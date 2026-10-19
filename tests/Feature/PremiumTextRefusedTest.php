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
 * Text the premium engine will not read (a blocked prompt, a safety stop) is
 * not called busy: the member is asked to change the wording, the text is
 * sent only once, and every credit comes back. Speech is faked.
 */
class PremiumTextRefusedTest extends TestCase
{
    use RefreshDatabase;

    private int $premiumCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['services.gemini_tts.retry_pause_ms' => 0]);
        SiteSetting::set('tts_mode', TtsRoutingService::MODE_HYBRID, 'tts');

        $key = ['key' => 'test-gemini-key', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1];
        $manager = \Mockery::mock(GeminiKeyManager::class);
        $manager->shouldReceive('getAllKeys')->andReturn([$key]);
        $manager->shouldReceive('getAvailableKey')->andReturn($key);
        $manager->shouldReceive('recordUsage')->andReturnNull();
        $this->app->instance(GeminiKeyManager::class, $manager);
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_a_blocked_prompt_asks_for_other_wording_and_is_sent_once(): void
    {
        $this->premiumReply(['promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT']]);
        $user = $this->paidMember();

        $message = $this->generate($user)
            ->assertStatus(422)
            ->assertJsonPath('job.status', 'failed')
            ->json('message');

        $this->assertSame(trans('ui.backend.premium_text_refused', [], 'en'), $message);
        $this->assertStringNotContainsStringIgnoringCase('minute', $message);
        $this->assertSame(GenerationMessage::stored('ui.backend.premium_text_refused'), AudioGeneration::query()->latest('id')->value('error_message'));
        $this->assertSame(1, $this->premiumCalls, 'the same text is not sent again');
        $this->assertNothingCharged($user);
    }

    public function test_a_safety_stop_says_the_same_in_arabic(): void
    {
        $this->premiumReply(['candidates' => [['finishReason' => 'SAFETY', 'content' => ['parts' => []]]]]);
        $user = $this->paidMember();
        app()->setLocale('ar');

        $message = $this->generate($user)
            ->assertStatus(422)
            ->assertJsonPath('job.error_message', trans('ui.backend.premium_text_refused', [], 'ar'))
            ->json('message');

        $this->assertSame(trans('ui.backend.premium_text_refused', [], 'ar'), $message);
        $this->assertStringNotContainsString('دقيقة', $message);
        $this->assertSame(1, $this->premiumCalls);
        $this->assertNothingCharged($user);
    }

    /** @param array<string, mixed> $body */
    private function premiumReply(array $body): void
    {
        Http::fake(function () use ($body) {
            $this->premiumCalls++;

            return Http::response($body);
        });
    }

    private function generate(User $user): \Illuminate\Testing\TestResponse
    {
        $voice = Voice::query()->firstOrCreate(['internal_voice_id' => 'en-Gemini-HD-Kore'], [
            'language' => 'en', 'gender' => 'female', 'tier' => 'hd', 'display_name' => 'Daisy',
            'provider' => 'gemini', 'provider_voice_id' => 'Kore', 'is_active' => true,
        ]);

        return $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Hello there',
            'target_language' => 'en',
            'voice_tier' => 'hd',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
        ]);
    }

    private function assertNothingCharged(User $user): void
    {
        $counter = CreditPeriod::counter((int) $user->id);
        $this->assertSame(0, (int) $counter->total_chars, 'credits given back');
        $this->assertSame(0, (int) $counter->total_requests, 'the generation given back');
        $this->assertSame(0, (int) $counter->total_seconds, 'seconds given back');
    }

    private function paidMember(): User
    {
        $plan = Plan::factory()->create([
            'slug' => 'studio-hd',
            'price_cents' => 1999,
            'voice_tier' => 'hd',
            'monthly_chars' => 100000,
            'monthly_requests' => 0,
            'max_chars_per_request' => 5000,
            'is_active' => true,
        ]);
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }
}
