<?php

namespace Tests\Feature;

use App\Jobs\GenerateAudioJob;
use App\Models\AudioGeneration;
use App\Models\ClonedVoice;
use App\Models\User;
use App\Services\Analytics\BusinessStatsService;
use App\Services\TranslationService;
use App\Services\TtsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The dashboard's API cost: exact from Google's token counts once they are
 * recorded, and Google's dated list prices for the voices made before.
 */
class ApiCostExactTest extends TestCase
{
    use RefreshDatabase;

    private function clonedGeneration(): AudioGeneration
    {
        Storage::fake('public');
        config(['services.gemini_tts.api_key' => 'test-key', 'services.gemini_tts.voice_retry_pause' => false]);
        // The owner's account can use cloned voices on any plan (as in ClonedVoiceDialectTest).
        $user = User::factory()->create(['email' => 'solicode.co.uk@gmail.com']);
        $voice = ClonedVoice::factory()->ready()->forUser($user)->create(['provider_voice_key' => 'voice_test', 'language_code' => 'ar-XA']);

        return AudioGeneration::create([
            'user_id' => $user->id, 'input_text' => 'إزيك', 'source_language' => 'ar', 'target_language' => 'ar',
            'voice_tier' => 'hd', 'voice_name' => 'cloned_'.$voice->id, 'dialect' => 'ar-SA',
            'performance_style' => 'neutral', 'emotion' => 'neutral', 'translate' => false, 'status' => 'processing',
            'duration_seconds' => 5,
        ]);
    }

    private function fakeGoogle(bool $speechFails = false): void
    {
        Http::fake(function ($request) use ($speechFails) {
            if (str_contains($request->url(), ':generateContent') && ! str_contains($request->url(), '-tts')) {
                // The dialect rewrite (a text model): 120 tokens in, 30 out and 50 of thinking.
                return Http::response([
                    'candidates' => [['content' => ['parts' => [['text' => 'وش لونك']]]]],
                    'usageMetadata' => ['promptTokenCount' => 120, 'candidatesTokenCount' => 30, 'thoughtsTokenCount' => 50],
                ]);
            }
            if (str_contains($request->url(), '/interactions')) {
                return $speechFails
                    ? Http::response(['error' => ['message' => 'unavailable']], 503)
                    : Http::response([
                        'output_audio' => ['data' => base64_encode('RIFF....WAVE')],
                        'usage' => ['total_input_tokens' => 20, 'total_output_tokens' => 400, 'total_tokens' => 420],
                    ]);
            }

            return Http::response(['error' => ['message' => 'not found']], 404);
        });
    }

    public function test_a_voice_keeps_the_exact_cost_of_every_request_it_made(): void
    {
        Carbon::setTestNow('2026-11-01 12:00:00');
        $generation = $this->clonedGeneration();
        $this->fakeGoogle();

        (new GenerateAudioJob($generation->id))->handle(app(TranslationService::class), app(TtsService::class));

        $generation->refresh();
        $this->assertSame('done', $generation->status, (string) $generation->error_message);
        // The rewrite on the first text model, then the voice: 20 text tokens in, 400 audio tokens out.
        $rewrite = (120 * 0.50 + 80 * 3.00) / 1e6;
        $voice = (20 * 0.50 + 400 * 9.00) / 1e6;
        $this->assertSame(round($rewrite + $voice, 6), $generation->tts_cost_estimate_usd);
        $this->assertSame(2, $generation->api_calls);
        $this->assertSame(140, $generation->api_input_tokens);
        $this->assertSame(480, $generation->api_output_tokens);

        // The dashboard shows it as it was recorded, marked exact.
        $model = collect(app(BusinessStatsService::class)->build(12)['api_usage']['by_model_30d'])->firstWhere('model', 'Gemini · gemini-3.8-flash-tts');
        $this->assertSame('exact', $model['cost_basis']);
    }

    public function test_a_failed_voice_keeps_what_its_billed_requests_cost(): void
    {
        Carbon::setTestNow('2026-11-01 12:00:00');
        $generation = $this->clonedGeneration();
        $this->fakeGoogle(speechFails: true);

        try {
            (new GenerateAudioJob($generation->id))->handle(app(TranslationService::class), app(TtsService::class));
        } catch (\Throwable) {
            // The member is told it failed and given their credits back.
        }

        $generation->refresh();
        $this->assertSame('failed', $generation->status);
        // Only the rewrite was billed: the speech requests were refused.
        $this->assertSame(round((120 * 0.50 + 80 * 3.00) / 1e6, 6), $generation->tts_cost_estimate_usd);
        $this->assertSame(1, $generation->api_calls);
    }

