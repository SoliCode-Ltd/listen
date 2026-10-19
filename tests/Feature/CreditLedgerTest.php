<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TextItem;
use App\Models\UsageCounter;
use App\Models\User;
use App\Models\Voice;
use App\Services\TranslationService;
use App\Services\TtsService;
use App\Support\CreditPeriod;
use App\Support\Credits;
use App\Support\UsageEstimator;
use App\Support\VoiceTier;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * The member's credit counter always equals what they were given: after a
 * mix of voices made, voices that failed, a voice that got stuck and was
 * swept (twice), and a finished voice deleted, the counter holds exactly the
 * real length of the voices delivered — nothing for failures, nothing twice,
 * and deleting a voice never gives back what it cost.
 */
class CreditLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_counter_holds_exactly_what_was_delivered(): void
    {
        Storage::fake('public');
        $user = $this->member();

        $this->speech(seconds: [3, 7]);
        $this->generate($user)->assertOk();
        $this->generate($user)->assertOk();

        $this->speech(fails: true);
        $this->generate($user)->assertStatus(422);
        $this->generate($user)->assertStatus(422);

        $this->stuckGeneration($user, minutesAgo: 31);
        $this->artisan('listen:expire-stuck-generations')->assertSuccessful();
        $this->artisan('listen:expire-stuck-generations')->assertSuccessful();

        $first = AudioGeneration::query()->where('user_id', $user->id)->where('status', 'done')->orderBy('id')->first();
        $this->deleteGeneration($user, $first)->assertOk();

        $counter = CreditPeriod::counter((int) $user->id);
        $this->assertSame(Credits::forSeconds(3) + Credits::forSeconds(7), (int) $counter->total_chars, 'credits: the two voices delivered');
        $this->assertSame(10, (int) $counter->total_seconds, 'seconds: their real length');
        $this->assertSame(2, (int) $counter->total_requests, 'requests: failures and the stuck voice gave theirs back');
        $this->assertSame(['done', 'failed', 'failed', 'failed'], AudioGeneration::query()->where('user_id', $user->id)->orderBy('id')->pluck('status')->all());
    }

    private function generate(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Hello there, this is a short test.',
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->voice()->id),
        ]);
    }

    private function deleteGeneration(User $user, AudioGeneration $generation): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)
            ->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class, ValidateCsrfToken::class])
            ->deleteJson($this->localizedRoute('dashboard.jobs.destroy', ['job' => $generation->id]));
    }

    /**
     * A generation whose web request died after reserving its credits.
     */
    private function stuckGeneration(User $user, int $minutesAgo): void
    {
        $text = 'A voice that never finished.';
        $item = TextItem::query()->create(['user_id' => $user->id, 'source_type' => 'message', 'content' => $text, 'language' => 'en', 'status' => 'processing']);
        $generation = AudioGeneration::query()->create([
            'user_id' => $user->id,
            'text_item_id' => $item->id,
            'user_sequence_number' => (int) AudioGeneration::query()->where('user_id', $user->id)->max('user_sequence_number') + 1,
            'input_text' => $text,
            'source_language' => 'en',
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_name' => 'en-US-ledger',
            'status' => 'processing',
        ]);
        $estimate = UsageEstimator::estimate($text, VoiceTier::STANDARD);
        $counter = CreditPeriod::counter((int) $user->id);
        $counter->increment('total_requests');
        $counter->increment('total_chars', $estimate['credits']);
        $counter->addSeconds($estimate['seconds']);
        AudioGeneration::query()->whereKey($generation->id)->update(['updated_at' => now()->subMinutes($minutesAgo)]);
    }

    /**
     * @param  array<int, int>  $seconds  the length of each voice made, in turn
     */
    private function speech(array $seconds = [], bool $fails = false): void
    {
        $translation = $this->createMock(TranslationService::class);
        $translation->method('maybeTranslate')->willReturnArgument(0);
        $this->app->instance(TranslationService::class, $translation);

        $tts = $this->createMock(TtsService::class);
        if ($fails) {
            $tts->method('synthesizeAdvanced')->willThrowException(new \RuntimeException('Speech service unavailable.'));
        } else {
            $results = [];
            foreach ($seconds as $index => $length) {
                $path = "audio/ledger-{$index}.mp3";
                Storage::disk('public')->put($path, 'not readable as audio, so the engine\'s length counts');
                $results[] = [
                    'audio_path' => $path, 'duration_seconds' => $length, 'provider' => 'google_cloud', 'model' => 'standard',
                    'latency_ms' => 10, 'fallback_used' => false, 'cost_estimate_usd' => 0.0, 'routing_mode' => 'hybrid', 'plan_slug' => 'test',
                ];
            }
            $tts->method('synthesizeAdvanced')->willReturnOnConsecutiveCalls(...$results);
        }
        $this->app->instance(TtsService::class, $tts);
    }

    private function member(): User
    {
        $plan = Plan::factory()->create(['voice_tier' => 'standard', 'price_cents' => 1999, 'is_active' => true,
            'monthly_chars' => 100000, 'max_chars_per_request' => 5000, 'monthly_requests' => 0]);
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }

    private function voice(): Voice
    {
        return Voice::query()->firstOrCreate(['internal_voice_id' => 'en-US-ledger'], [
            'language' => 'en', 'gender' => 'female', 'tier' => 'standard', 'display_name' => 'Ledger',
            'provider' => 'google_cloud', 'provider_voice_id' => 'en-US-ledger', 'is_active' => true,
        ]);
    }
}
