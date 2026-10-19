<?php

namespace Tests\Feature;

use App\Jobs\ProcessVoiceCloningJob;
use App\Models\ClonedVoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\VoiceCloningAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Every cloning attempt has the provider build a voice from the recordings,
 * so a new voice, a retry and a new consent sentence share one limit of five
 * an hour: a failing clone retried in a loop can't use up the shared quota.
 */
class VoiceCloningAttemptLimitTest extends TestCase
{
    use RefreshDatabase;

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
        Bus::fake([ProcessVoiceCloningJob::class]);
    }

    public function test_retrying_a_failed_voice_counts_toward_the_hourly_limit(): void
    {
        $voice = ClonedVoice::factory()->failed()->create([
            'user_id' => $this->member->id,
            'source_audio_path' => 'voice-cloning/1/sample.wav',
            'consent_audio_path' => 'voice-cloning/1/consent.wav',
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            ClonedVoice::query()->whereKey($voice->id)->update(['status' => 'failed']);
            $response = $this->retry($voice);
            $this->assertSame(200, $response->status(), 'attempt '.$attempt.': '.$response->getContent());
        }

        ClonedVoice::query()->whereKey($voice->id)->update(['status' => 'failed']);
        $this->retry($voice)->assertStatus(429)->assertJsonFragment(['error' => __('ui.voice_cloning.rate_limited')]);
        Bus::assertDispatchedTimes(ProcessVoiceCloningJob::class, 5);

        // A brand-new voice shares the same limit.
        $this->actingAs($this->member)->postJsonLocalizedRoute('voice-cloning.store', [
            'name' => 'Another Voice', 'language_code' => 'ar-XA', 'source_type' => 'upload',
            'audio_file' => UploadedFile::fake()->create('sample.wav', 2048, 'audio/wav'),
            'consent_audio' => UploadedFile::fake()->create('consent.wav', 800, 'audio/wav'),
            'consent_confirmed' => '1', 'consent_duration' => 8, 'audio_duration' => 12,
        ])->assertStatus(429);

        // An hour later the member can try again.
        $this->travel(61)->minutes();
        ClonedVoice::query()->whereKey($voice->id)->update(['status' => 'failed']);
        $this->retry($voice)->assertOk();
    }

    public function test_a_new_consent_sentence_counts_toward_the_hourly_limit(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->countedAttempt();
        }
        $voice = ClonedVoice::factory()->failed()->create([
            'user_id' => $this->member->id,
            'source_audio_path' => 'voice-cloning/1/sample.wav',
            'consent_audio_path' => 'voice-cloning/1/consent.wav',
            'metadata' => ['failure_code' => 'consent'],
        ]);

        $this->actingAs($this->member)->postJsonLocalizedRoute('voice-cloning.consent', [
            'consent_audio' => UploadedFile::fake()->create('consent.wav', 800, 'audio/wav'),
            'consent_confirmed' => '1', 'consent_duration' => 8,
        ], ['id' => $voice->id])->assertStatus(429);
        Bus::assertNotDispatched(ProcessVoiceCloningJob::class);
    }

    private function retry(ClonedVoice $voice): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->member)->postJsonLocalizedRoute('voice-cloning.retry', [], ['id' => $voice->id]);
    }

    private function countedAttempt(): void
    {
        \Illuminate\Support\Facades\RateLimiter::hit("voice_cloning:{$this->member->id}", 3600);
    }
}