    private ?User $member = null;

    private function row(array $values, string $when): void
    {
        $this->member ??= User::factory()->create();
        DB::table('audio_generations')->insert($values + [
            'user_id' => $this->member->id, 'input_text' => 'x', 'target_language' => 'ar', 'status' => 'done',
            'tts_provider' => 'gemini', 'tts_model' => 'gemini-3.8-flash-tts', 'tts_cost_estimate_usd' => 0,
            'created_at' => $when, 'updated_at' => $when,
        ]);
    }

    public function test_dashboard_cost_is_exact_where_recorded_and_list_price_before(): void
    {
        Carbon::setTestNow('2026-11-20 12:00:00');
        $day = '2026-11-10 10:00:00';
        // Before metering: 10 hours of 3.8 audio and 80,000 characters: 900,000 audio tokens
        // at $9 and 20,000 text tokens at $0.50 = $8.11.
        $this->row(['duration_seconds' => 36000, 'billable_chars' => 80000], $day);
        // A failed one only reserved its seconds: its text is priced, not audio it never made ($0.01).
        $this->row(['status' => 'failed', 'duration_seconds' => 36000, 'billable_chars' => 80000], $day);
        // An older model at its own price: 3,600 s at $10 and 20,000 text tokens = $0.91.
        $this->row(['tts_model' => 'gemini-2.5-flash-preview-tts', 'duration_seconds' => 3600, 'billable_chars' => 80000], $day);
        // Metered: exactly what was recorded, failed or not.
        $this->row(['duration_seconds' => 120, 'billable_chars' => 2000, 'tts_cost_estimate_usd' => 1.234567, 'api_calls' => 5, 'api_input_tokens' => 900, 'api_output_tokens' => 3400], $day);
        $this->row(['status' => 'failed', 'duration_seconds' => 120, 'billable_chars' => 2000, 'tts_cost_estimate_usd' => 0.5, 'api_calls' => 2, 'api_input_tokens' => 100, 'api_output_tokens' => 0], $day);

        $build = app(BusinessStatsService::class)->build(12);
        $t = $build['api_usage']['totals_30d'];
        $total = 8.11 + 0.01 + 0.91 + 1.234567 + 0.5;

        $this->assertSame(round($total, 2), $t['cost']);
        // Delivered audio only: 36,000 + 3,600 + 120 seconds.
        $this->assertSame(round(39720 / 60, 1), $t['audio_minutes']);
        $this->assertSame(round($total / (39720 / 60), 4), $t['cost_per_audio_minute']);
        $this->assertSame(round($total / (80000 * 3 + 4000) * 1000, 4), $t['cost_per_1k_chars']);
        $this->assertSame(round(1.734567 / $total, 4), $t['exact_share']);
        $this->assertSame(round((8.11 + 0.01 + 0.91) / $total, 4), $t['estimated_share']);
        // Five voices, seven requests Google answered for the two that were counted.
        $this->assertSame(5, $t['api_requests']);
        $this->assertSame(7, $t['api_calls']);

        $models = collect($build['api_usage']['by_model_30d'])->keyBy('model');
        $this->assertSame(round(8.11 + 0.01 + 1.234567 + 0.5, 2), $models['Gemini · gemini-3.8-flash-tts']['cost_usd']);
        $this->assertSame('estimated + exact', $models['Gemini · gemini-3.8-flash-tts']['cost_basis']);
        $this->assertSame(0.91, $models['Gemini · gemini-2.5-flash-preview-tts']['cost_usd']);

        // The gross margin card takes the same figure.
        $this->assertSame(round($total, 2), $build['unit_economics']['api_cost_30d']);
    }

    public function test_voices_from_2027_are_estimated_at_the_new_price(): void
    {
        Carbon::setTestNow('2027-01-20 12:00:00');
        // 900,000 audio tokens at $18 and 20,000 text tokens at $1.00 = $16.22.
        $this->row(['duration_seconds' => 36000, 'billable_chars' => 80000], '2027-01-05 10:00:00');

        $this->assertSame(16.22, app(BusinessStatsService::class)->build(12)['api_usage']['totals_30d']['cost']);
    }
}
