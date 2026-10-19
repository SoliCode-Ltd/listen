<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Models\Voice;
use App\Services\TranslationService;
use App\Services\TtsService;
use App\Support\CreditPeriod;
use App\Support\GenerationMessage;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A long text (listen.audio.force_async_chars, 2,500 characters) is never made
 * inside the web request, where the web server's time limit can cut it short:
 * the studio and the extension are answered at once with the voice being made,
 * and it is made right after the reply in the same process, with no queue
 * worker. Shorter texts are made in the request as before. Speech is faked.
 */
class LongTextAfterReplyTest extends TestCase
{
    use RefreshDatabase;

    private const SHORT_TEXT = 'Hello there';

    /** Set when the reply has been made: speech before it would mean the request waited for the voice. */
    private bool $replied = false;

    private ?bool $spokenAfterReply = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::disk('public')->put('audio/long.mp3', 'audio');
        // As on the servers: a queued job would wait for a worker that may not be running.
        config(['queue.default' => 'database']);
        Event::listen(RequestHandled::class, function (): void {
            $this->replied = true;
        });
    }

    public function test_a_long_studio_text_is_answered_at_once_and_made_after_the_reply(): void
    {
        $user = $this->member();
        $this->fakeSpeech();

        $response = $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', $this->studioPayload($this->longText()));

        $response->assertStatus(202)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', __('ui.backend.dashboard_voice_being_made'))
            ->assertJsonPath('job.status', 'processing')
            ->assertJsonPath('job.audio_url', null)
            ->assertJsonPath('stats.processing', 1)
            ->assertJsonStructure(['job' => ['id', 'row_key', 'display_number', 'text_excerpt'], 'usage' => ['remaining_chars']]);
        $this->assertGreaterThan(0, (int) $response->json('usage.total_chars'), 'the credits are reserved before the reply');

        $this->assertTrue($this->spokenAfterReply, 'the voice is made after the reply, not while the member waits');
        $generation = AudioGeneration::query()->findOrFail((int) $response->json('job.id'));
        $this->assertSame('done', $generation->status);
        $this->assertSame('audio/long.mp3', $generation->audio_path);
        // Charged for the audio it made (40 seconds, 15 credits a second), not the reservation.
        $this->assertSame(600, (int) CreditPeriod::counter((int) $user->id)->total_chars);
        $this->assertSame(1, (int) CreditPeriod::counter((int) $user->id)->total_requests);
    }

    public function test_a_long_text_that_fails_after_the_reply_gives_its_credits_back_once(): void
    {
        $user = $this->member();
        // Other usage this month, so a second refund would show.
        $counter = UsageCounter::query()->create(['user_id' => $user->id, 'period_ym' => now()->format('Y-m'), 'total_chars' => 900, 'total_requests' => 4, 'total_seconds' => 60, 'total_minutes' => 1]);
        $this->fakeSpeech(fails: true);

        $response = $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', $this->studioPayload($this->longText()))
            ->assertStatus(202)
            ->assertJsonPath('job.status', 'processing');

        $generation = AudioGeneration::query()->findOrFail((int) $response->json('job.id'));
        $this->assertSame('failed', $generation->status);
        $this->assertSame(GenerationMessage::stored('ui.backend.dashboard_voice_generation_failed_try_again'), $generation->error_message);
        $counter->refresh();
        $this->assertSame(900, (int) $counter->total_chars, 'everything reserved given back, once');
        $this->assertSame(4, (int) $counter->total_requests);
        $this->assertSame(60, (int) $counter->total_seconds);
    }

    public function test_a_short_studio_text_is_still_made_in_the_request_with_its_audio_in_the_reply(): void
    {
        $user = $this->member();
        $this->fakeSpeech();

        $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', $this->studioPayload(self::SHORT_TEXT))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('job.status', 'done')
            ->assertJsonPath('message', __('ui.backend.dashboard_voice_generated'));

        $this->assertFalse($this->spokenAfterReply, 'made while the request waited');
    }

    public function test_without_the_page_the_member_is_sent_back_to_the_studio_with_the_voice_being_made(): void
    {
        $user = $this->member();
        $this->fakeSpeech();

        $this->actingAs($user)->postLocalizedRoute('dashboard.queue', $this->studioPayload($this->longText()))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('ok', __('ui.backend.dashboard_voice_being_made'));

        $this->assertSame('done', AudioGeneration::query()->latest('id')->value('status'));
    }

    public function test_the_work_after_the_reply_outlives_a_closed_tab_and_a_low_time_limit(): void
    {
        $user = $this->member();
        $this->fakeSpeech();
        $wasIgnoringAbort = ignore_user_abort(false);
        ini_set('max_execution_time', '30');

        try {
            $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', $this->studioPayload($this->longText()))->assertStatus(202);

            $this->assertSame(1, ignore_user_abort(), 'a closed tab does not stop it');
            $this->assertSame('1800', ini_get('max_execution_time'), 'it has the time the stuck sweep allows');
        } finally {
            set_time_limit(0);
            ignore_user_abort((bool) $wasIgnoringAbort);
        }
    }

    public function test_the_extension_never_waits_on_a_long_text_even_when_it_asks_to(): void
    {
        $user = $this->member();
        $key = $this->extensionKey($user);
        $this->fakeSpeech();

        // Older extensions ask to wait for every text.
        $response = $this->withHeaders(['X-Listen-Key' => $key])->postJson('/api/reader/queue', [
            'content' => $this->longText(),
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => $this->voiceKey(),
            'prefer_sync' => true,
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('audio_url', null);
        $this->assertGreaterThan(0, (int) $response->json('job_id'));

        $this->assertTrue($this->spokenAfterReply);
        $this->assertSame('done', AudioGeneration::query()->whereKey((int) $response->json('job_id'))->value('status'));
        $this->assertSame(0, DB::table('jobs')->count(), 'nothing waits for a queue worker');
        $this->withHeaders(['X-Listen-Key' => $key])->getJson('/api/reader/jobs')
            ->assertOk()
            ->assertJsonPath('jobs.0.status', 'done')
            ->assertJsonPath('jobs.0.id', (int) $response->json('job_id'));
    }

    public function test_a_long_extension_text_needs_no_queue_worker(): void
    {
        $user = $this->member();
        $key = $this->extensionKey($user);
        $this->fakeSpeech();

        $response = $this->withHeaders(['X-Listen-Key' => $key])->postJson('/api/reader/queue', [
            'content' => $this->longText(),
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => $this->voiceKey(),
        ])->assertStatus(202)->assertJsonPath('status', 'processing');

        $this->assertSame('done', AudioGeneration::query()->whereKey((int) $response->json('job_id'))->value('status'));
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_short_extension_text_is_still_made_in_the_request(): void
    {
        $user = $this->member();
        $key = $this->extensionKey($user);
        $this->fakeSpeech();

        $this->withHeaders(['X-Listen-Key' => $key])->postJson('/api/reader/queue', [
            'content' => self::SHORT_TEXT,
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => $this->voiceKey(),
            'prefer_sync' => true,
        ])->assertOk()->assertJsonPath('status', 'done');

        $this->assertFalse($this->spokenAfterReply);
    }

    public function test_with_the_threshold_off_a_long_text_is_made_in_the_request(): void
    {
        config(['listen.audio.force_async_chars' => 0]);
        $user = $this->member();
        $this->fakeSpeech();

        $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', $this->studioPayload($this->longText()))
            ->assertOk()
            ->assertJsonPath('job.status', 'done');

        $this->assertFalse($this->spokenAfterReply);
    }

    /** About 2,600 characters: past the 2,500 from which a text is made after the reply. */
    private function longText(): string
    {
        return trim(str_repeat('The quick brown fox jumps over the lazy dog. ', 58));
    }

    /**
     * @return array<string, mixed>
     */
    private function studioPayload(string $text): array
    {
        return [
            'content' => $text,
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => $this->voiceKey(),
        ];
    }

    private function member(): User
    {
        $plan = Plan::factory()->create([
            'voice_tier' => 'standard', 'price_cents' => 1999, 'is_active' => true,
            'monthly_chars' => 100000, 'max_chars_per_request' => 5000, 'monthly_requests' => 0,
        ]);
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }

    private function extensionKey(User $user): string
    {
        $key = 'lk_live_'.str_repeat('l', 32);
        $user->forceFill(['api_key_hash' => User::hashApiKey($key)])->save();

        return $key;
    }

    private function voiceKey(): string
    {
        $voice = Voice::query()->firstOrCreate(['internal_voice_id' => 'en-US-Wavenet-C'], [
            'language' => 'en', 'gender' => 'female', 'tier' => 'standard', 'display_name' => 'Emma',
            'provider' => 'google_cloud', 'provider_voice_id' => 'en-US-Wavenet-C', 'is_active' => true,
        ]);

        return VoiceCatalog::keyFromId((int) $voice->id);
    }

    private function fakeSpeech(bool $fails = false): void
    {
        $translation = $this->createMock(TranslationService::class);
        $translation->method('maybeTranslate')->willReturnArgument(0);
        $this->app->instance(TranslationService::class, $translation);

        $tts = $this->createMock(TtsService::class);
        $tts->method('synthesizeAdvanced')->willReturnCallback(function () use ($fails): array {
            $this->spokenAfterReply = $this->replied;
            if ($fails) {
                throw new \RuntimeException('Speech service unavailable.');
            }

            return [
                'audio_path' => 'audio/long.mp3', 'duration_seconds' => 40, 'provider' => 'google_cloud', 'model' => 'standard',
                'latency_ms' => 10, 'fallback_used' => false, 'cost_estimate_usd' => 0.0, 'routing_mode' => 'hybrid', 'plan_slug' => 'test',
            ];
        });
        $this->app->instance(TtsService::class, $tts);
    }
}
