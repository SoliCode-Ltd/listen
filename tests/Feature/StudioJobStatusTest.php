<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voice;
use App\Services\TranslationService;
use App\Services\TtsService;
use App\Support\GenerationMessage;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The studio checks on a voice still being made (a long text is made after
 * the reply, or the page opened while one was): a member's own generation, as
 * the history shows it, until it is done or failed; then it plays as a voice
 * made in the request does. Speech is faked.
 */
class StudioJobStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_a_voice_is_reported_as_the_history_shows_it(): void
    {
        $this->freezeTime();
        Storage::fake('public');
        Storage::disk('public')->put('audio/status.mp3', 'audio');
        $user = $this->member();
        $this->fakeSpeech();

        $queued = $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Hello there',
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->voice()->id),
        ])->assertOk();

        $shown = $this->jobStatus($user, (int) $queued->json('job.id'))->assertOk()->assertJsonPath('ok', true);

        $this->assertSame($queued->json('job'), $shown->json('job'), 'the same payload the Generate reply carries');
        $this->assertSame($queued->json('stats'), $shown->json('stats'), 'with the counts once it has finished');
        $this->assertSame($queued->json('usage.remaining_chars'), $shown->json('usage.remaining_chars'), 'and the usage');
        $this->assertStringContainsString('no-store', (string) $shown->headers->get('Cache-Control'));
    }

    public function test_a_voice_being_made_is_reported_without_the_usage_until_it_finishes(): void
    {
        $user = $this->member();
        $generation = $this->generation($user, ['status' => 'processing']);

        $this->jobStatus($user, $generation->id)
            ->assertOk()
            ->assertJsonPath('job.status', 'processing')
            ->assertJsonPath('job.row_key', 'audio:'.$generation->id)
            ->assertJsonPath('job.audio_url', null)
            ->assertJsonMissingPath('usage')
            ->assertJsonMissingPath('stats');

        $generation->update(['status' => 'failed', 'error_message' => GenerationMessage::stored('ui.backend.premium_voices_busy')]);
        app()->setLocale('ar');

        $this->jobStatus($user, $generation->id)
            ->assertOk()
            ->assertJsonPath('job.status', 'failed')
            ->assertJsonPath('job.error_message', trans('ui.backend.premium_voices_busy', [], 'ar'))
            ->assertJsonPath('stats.failed', 1)
            ->assertJsonStructure(['usage' => ['remaining_chars']]);
    }

    public function test_only_the_members_own_voices_are_reported(): void
    {
        $owner = $this->member();
        $generation = $this->generation($owner, ['status' => 'processing']);

        $this->jobStatus($this->member(), $generation->id)->assertNotFound();
        $this->jobStatus($owner, 999999)->assertNotFound();
    }

    public function test_a_guest_is_refused(): void
    {
        $generation = $this->generation($this->member(), ['status' => 'processing']);

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ])->getJson($this->localizedRoute('dashboard.jobs.show', ['job' => $generation->id]))->assertUnauthorized();
    }

    public function test_checking_skips_the_billing_sync_the_pages_run(): void
    {
        $middleware = Route::getRoutes()->getByName('dashboard.jobs.show')?->gatherMiddleware() ?? [];

        $this->assertContains('auth', $middleware);
        $this->assertNotContains('subscription.sync', $middleware, 'checked every few seconds: no billing sync each time');
    }

    public function test_the_studio_checks_on_voices_being_made_and_plays_them_when_ready(): void
    {
        $user = $this->member();
        $this->generation($user, ['status' => 'processing']);

        $html = $this->actingAs($user)->getLocalizedRoute('dashboard')->assertOk()->getContent();

        $this->assertStringContainsString("const jobStatusUrlTemplate = '".route('dashboard.jobs.show', ['job' => '__JOB_ID__'])."';", $html);
        // A new reply that is still being made, and voices being made when the page opened.
        $this->assertStringContainsString('} else if (data?.job && isJobRunning(data.job)) {', $html);
        $this->assertStringContainsString('allJobs.filter((job) => !isDemoHistoryJob(job) && isJobRunning(job)).forEach((job) => watchJob(job.id));', $html);
        // From every 2 seconds, slowing to every 6, for 20 minutes, never while the tab is hidden.
        $this->assertStringContainsString('const JOB_CHECK_FIRST_MS = 2000;', $html);
        $this->assertStringContainsString('const JOB_CHECK_SLOWEST_MS = 6000;', $html);
        $this->assertStringContainsString('const JOB_CHECK_FOR_MS = 20 * 60 * 1000;', $html);
        $this->assertStringContainsString("document.addEventListener('visibilitychange'", $html);
        $this->assertStringContainsString('if (document.hidden) {', $html);
        // Done: the row, the usage and the docked player; failed: the error in the page's language.
        $this->assertStringContainsString('upsertJobRow(job);', $html);
        $this->assertStringContainsString('if (data?.usage) updateUsagePanel(data.usage);', $html);
        $this->assertStringContainsString('showGeneratedPreview(job);', $html);
        $this->assertStringContainsString("showFormMessage('error', job.error_message || i18n.voice_generation_failed);", $html);
    }

    private function jobStatus(User $user, int $id): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ])->getJson($this->localizedRoute('dashboard.jobs.show', ['job' => $id]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function generation(User $user, array $attributes): AudioGeneration
    {
        return AudioGeneration::query()->create(array_merge([
            'user_id' => $user->id,
            'user_sequence_number' => (int) AudioGeneration::query()->where('user_id', $user->id)->max('user_sequence_number') + 1,
            'input_text' => 'A long article that is still being read.',
            'source_language' => 'en',
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_name' => 'en-US-Wavenet-C',
        ], $attributes));
    }

    private function member(): User
    {
        $plan = Plan::factory()->create(['voice_tier' => 'standard', 'is_active' => true, 'monthly_chars' => 100000, 'max_chars_per_request' => 5000, 'monthly_requests' => 0]);
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }

    private function voice(): Voice
    {
        return Voice::query()->firstOrCreate(['internal_voice_id' => 'en-US-Wavenet-C'], [
            'language' => 'en', 'gender' => 'female', 'tier' => 'standard', 'display_name' => 'Emma',
            'provider' => 'google_cloud', 'provider_voice_id' => 'en-US-Wavenet-C', 'is_active' => true,
        ]);
    }

    private function fakeSpeech(): void
    {
        $translation = $this->createMock(TranslationService::class);
        $translation->method('maybeTranslate')->willReturnArgument(0);
        $this->app->instance(TranslationService::class, $translation);

        $tts = $this->createMock(TtsService::class);
        $tts->method('synthesizeAdvanced')->willReturn([
            'audio_path' => 'audio/status.mp3', 'duration_seconds' => 3, 'provider' => 'google_cloud', 'model' => 'standard',
            'latency_ms' => 10, 'fallback_used' => false, 'cost_estimate_usd' => 0.0, 'routing_mode' => 'hybrid', 'plan_slug' => 'test',
        ]);
        $this->app->instance(TtsService::class, $tts);
    }
}
