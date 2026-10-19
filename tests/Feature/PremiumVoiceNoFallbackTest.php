<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voice;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\TtsRoutingService;
use App\Support\CreditPeriod;
use App\Support\GenerationMessage;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Paid members never get a standard voice: an HD generation the premium
 * engine cannot make fails, every credit comes back, and the member is told
 * the premium voices are busy, in their language. Speech is faked.
 */
class PremiumVoiceNoFallbackTest extends TestCase
{
    use RefreshDatabase;

    private int $googleCalls = 0;

    private int $geminiCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'services.google_tts.api_key' => 'test-google-key',
            'services.google_tts.endpoint' => 'https://texttospeech.googleapis.com/v1/text:synthesize',
            'services.gemini_tts.retry_pause_ms' => 0,
        ]);
        SiteSetting::set('tts_mode', TtsRoutingService::MODE_HYBRID, 'tts');
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'texttospeech')) {
                $this->googleCalls++;

                return Http::response(['audioContent' => base64_encode('google-audio')]);
            }
            $this->geminiCalls++;

            return Http::response(['error' => ['message' => 'The model is overloaded.']], 503);
        });
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_a_paid_hd_generation_gemini_cannot_make_fails_and_gives_every_credit_back(): void
    {
        $this->geminiKeys(1);
        $user = $this->paidMember();
        // A plan forced to Google before: it no longer sends paid HD voices to a standard voice.
        SiteSetting::set('tts_plan_provider_studio-hd', 'google_cloud', 'tts');

        $this->generate($user)
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', trans('ui.backend.premium_voices_busy', [], 'en'))
            ->assertJsonPath('job.status', 'failed');

        $generation = AudioGeneration::query()->latest('id')->firstOrFail();
        $this->assertSame(GenerationMessage::stored('ui.backend.premium_voices_busy'), $generation->error_message);
        $this->assertFalse((bool) $generation->tts_fallback_used);
        $this->assertNull($generation->audio_path);
        $this->assertSame(3, $this->geminiCalls, 'the first try and its retries');
        $this->assertSame(0, $this->googleCalls, 'never read by a standard voice');
        $this->assertNothingCharged($user);
    }

    public function test_the_member_is_told_in_arabic(): void
    {
        $this->geminiKeys(1);
        $user = $this->paidMember();
        app()->setLocale('ar');

        $this->generate($user)
            ->assertStatus(422)
            ->assertJsonPath('message', trans('ui.backend.premium_voices_busy', [], 'ar'))
            ->assertJsonPath('job.error_message', trans('ui.backend.premium_voices_busy', [], 'ar'));

        $this->assertNothingCharged($user);
    }

    public function test_without_a_premium_engine_set_up_hd_generations_fail_and_are_refunded(): void
    {
        $this->geminiKeys(0);
        $user = $this->paidMember();

        // Not set up is not a busy minute: the member is not told to wait one.
        $this->generate($user)
            ->assertStatus(422)
            ->assertJsonPath('message', trans('ui.backend.premium_voices_our_limit', [], 'en'));

        $this->assertSame(0, $this->geminiCalls);
        $this->assertSame(0, $this->googleCalls);
        $this->assertNothingCharged($user);
    }

    public function test_the_extension_hd_generation_fails_the_same_way(): void
    {
        $this->geminiKeys(1);
        $user = $this->paidMember();
        $key = 'lk_live_'.str_repeat('p', 32);
        $user->forceFill(['api_key_hash' => User::hashApiKey($key)])->save();

        $this->withHeaders(['X-Listen-Key' => $key, 'X-Listen-Locale' => 'ar'])->postJson('/api/reader/queue', [
            'content' => 'Hello there',
            'target_language' => 'en',
            'voice_tier' => 'hd',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->premiumVoice()->id),
            'prefer_sync' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error_message', trans('ui.backend.premium_voices_busy', [], 'ar'));

        $this->assertSame(0, $this->googleCalls);
        $this->assertNothingCharged($user);
    }

    private function generate(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Hello there',
            'target_language' => 'en',
            'voice_tier' => 'hd',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->premiumVoice()->id),
        ]);
    }

    private function assertNothingCharged(User $user): void
    {
        $counter = CreditPeriod::counter((int) $user->id);
        $this->assertSame(0, (int) $counter->total_chars, 'credits given back');
        $this->assertSame(0, (int) $counter->total_requests, 'the generation given back');
        $this->assertSame(0, (int) $counter->total_seconds, 'seconds given back');
    }

    private function geminiKeys(int $count): void
    {
        $keys = array_slice([
            ['key' => 'test-gemini-key', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1],
        ], 0, $count);
        $manager = \Mockery::mock(GeminiKeyManager::class);
        $manager->shouldReceive('getAllKeys')->andReturn($keys);
        $manager->shouldReceive('getAvailableKey')->andReturn($keys[0] ?? null);
        $manager->shouldReceive('recordUsage')->andReturnNull();
        $manager->shouldReceive('markKeyExhausted')->andReturnNull();
        $this->app->instance(GeminiKeyManager::class, $manager);
    }

    private function paidMember(): User
    {
        $plan = Plan::factory()->create([
            'slug' => 'studio-hd',
            'price_cents' => 1999,
            'voice_tier' => 'hd',
            'tts_provider_policy' => 'google_only',
            'monthly_chars' => 100000,
            'monthly_requests' => 0,
            'max_chars_per_request' => 5000,
            'is_active' => true,
        ]);
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }

    private function premiumVoice(): Voice
    {
        return Voice::query()->firstOrCreate(['internal_voice_id' => 'en-Gemini-HD-Kore'], [
            'language' => 'en', 'gender' => 'female', 'tier' => 'hd', 'display_name' => 'Daisy',
            'provider' => 'gemini', 'provider_voice_id' => 'Kore', 'is_active' => true,
        ]);
    }
}
