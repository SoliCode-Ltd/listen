<?php

namespace Tests\Feature;

use App\Models\ClonedVoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\VoiceCloningService;
use App\Support\VoiceCloningAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A cloned voice that can't be made because of our side (the provider's
 * spending cap or rate limit, a refused key, the server's country) says so
 * with no wait promised, is not labelled "Needs another take", and does not
 * use up one of the member's five attempts an hour. A problem with the
 * recordings still asks for another take and counts. The provider is faked.
 */
class VoiceCloningOurSideFailureTest extends TestCase
{
    use RefreshDatabase;

    private const SPENDING_CAP = ['error' => ['code' => 429, 'message' => 'Your project has exceeded its monthly spending cap.', 'status' => 'RESOURCE_EXHAUSTED']];

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::factory()->free()->create(['slug' => 'free']);
        $plan = Plan::factory()->paid()->create([
            'slug' => 'creator', 'price_cents' => 1999, 'voice_tier' => 'hd',
            'voice_cloning_enabled' => true, 'voice_cloning_max_voices' => 5,
        ]);
        $this->member = User::factory()->create(['email' => VoiceCloningAccess::PREVIEW_EMAIL]);
        Subscription::create(['user_id' => $this->member->id, 'plan_id' => $plan->id, 'status' => 'active']);

        Storage::fake('local');
        Storage::fake('public');
        config(['services.gemini_tts.api_key' => 'test-key', 'services.gemini_tts.voice_retry_pause' => false]);
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_a_spending_cap_says_so_with_a_neutral_label_and_uses_no_attempt(): void
    {
        $this->providerReplies(self::SPENDING_CAP, 429);

        $this->createVoice()->assertOk();

        $voice = ClonedVoice::query()->firstOrFail();
        $this->assertSame('failed', $voice->status);
        $this->assertSame('quota', data_get($voice->metadata, 'failure_code'));
        $this->assertSame(0, RateLimiter::attempts(VoiceCloningService::attemptsKey((int) $this->member->id)), 'the attempt was given back');

        $status = $this->actingAs($this->member)
            ->getJson($this->localizedRoute('voice-cloning.status', ['id' => $voice->id]))
            ->assertOk()
            ->assertJson([
                'status' => 'failed',
                'status_label' => __('ui.voice_cloning.status_failed_ours'),
                'failure_reason' => __('ui.voice_cloning.failure_quota'),
                'can_retry' => true,
            ]);
        $this->assertStringNotContainsStringIgnoringCase('minute', (string) $status->json('failure_reason'));
        $this->assertNotSame(__('ui.voice_cloning.status_failed'), $status->json('status_label'));

        app()->setLocale('ar');
        $card = $this->voiceCard($voice);
        $this->assertStringContainsString(e(trans('ui.voice_cloning.status_failed_ours', [], 'ar')), $card);
        $this->assertStringContainsString(e(trans('ui.voice_cloning.failure_quota', [], 'ar')), $card);
        $this->assertStringNotContainsString(e(trans('ui.voice_cloning.status_failed', [], 'ar')), $card);
        $this->assertStringNotContainsString('دقيقة', $card);
        $this->assertStringNotContainsString('دقائق', $card);
    }

    public function test_failures_on_our_side_never_use_up_the_hourly_limit(): void
    {
        $this->providerReplies(self::SPENDING_CAP, 429);
        $this->createVoice()->assertOk();
        $voice = ClonedVoice::query()->firstOrFail();

        // More tries than the five an hour, each failing on our side.
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->actingAs($this->member)
                ->postJsonLocalizedRoute('voice-cloning.retry', [], ['id' => $voice->id])
                ->assertOk();
            $this->assertSame('failed', $voice->fresh()->status, 'attempt '.$attempt);
        }

        $this->assertSame(0, RateLimiter::attempts(VoiceCloningService::attemptsKey((int) $this->member->id)));
    }

    public function test_an_attempt_that_stalled_is_given_back(): void
    {
        RateLimiter::hit(VoiceCloningService::attemptsKey((int) $this->member->id), 3600);
        $voice = ClonedVoice::factory()->processing()->create(['user_id' => $this->member->id]);
        $this->travel(VoiceCloningService::STALLED_AFTER_SECONDS + 60)->seconds();

        $this->assertTrue(VoiceCloningService::failIfStalled($voice->fresh()));

        $this->assertSame(0, RateLimiter::attempts(VoiceCloningService::attemptsKey((int) $this->member->id)));
        $this->assertSame(__('ui.voice_cloning.status_failed_ours'), VoiceCloningService::statusLabel($voice->fresh()));
    }

    public function test_a_problem_with_the_recordings_still_asks_for_another_take_and_counts(): void
    {
        $this->providerReplies(['error' => ['code' => 400, 'message' => 'The audio could not be processed.', 'status' => 'INVALID_ARGUMENT']], 400);

        $this->createVoice()->assertOk();

        $voice = ClonedVoice::query()->firstOrFail();
        $this->assertSame('audio', data_get($voice->metadata, 'failure_code'));
        $this->assertSame(__('ui.voice_cloning.status_failed'), VoiceCloningService::statusLabel($voice));
        $this->assertSame(1, RateLimiter::attempts(VoiceCloningService::attemptsKey((int) $this->member->id)), 'the member\'s own take counts');
    }

    public function test_each_failure_on_our_side_is_told_in_member_words_in_both_languages(): void
    {
        foreach (['quota', 'key', 'region', 'network', 'server'] as $code) {
            $voice = ClonedVoice::factory()->failed()->create(['user_id' => $this->member->id, 'metadata' => ['failure_code' => $code]]);

            foreach (['en', 'ar'] as $locale) {
                app()->setLocale($locale);
                $this->assertSame(__('ui.voice_cloning.status_failed_ours'), VoiceCloningService::statusLabel($voice), "{$code} {$locale}");

                $message = VoiceCloningService::publicFailureMessage($voice);
                foreach (['minute', 'Update the voice connection', 'Check the voice service', 'دقيقة', 'دقائق', 'حدّث الاتصال', 'تحقّق من خدمة الصوت'] as $promise) {
                    $this->assertStringNotContainsString($promise, $message, "{$code} {$locale}");
                }
            }
        }

        foreach (['consent', 'consent_phrase', 'duration', 'audio'] as $code) {
            $voice = ClonedVoice::factory()->failed()->create(['user_id' => $this->member->id, 'metadata' => ['failure_code' => $code]]);
            $this->assertSame(__('ui.voice_cloning.status_failed'), VoiceCloningService::statusLabel($voice), $code);
        }
    }

    /** @param array<string, mixed> $body */
    private function providerReplies(array $body, int $status): void
    {
        Http::fake(fn ($request) => $request->method() === 'POST'
            ? Http::response($body, $status)
            : Http::response(['voices' => []]));
    }

    private function createVoice(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->member)->postJsonLocalizedRoute('voice-cloning.store', [
            'name' => 'My studio voice', 'language_code' => 'ar-XA', 'source_type' => 'upload',
            'audio_file' => UploadedFile::fake()->create('sample.wav', 20, 'audio/wav'),
            'consent_audio' => UploadedFile::fake()->create('consent.wav', 10, 'audio/wav'),
            'consent_confirmed' => '1', 'consent_duration' => 8, 'audio_duration' => 15,
        ]);
    }

    private function voiceCard(ClonedVoice $voice): string
    {
        $html = $this->actingAs($this->member)->getLocalizedRoute('dashboard')->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<article class="vc-card" data-voice-id="'.$voice->id.'"[\s\S]*?<\/article>/', $html, $matches));

        return $matches[0];
    }
}
