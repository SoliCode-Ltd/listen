<?php

namespace Tests\Feature;

use App\Models\DemoFraudSignal;
use App\Models\DemoGuestUsage;
use App\Models\DemoIdentityLink;
use App\Models\DemoVoiceGeneration;
use App\Models\Plan;
use App\Models\User;
use App\Models\Voice;
use App\Services\TranslationService;
use App\Services\TtsService;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Nobody can get more demo audio than their allowance, however they send
 * the requests. Speech is always faked here: no real audio is generated.
 */
class DemoQuotaAbuseProtectionTest extends TestCase
{
    use RefreshDatabase;

    private Voice $voice;

    /** @var list<string> */
    private array $spokenTexts = [];

    /** @var (callable(): void)|null */
    private $duringSpeech = null;

    private bool $speechFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::disk('public')->put('audio/demo-track.mp3', 'demo-audio');

        $this->seedFreePlan();
        $this->voice = $this->seedVoice();
        $this->fakeSpeech();
    }

    // ──────────────────────────────────────────────────────────────────
    //  Requests sent at the same time
    // ──────────────────────────────────────────────────────────────────

    public function test_second_request_sent_while_the_first_is_still_generating_is_refused(): void
    {
        $user = User::factory()->create(['registration_ip' => '203.0.113.10']);
        $this->actingAs($user);

        $inner = null;
        $this->duringSpeech = function () use (&$inner): void {
            $this->duringSpeech = null;
            $inner = $this->preview(str_repeat('b', 200));
        };

        $outer = $this->preview(str_repeat('a', 200));

        $outer->assertOk()->assertJsonPath('ok', true);
        $inner->assertStatus(422)->assertJsonPath('message', __('ui.backend.demo_trial_exhausted'));
        $this->assertCount(1, $this->spokenTexts, 'only one request may reach the speech service');
        $this->assertSame(200, (int) DemoVoiceGeneration::query()->sum('billable_chars'));
    }

    public function test_many_requests_at_once_never_get_past_the_allowance(): void
    {
        $user = User::factory()->create(['registration_ip' => '203.0.113.11']);
        $this->actingAs($user);

        // Each request starts another before its own audio comes back,
        // five deep: the same as five requests in flight together.
        $results = [];
        $depth = 0;
        $this->duringSpeech = function () use (&$results, &$depth): void {
            if (++$depth >= 5) {
                return;
            }
            $results[] = $this->preview(str_repeat('c', 100));
        };

        $results[] = $this->preview(str_repeat('c', 100));

        $this->assertLessThanOrEqual(300, (int) DemoVoiceGeneration::query()->sum('billable_chars'));
        $this->assertCount(3, $this->spokenTexts, '300 chars allow three 100-char previews');
    }

    public function test_linked_account_cannot_spend_the_shared_allowance_while_a_request_is_in_flight(): void
    {
        $sharedIp = '203.0.113.12';
        $userA = User::factory()->create(['registration_ip' => $sharedIp]);
        $userB = User::factory()->create(['registration_ip' => $sharedIp]);

        $inner = null;
        $this->duringSpeech = function () use (&$inner, $userA, $userB): void {
            $this->duringSpeech = null;
            $this->actingAs($userB);
            $inner = $this->preview(str_repeat('b', 200));
            $this->actingAs($userA);
        };

        $this->actingAs($userA);
        $outer = $this->preview(str_repeat('a', 200));

        $outer->assertOk();
        $inner->assertStatus(422)->assertJsonPath('message', __('ui.backend.demo_trial_exhausted'));
        $this->assertCount(1, $this->spokenTexts);
    }

    public function test_request_within_what_is_left_still_works_while_another_is_in_flight(): void
    {
        $user = User::factory()->create(['registration_ip' => '203.0.113.13']);
        $this->actingAs($user);

        $inner = null;
        $this->duringSpeech = function () use (&$inner): void {
            $this->duringSpeech = null;
            $inner = $this->preview(str_repeat('b', 100));
        };

        $this->preview(str_repeat('a', 200))->assertOk();

        $inner->assertOk();
        $this->assertSame(300, (int) DemoVoiceGeneration::query()->sum('billable_chars'));
    }

    // ──────────────────────────────────────────────────────────────────
    //  Reservations are released
    // ──────────────────────────────────────────────────────────────────

    public function test_finished_request_releases_its_reservation_and_is_counted_once(): void
    {
        $user = User::factory()->create(['registration_ip' => '203.0.113.20']);
        $this->actingAs($user);

        $this->preview(str_repeat('a', 120))
            ->assertOk()
            ->assertJsonPath('quota.used_chars', 120)
            ->assertJsonPath('quota.remaining_chars', 180);

        $this->assertSame(0, (int) Cache::get("demo:pending_chars:u:{$user->id}", 0));

        $this->postJsonLocalizedRoute('demo.quota', [])
            ->assertJsonPath('quota.used_chars', 120);
    }

    public function test_failed_generation_gives_the_chars_back(): void
    {
        $user = User::factory()->create(['registration_ip' => '203.0.113.21']);
        $this->actingAs($user);
        $this->speechFails = true;

        $this->preview(str_repeat('a', 300))->assertStatus(422);

        $day = now()->format('Ymd');
        $this->assertSame(0, (int) Cache::get("demo:pending_chars:u:{$user->id}", 0));
        $this->assertSame(0, (int) Cache::get("demo:chars:{$day}:preview:global", 0));
        $this->assertSame(0, (int) Cache::get("demo:req:{$day}:preview:global", 0));
        $this->assertSame(0, (int) Cache::get("demo:chars:{$day}:preview:u:{$user->id}", 0));

        // The full allowance is still there.
        $this->speechFails = false;
        $this->preview(str_repeat('a', 300))->assertOk();
    }

    public function test_reservation_from_a_request_that_died_expires(): void
    {
        $user = User::factory()->create(['registration_ip' => '203.0.113.22']);
        $this->actingAs($user);

        // As if a request reserved 300 chars and the server process was killed.
        Cache::put("demo:pending_chars:u:{$user->id}", 300, 600);

        $this->preview('Hello')->assertStatus(422);

        $this->travel(11)->minutes();

        $this->preview('Hello')->assertOk();
    }

    // ──────────────────────────────────────────────────────────────────
    //  Per-user daily limits
    // ──────────────────────────────────────────────────────────────────

    public function test_daily_preview_request_limit_per_user(): void
    {
        config([
            'listen.demo.guest_char_allowance' => 100000,
            'listen.demo.preview.user_daily_requests' => 2,
        ]);
        $user = User::factory()->create(['registration_ip' => '203.0.113.30']);
        $this->actingAs($user);

        $this->preview('One')->assertOk();
        $this->preview('Two')->assertOk();
        $this->preview('Three')
            ->assertStatus(422)
            ->assertJsonPath('message', __('ui.backend.demo_daily_limit_reached'));

        $this->assertCount(2, $this->spokenTexts);

        $this->travel(1)->days();
        $this->preview('Next day')->assertOk();
    }

    public function test_daily_preview_char_limit_per_user(): void
    {
        config([
            'listen.demo.guest_char_allowance' => 100000,
            'listen.demo.preview.user_daily_chars' => 250,
        ]);
        $user = User::factory()->create(['registration_ip' => '203.0.113.31']);
        $this->actingAs($user);

        $this->preview(str_repeat('a', 200))->assertOk();
        $this->preview(str_repeat('a', 100))
            ->assertStatus(422)
            ->assertJsonPath('message', __('ui.backend.demo_daily_limit_reached'));
        $this->preview(str_repeat('a', 50))->assertOk();
    }

    public function test_daily_limits_are_per_user(): void
    {
        config([
            'listen.demo.guest_char_allowance' => 100000,
            'listen.demo.preview.user_daily_requests' => 1,
        ]);
        $userA = User::factory()->create(['registration_ip' => '203.0.113.32']);
        $userB = User::factory()->create(['registration_ip' => '203.0.113.33']);

        $this->actingAs($userA)->preview('Mine')->assertOk();
        $this->actingAs($userB)->preview('Mine too')->assertOk();
    }

    public function test_daily_check_request_limit_per_user(): void
    {
        config(['listen.demo.check.user_daily_requests' => 2]);
        $user = User::factory()->create(['registration_ip' => '203.0.113.34']);
        $this->actingAs($user);

        $this->check('One')->assertOk();
        $this->check('Two')->assertOk();
        $this->check('Three')
            ->assertStatus(422)
            ->assertJsonPath('message', __('ui.backend.demo_daily_limit_reached'));
    }

    public function test_failed_generation_does_not_use_up_the_daily_request_limit(): void
    {
        config([
            'listen.demo.guest_char_allowance' => 100000,
            'listen.demo.preview.user_daily_requests' => 1,
        ]);
        $user = User::factory()->create(['registration_ip' => '203.0.113.35']);
        $this->actingAs($user);

        $this->speechFails = true;
        $this->preview('Hello')->assertStatus(422);

        $this->speechFails = false;
        $this->preview('Hello')->assertOk();
    }

    public function test_preview_rate_per_minute_defaults_to_ten(): void
    {
        $this->assertSame(10, (int) config('listen.demo.preview.user_rate_per_minute'));

        config(['listen.demo.guest_char_allowance' => 100000]);
        $user = User::factory()->create(['registration_ip' => '203.0.113.36']);
        $this->actingAs($user);

        for ($i = 0; $i < 10; $i++) {
            $this->preview("Line {$i}")->assertOk();
        }

        $this->preview('Eleventh')->assertStatus(422);
        $this->assertCount(10, $this->spokenTexts);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Site-wide demo limits
    // ──────────────────────────────────────────────────────────────────

    public function test_global_daily_request_cap(): void
    {
        config(['listen.demo.global_preview_daily_requests' => 2]);

        foreach (['203.0.113.40', '203.0.113.41'] as $ip) {
            $this->actingAs(User::factory()->create(['registration_ip' => $ip]))
                ->preview('Hello')
                ->assertOk();
        }

        $this->actingAs(User::factory()->create(['registration_ip' => '203.0.113.42']))
            ->preview('Hello')
            ->assertStatus(422)
            ->assertJsonPath('message', __('ui.backend.demo_preview_capacity_reached'));
    }

    public function test_global_daily_char_cap(): void
    {
        config(['listen.demo.global_preview_daily_chars' => 250]);

        $this->actingAs(User::factory()->create(['registration_ip' => '203.0.113.43']))
            ->preview(str_repeat('a', 200))
            ->assertOk();

        $this->actingAs(User::factory()->create(['registration_ip' => '203.0.113.44']))
            ->preview(str_repeat('a', 100))
            ->assertStatus(422)
            ->assertJsonPath('message', __('ui.backend.demo_preview_capacity_reached'));
    }

    public function test_global_cap_holds_under_simultaneous_requests(): void
    {
        config(['listen.demo.global_preview_daily_requests' => 1]);
        $userA = User::factory()->create(['registration_ip' => '203.0.113.45']);
        $userB = User::factory()->create(['registration_ip' => '203.0.113.46']);

        $inner = null;
        $this->duringSpeech = function () use (&$inner, $userA, $userB): void {
            $this->duringSpeech = null;
            $this->actingAs($userB);
            $inner = $this->preview('Hello');
            $this->actingAs($userA);
        };

        $this->actingAs($userA)->preview('Hello')->assertOk();

        $inner->assertStatus(422)->assertJsonPath('message', __('ui.backend.demo_preview_capacity_reached'));
        $this->assertCount(1, $this->spokenTexts);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Linked accounts are seen at once
    // ──────────────────────────────────────────────────────────────────

    public function test_new_account_on_the_same_ip_counts_immediately(): void
    {
        $sharedIp = '203.0.113.50';
        $userA = User::factory()->create(['registration_ip' => $sharedIp]);

        $this->actingAs($userA)->postJsonLocalizedRoute('demo.quota', [])
            ->assertJsonPath('quota.used_chars', 0);

        // A second account appears and spends the allowance. No cache flush.
        $userB = User::factory()->create(['registration_ip' => $sharedIp]);
        $this->actingAs($userB)->preview(str_repeat('b', 300))->assertOk();

        $this->actingAs($userA)->postJsonLocalizedRoute('demo.quota', [])
            ->assertJsonPath('quota.used_chars', 300)
            ->assertJsonPath('quota.is_exhausted', true);

        $this->actingAs($userA)->preview('Hello')
            ->assertStatus(422)
            ->assertJsonPath('message', __('ui.backend.demo_trial_exhausted'));
    }

    public function test_request_ip_links_accounts_immediately(): void
    {
        $userA = User::factory()->create(['registration_ip' => '203.0.113.51']);
        $userB = User::factory()->create(['registration_ip' => '198.51.100.9']);

        $this->actingAs($userA)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->postJsonLocalizedRoute('demo.quota', [])
            ->assertJsonPath('quota.used_chars', 0);

        $this->actingAs($userB)->preview(str_repeat('b', 300))->assertOk();

        $this->actingAs($userA)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->postJsonLocalizedRoute('demo.quota', [])
            ->assertJsonPath('quota.used_chars', 300);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Automated browsers and admin blocks
    // ──────────────────────────────────────────────────────────────────

    public function test_headless_browser_is_refused_before_any_speech(): void
    {
        $user = User::factory()->create(['registration_ip' => '203.0.113.60']);

        $this->actingAs($user)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 HeadlessChrome/120.0.0.0 Safari/537.36'])
            ->withCredentials()
            ->withUnencryptedCookie('listen_demo_fp', $this->fingerprintCookie(['canvas' => 'canvas-headless']))
            ->preview('Hello')
            ->assertStatus(422)
            ->assertJsonPath('message', __('ui.backend.demo_access_blocked'));

        $this->assertCount(0, $this->spokenTexts);
        $this->assertTrue(DemoFraudSignal::query()
            ->where('identity_hash', 'cv:'.hash('sha256', 'canvas-headless'))
            ->where('signal_type', 'automation_detected')
            ->exists());
    }

    public function test_browser_reporting_webdriver_is_refused(): void
    {
        $user = User::factory()->create(['registration_ip' => '203.0.113.61']);

        $this->actingAs($user)
            ->withCredentials()
            ->withUnencryptedCookie('listen_demo_fp', $this->fingerprintCookie(['canvas' => 'canvas-driven', 'webdriver' => 'true']))
            ->preview('Hello')
            ->assertStatus(422)
            ->assertJsonPath('message', __('ui.backend.demo_access_blocked'));

        $this->assertCount(0, $this->spokenTexts);
    }

    public function test_automated_browser_is_refused_on_the_check_endpoint_too(): void
    {
        $user = User::factory()->create(['registration_ip' => '203.0.113.62']);

        $this->actingAs($user)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 HeadlessChrome/120.0.0.0'])
            ->check('Hello')
            ->assertStatus(422)
            ->assertJsonPath('message', __('ui.backend.demo_access_blocked'));
    }

    public function test_identity_blocked_by_an_admin_is_refused(): void
    {
        DemoGuestUsage::query()->create([
            'identity_hash' => 'cv:'.hash('sha256', 'canvas-banned'),
            'used_chars' => 0,
            'is_blocked' => true,
        ]);
        $user = User::factory()->create(['registration_ip' => '203.0.113.63']);

        $this->actingAs($user)
            ->withCredentials()
            ->withUnencryptedCookie('listen_demo_fp', $this->fingerprintCookie(['canvas' => 'canvas-banned']))
            ->preview('Hello')
            ->assertStatus(422)
            ->assertJsonPath('message', __('ui.backend.demo_access_blocked'));

        $this->assertCount(0, $this->spokenTexts);
    }

    public function test_admin_block_reaches_a_browser_linked_through_another_fingerprint(): void
    {
        $canvas = 'cv:'.hash('sha256', 'canvas-linked');
        $webgl = 'gl:'.hash('sha256', 'webgl-banned');
        foreach ([[$canvas, 'canvas'], [$webgl, 'webgl']] as [$hash, $type]) {
            DemoIdentityLink::query()->create([
                'identity_hash' => $hash,
                'link_group' => 'sg:group-1',
                'identity_type' => $type,
                'confidence' => 1.0,
            ]);
        }
        DemoGuestUsage::query()->create(['identity_hash' => $webgl, 'used_chars' => 0, 'is_blocked' => true]);

        $this->actingAs(User::factory()->create(['registration_ip' => '203.0.113.64']))
            ->withCredentials()
            ->withUnencryptedCookie('listen_demo_fp', $this->fingerprintCookie(['canvas' => 'canvas-linked']))
            ->preview('Hello')
            ->assertStatus(422)
            ->assertJsonPath('message', __('ui.backend.demo_access_blocked'));
    }

    public function test_unblocked_identity_can_use_the_demo(): void
    {
        DemoGuestUsage::query()->create([
            'identity_hash' => 'cv:'.hash('sha256', 'canvas-forgiven'),
            'used_chars' => 0,
            'is_blocked' => false,
        ]);

        $this->actingAs(User::factory()->create(['registration_ip' => '203.0.113.65']))
            ->withCredentials()
            ->withUnencryptedCookie('listen_demo_fp', $this->fingerprintCookie(['canvas' => 'canvas-forgiven']))
            ->preview('Hello')
            ->assertOk();
    }

    public function test_block_on_a_shared_user_agent_identity_does_not_block_everyone(): void
    {
        // Weak identities are shared by thousands of people with the same browser.
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';
        DemoGuestUsage::query()->create([
            'identity_hash' => 'ua:'.hash('sha256', implode('|', [$ua, '', '', '', ''])),
            'used_chars' => 0,
            'is_blocked' => true,
        ]);

        $this->actingAs(User::factory()->create(['registration_ip' => '203.0.113.66']))
            ->withHeaders(['User-Agent' => $ua, 'Sec-CH-UA' => '"Chromium";v="120"'])
            ->preview('Hello')
            ->assertOk();
    }

    public function test_privacy_browser_without_fingerprints_is_not_blocked(): void
    {
        // Blocked fingerprinting, a private IP and no client hints are soft
        // signals: they never refuse a request on their own.
        $this->actingAs(User::factory()->create(['registration_ip' => '10.0.0.70']))
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.70'])
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36'])
            ->preview('Hello')
            ->assertOk();

        $this->actingAs(User::factory()->create(['registration_ip' => '10.0.0.70']))
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.70'])
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36'])
            ->preview('Hello')
            ->assertOk();
    }

    public function test_blocked_account_is_logged_out_of_the_demo(): void
    {
        $user = User::factory()->create(['registration_ip' => '203.0.113.67', 'is_blocked' => true]);

        $this->actingAs($user)->preview('Hello')->assertStatus(403);
        $this->assertCount(0, $this->spokenTexts);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────────

    private function preview(string $text): TestResponse
    {
        return $this->postJsonLocalizedRoute('demo.preview', [
            'text' => $text,
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->voice->id),
        ]);
    }

    private function check(string $text): TestResponse
    {
        return $this->postJsonLocalizedRoute('demo.run', [
            'text' => $text,
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->voice->id),
        ]);
    }

    /**
     * @param  array<string, string>  $components
     */
    private function fingerprintCookie(array $components): string
    {
        return base64_encode(json_encode($components));
    }

    private function fakeSpeech(): void
    {
        $translation = $this->createMock(TranslationService::class);
        $translation->method('maybeTranslate')->willReturnArgument(0);
        $this->app->instance(TranslationService::class, $translation);

        $tts = $this->createMock(TtsService::class);
        $tts->method('synthesizeAdvanced')->willReturnCallback(function (string $text): array {
            if ($this->speechFails) {
                throw new \RuntimeException('Speech service unavailable.');
            }

            $this->spokenTexts[] = $text;

            if ($this->duringSpeech !== null) {
                ($this->duringSpeech)();
            }

            return [
                'audio_path' => 'audio/demo-track.mp3',
                'duration_seconds' => 2,
                'provider' => 'google_cloud',
                'model' => 'google-cloud-tts',
                'latency_ms' => 10,
                'fallback_used' => false,
                'cost_estimate_usd' => 0.0,
                'routing_mode' => 'hybrid',
                'plan_slug' => 'free',
            ];
        });
        $this->app->instance(TtsService::class, $tts);
    }

    private function seedFreePlan(): Plan
    {
        return Plan::query()->firstOrCreate(
            ['slug' => 'free'],
            [
                'name' => 'Free',
                'price_cents' => 0,
                'monthly_minutes' => 10,
                'monthly_chars' => 10000,
                'monthly_requests' => 60,
                'max_chars_per_request' => 1200,
                'voice_tier' => 'standard',
                'tts_provider_policy' => 'google_cloud',
                'dialogue_enabled' => false,
                'custom_prompt_enabled' => false,
                'is_active' => true,
            ]
        );
    }

    private function seedVoice(): Voice
    {
        return Voice::query()->firstOrCreate(
            ['internal_voice_id' => 'en-US-Wavenet-C'],
            [
                'language' => 'en',
                'gender' => 'female',
                'tier' => 'standard',
                'display_name' => 'Emma',
                'provider' => 'google_cloud',
                'provider_voice_id' => 'en-US-Wavenet-C',
                'is_active' => true,
            ]
        );
    }
}
