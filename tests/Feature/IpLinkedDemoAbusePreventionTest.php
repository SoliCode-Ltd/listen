<?php

namespace Tests\Feature;

use App\Models\DemoVoiceGeneration;
use App\Models\Plan;
use App\Models\User;
use App\Models\Voice;
use App\Services\Auth\PhoneAuthBridgeService;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

/**
 * Comprehensive tests to ensure users cannot bypass demo character limits
 * by creating multiple accounts.  The anti-abuse system tracks IPs across
 * all authentication methods (email, Google OAuth, Firebase Google, Firebase phone)
 * and aggregates demo usage from every account sharing the same IP.
 */
class IpLinkedDemoAbusePreventionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed free plan before any User::factory()->create() calls so the
        // User::booted() callback (ensureFreePlanForUser) doesn't fail.
        $this->seedFreePlan();
    }

    // ──────────────────────────────────────────────────────────────────
    //  1.  IP stamping — every auth path must record the IP
    // ──────────────────────────────────────────────────────────────────

    public function test_email_registration_stamps_registration_ip(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postLocalizedRoute('register.submit', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'test@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('10.0.0.1', $user->registration_ip);
    }

    public function test_email_login_stamps_last_login_ip(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123!'),
            'registration_ip' => '10.0.0.1',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postLocalizedRoute('login.submit', [
                'email' => $user->email,
                'password' => 'Password123!',
            ]);

        $user->refresh();
        $this->assertSame('10.0.0.2', $user->last_login_ip);
    }

    public function test_email_login_backfills_registration_ip_when_null(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123!'),
            'registration_ip' => null,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->postLocalizedRoute('login.submit', [
                'email' => $user->email,
                'password' => 'Password123!',
            ]);

        $user->refresh();
        $this->assertSame('10.0.0.5', $user->registration_ip);
        $this->assertSame('10.0.0.5', $user->last_login_ip);
    }

    public function test_phone_auth_stamps_login_ip(): void
    {
        $this->enablePhoneSettings();

        $user = User::factory()->create([
            'firebase_uid' => 'phone-uid-1',
            'phone_number' => '+201000000001',
            'phone_verified_at' => now(),
            'registration_ip' => '10.0.0.1',
        ]);

        $mock = Mockery::mock(PhoneAuthBridgeService::class);
        $mock->shouldReceive('resolveUserFromIdToken')
            ->once()
            ->andReturn($user);
        $this->app->instance(PhoneAuthBridgeService::class, $mock);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->postJsonLocalizedRoute('auth.phone.verify', [
                'id_token' => 'mock_token',
                'intent' => 'login',
            ]);

        $user->refresh();
        $this->assertSame('10.0.0.99', $user->last_login_ip);
    }

    public function test_firebase_google_auth_stamps_login_ip(): void
    {
        $this->enableFirebaseGoogleSettings();

        $user = User::factory()->create([
            'firebase_uid' => 'fb-google-uid-1',
            'registration_ip' => '10.0.0.1',
        ]);

        $mock = Mockery::mock(PhoneAuthBridgeService::class);
        $mock->shouldReceive('resolveUserFromGoogleIdToken')
            ->once()
            ->andReturn($user);
        $this->app->instance(PhoneAuthBridgeService::class, $mock);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.77'])
            ->postJsonLocalizedRoute('auth.firebase-google.verify', [
                'id_token' => 'mock_google_token',
            ]);

        $user->refresh();
        $this->assertSame('10.0.0.77', $user->last_login_ip);
    }

    public function test_phone_auth_new_user_gets_registration_ip_via_bridge(): void
    {
        $this->enablePhoneSettings();

        $user = User::factory()->create([
            'firebase_uid' => 'phone-new-uid',
            'phone_number' => '+201000000099',
            'phone_verified_at' => now(),
            'registration_ip' => '10.0.0.42',
        ]);

        $mock = Mockery::mock(PhoneAuthBridgeService::class);
        $mock->shouldReceive('resolveUserFromIdToken')
            ->once()
            ->andReturn($user);
        $this->app->instance(PhoneAuthBridgeService::class, $mock);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.42'])
            ->postJsonLocalizedRoute('auth.phone.verify', [
                'id_token' => 'mock_token',
                'intent' => 'register',
            ]);

        $user->refresh();
        $this->assertSame('10.0.0.42', $user->registration_ip);
    }

    // ──────────────────────────────────────────────────────────────────
    //  2.  IP-linked quota sharing — multi-account abuse via same IP
    // ──────────────────────────────────────────────────────────────────

    public function test_two_accounts_same_registration_ip_share_demo_quota(): void
    {
        $voice = $this->seedVoice();
        $sharedIp = '192.168.1.100';

        $userA = User::factory()->create(['registration_ip' => $sharedIp]);
        $this->storeDemoUsage($userA, 260);

        $userB = User::factory()->create(['registration_ip' => $sharedIp]);

        Cache::flush();

        $response = $this->actingAs($userB)->postJsonLocalizedRoute('demo.run', [
            'text' => str_repeat('a', 50), // 260+50 > 300
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('text');
    }

    public function test_two_accounts_same_login_ip_share_demo_quota(): void
    {
        $voice = $this->seedVoice();
        $sharedIp = '192.168.1.200';

        $userA = User::factory()->create(['last_login_ip' => $sharedIp]);
        $this->storeDemoUsage($userA, 280);

        $userB = User::factory()->create(['last_login_ip' => $sharedIp]);

        Cache::flush();

        $response = $this->actingAs($userB)->postJsonLocalizedRoute('demo.run', [
            'text' => str_repeat('b', 30), // 280+30 > 300
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('text');
    }

    public function test_registration_ip_of_one_matches_login_ip_of_another_shares_quota(): void
    {
        $voice = $this->seedVoice();
        $sharedIp = '172.16.0.50';

        $userA = User::factory()->create(['registration_ip' => $sharedIp, 'last_login_ip' => '1.2.3.4']);
        $this->storeDemoUsage($userA, 270);

        $userB = User::factory()->create(['registration_ip' => '5.6.7.8', 'last_login_ip' => $sharedIp]);

        Cache::flush();

        $response = $this->actingAs($userB)->postJsonLocalizedRoute('demo.run', [
            'text' => str_repeat('c', 40), // 270+40 > 300
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('text');
    }

    public function test_three_accounts_chained_through_shared_ip_all_share_quota(): void
    {
        $voice = $this->seedVoice();

        // UserA: reg=10.0.0.1, login=10.0.0.2
        $userA = User::factory()->create(['registration_ip' => '10.0.0.1', 'last_login_ip' => '10.0.0.2']);
        $this->storeDemoUsage($userA, 150);

        // UserB: reg=10.0.0.1 (same as A's reg_ip → linked to A)
        $userB = User::factory()->create(['registration_ip' => '10.0.0.1', 'last_login_ip' => '10.0.0.3']);
        $this->storeDemoUsage($userB, 100);

        // UserC: login=10.0.0.1 (shares IP with A & B via last_login_ip matching their registration_ip)
        $userC = User::factory()->create(['registration_ip' => '10.0.0.4', 'last_login_ip' => '10.0.0.1']);

        Cache::flush();

        // UserC's lookup finds A and B via 10.0.0.1 → 150 + 100 = 250, request 60 → 310 > 300
        $response = $this->actingAs($userC)->postJsonLocalizedRoute('demo.run', [
            'text' => str_repeat('d', 60),
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('text');
    }

    public function test_different_ips_do_not_share_quota(): void
    {
        $this->seedVoice();

        $userA = User::factory()->create(['registration_ip' => '10.0.0.1', 'last_login_ip' => '10.0.0.1']);
        $this->storeDemoUsage($userA, 290);

        $userB = User::factory()->create(['registration_ip' => '10.0.0.2', 'last_login_ip' => '10.0.0.2']);

        Cache::flush();

        $quota = $this->actingAs($userB)->postJsonLocalizedRoute('demo.quota', []);

        $quota->assertOk();
        $quota->assertJsonPath('quota.used_chars', 0);
        $quota->assertJsonPath('quota.remaining_chars', 300);
    }

    // ──────────────────────────────────────────────────────────────────
    //  3.  Cross-auth-method abuse scenarios
    // ──────────────────────────────────────────────────────────────────

    public function test_email_register_then_phone_register_same_ip_shares_quota(): void
    {
        $voice = $this->seedVoice();
        $sharedIp = '203.0.113.10';

        $emailUser = User::factory()->create([
            'registration_ip' => $sharedIp,
            'auth_provider' => null,
        ]);
        $this->storeDemoUsage($emailUser, 260);

        $phoneUser = User::factory()->create([
            'registration_ip' => $sharedIp,
            'auth_provider' => 'phone',
            'firebase_uid' => 'phone-abuse-uid',
            'phone_number' => '+201111111111',
        ]);

        Cache::flush();

        $response = $this->actingAs($phoneUser)->postJsonLocalizedRoute('demo.run', [
            'text' => str_repeat('e', 50), // 260+50 > 300
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('text');
    }

    public function test_google_oauth_then_email_register_same_ip_shares_quota(): void
    {
        $voice = $this->seedVoice();
        $sharedIp = '203.0.113.20';

        $googleUser = User::factory()->create([
            'registration_ip' => $sharedIp,
            'google_id' => 'google-abuse-id',
        ]);
        $this->storeDemoUsage($googleUser, 250);

        $emailUser = User::factory()->create([
            'registration_ip' => $sharedIp,
        ]);

        Cache::flush();

        $response = $this->actingAs($emailUser)->postJsonLocalizedRoute('demo.run', [
            'text' => str_repeat('f', 60), // 250+60 > 300
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('text');
    }

    public function test_firebase_google_then_phone_register_same_ip_shares_quota(): void
    {
        $voice = $this->seedVoice();
        $sharedIp = '203.0.113.30';

        $fbGoogleUser = User::factory()->create([
            'registration_ip' => $sharedIp,
            'auth_provider' => 'firebase_google',
            'firebase_uid' => 'fb-google-abuse-uid',
        ]);
        $this->storeDemoUsage($fbGoogleUser, 280);

        $phoneUser = User::factory()->create([
            'registration_ip' => $sharedIp,
            'auth_provider' => 'phone',
            'firebase_uid' => 'phone-abuse-uid-2',
            'phone_number' => '+201222222222',
        ]);

        Cache::flush();

        $response = $this->actingAs($phoneUser)->postJsonLocalizedRoute('demo.run', [
            'text' => str_repeat('g', 30), // 280+30 > 300
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('text');
    }

    // ──────────────────────────────────────────────────────────────────
    //  4.  Quota endpoint reflects IP-linked usage
    // ──────────────────────────────────────────────────────────────────

    public function test_quota_endpoint_shows_aggregated_usage_from_ip_linked_accounts(): void
    {
        $this->seedVoice();
        $sharedIp = '198.51.100.1';

        $userA = User::factory()->create(['registration_ip' => $sharedIp]);
        $this->storeDemoUsage($userA, 100);

        $userB = User::factory()->create(['registration_ip' => $sharedIp]);
        $this->storeDemoUsage($userB, 50);

        $userC = User::factory()->create(['registration_ip' => $sharedIp]);

        Cache::flush();

        $quota = $this->actingAs($userC)->postJsonLocalizedRoute('demo.quota', []);

        $quota->assertOk();
        $quota->assertJsonPath('quota.used_chars', 150);
        $quota->assertJsonPath('quota.remaining_chars', 150);
        $quota->assertJsonPath('quota.is_exhausted', false);
    }

    public function test_quota_shows_exhausted_when_ip_linked_accounts_used_all_chars(): void
    {
        $this->seedVoice();
        $sharedIp = '198.51.100.2';

        $userA = User::factory()->create(['registration_ip' => $sharedIp]);
        $this->storeDemoUsage($userA, 300);

        $userB = User::factory()->create(['registration_ip' => $sharedIp]);

        Cache::flush();

        $quota = $this->actingAs($userB)->postJsonLocalizedRoute('demo.quota', []);

        $quota->assertOk();
        $quota->assertJsonPath('quota.used_chars', 300);
        $quota->assertJsonPath('quota.remaining_chars', 0);
        $quota->assertJsonPath('quota.is_exhausted', true);
    }

    public function test_different_ips_still_share_quota_when_browser_fingerprint_matches(): void
    {
        $this->seedVoice();
        $sharedFingerprint = 'fp_shared_browser_0123456789abcdef';

        $userA = User::factory()->create([
            'registration_ip' => '203.0.113.10',
            'last_login_ip' => '203.0.113.10',
        ]);

        DemoVoiceGeneration::query()->create([
            'user_id' => $userA->id,
            'primary_identity_hash' => 'fp:'.$sharedFingerprint,
            'input_text' => str_repeat('x', 240),
            'speech_text' => str_repeat('x', 240),
            'source_language' => 'en',
            'target_language' => 'en',
            'translate' => false,
            'voice_name' => 'en-US-Wavenet-C',
            'voice_provider' => 'google_cloud',
            'voice_tier' => 'standard',
            'billable_chars' => 240,
        ]);

        $userB = User::factory()->create([
            'registration_ip' => '198.51.100.77',
            'last_login_ip' => '198.51.100.77',
        ]);

        Cache::flush();

        $quota = $this->actingAs($userB)
            ->withHeaders(['X-Client-FP' => $sharedFingerprint])
            ->postJsonLocalizedRoute('demo.quota', []);

        $quota->assertOk();
        $quota->assertJsonPath('quota.used_chars', 240);
        $quota->assertJsonPath('quota.remaining_chars', 60);
        $quota->assertJsonPath('quota.is_exhausted', false);
    }

    // ──────────────────────────────────────────────────────────────────
    //  5.  Edge cases
    // ──────────────────────────────────────────────────────────────────

    public function test_user_with_null_ips_only_sees_own_usage(): void
    {
        $this->seedVoice();

        $other = User::factory()->create(['registration_ip' => '10.0.0.1']);
        $this->storeDemoUsage($other, 300);

        $nullIpUser = User::factory()->create([
            'registration_ip' => null,
            'last_login_ip' => null,
        ]);

        Cache::flush();

        $quota = $this->actingAs($nullIpUser)->postJsonLocalizedRoute('demo.quota', []);

        $quota->assertOk();
        $quota->assertJsonPath('quota.used_chars', 0);
        $quota->assertJsonPath('quota.remaining_chars', 300);
    }

    public function test_cache_refresh_picks_up_new_account_on_same_ip(): void
    {
        $this->seedVoice();
        $sharedIp = '10.10.10.10';

        $userA = User::factory()->create(['registration_ip' => $sharedIp]);

        Cache::flush();
        $quota = $this->actingAs($userA)->postJsonLocalizedRoute('demo.quota', []);
        $quota->assertJsonPath('quota.remaining_chars', 300);

        $userB = User::factory()->create(['registration_ip' => $sharedIp]);
        $this->storeDemoUsage($userB, 200);

        // Flush cache to simulate 120s TTL expiration
        Cache::flush();

        $quota2 = $this->actingAs($userA)->postJsonLocalizedRoute('demo.quota', []);
        $quota2->assertJsonPath('quota.used_chars', 200);
        $quota2->assertJsonPath('quota.remaining_chars', 100);
    }

    public function test_user_switching_ip_still_tracked_via_registration_ip(): void
    {
        $voice = $this->seedVoice();
        $origIp = '10.20.30.40';

        $userA = User::factory()->create([
            'registration_ip' => $origIp,
            'last_login_ip' => '99.99.99.99', // VPN
        ]);
        $this->storeDemoUsage($userA, 250);

        $userB = User::factory()->create([
            'registration_ip' => $origIp,
            'last_login_ip' => '88.88.88.88',
        ]);

        Cache::flush();

        $response = $this->actingAs($userB)->postJsonLocalizedRoute('demo.run', [
            'text' => str_repeat('h', 60), // 250+60 > 300
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('text');
    }

    public function test_small_request_within_remaining_quota_still_allowed(): void
    {
        $this->seedVoice();
        $sharedIp = '10.50.50.50';

        $userA = User::factory()->create(['registration_ip' => $sharedIp]);
        $this->storeDemoUsage($userA, 200);

        $userB = User::factory()->create(['registration_ip' => $sharedIp]);

        Cache::flush();

        $quota = $this->actingAs($userB)->postJsonLocalizedRoute('demo.quota', []);

        $quota->assertOk();
        $quota->assertJsonPath('quota.used_chars', 200);
        $quota->assertJsonPath('quota.remaining_chars', 100);
        $quota->assertJsonPath('quota.is_exhausted', false);
    }

    public function test_many_accounts_same_ip_all_blocked_once_limit_reached(): void
    {
        $this->seedVoice();
        $sharedIp = '10.99.99.99';

        for ($i = 0; $i < 5; $i++) {
            $user = User::factory()->create(['registration_ip' => $sharedIp]);
            $this->storeDemoUsage($user, 60);
        }

        $abuser = User::factory()->create(['registration_ip' => $sharedIp]);

        Cache::flush();

        $quota = $this->actingAs($abuser)->postJsonLocalizedRoute('demo.quota', []);
        $quota->assertOk();
        $quota->assertJsonPath('quota.used_chars', 300);
        $quota->assertJsonPath('quota.remaining_chars', 0);
        $quota->assertJsonPath('quota.is_exhausted', true);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────────

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

    private function storeDemoUsage(User $user, int $billableChars): void
    {
        DemoVoiceGeneration::query()->create([
            'user_id' => $user->id,
            'primary_identity_hash' => 'u:'.$user->id,
            'input_text' => str_repeat('x', max(1, $billableChars)),
            'speech_text' => str_repeat('x', max(1, $billableChars)),
            'source_language' => 'en',
            'target_language' => 'en',
            'translate' => false,
            'voice_name' => 'en-US-Wavenet-C',
            'voice_provider' => 'google_cloud',
            'voice_tier' => 'standard',
            'billable_chars' => $billableChars,
        ]);
    }

    private function enablePhoneSettings(): void
    {
        \App\Models\SiteSetting::set(\App\Services\Auth\AuthSettingsService::KEY_PHONE_ENABLED, '1', \App\Services\Auth\AuthSettingsService::GROUP);
        \App\Models\SiteSetting::set(\App\Services\Auth\AuthSettingsService::KEY_FIREBASE_PHONE_ENABLED, '1', \App\Services\Auth\AuthSettingsService::GROUP);
        \App\Models\SiteSetting::set(\App\Services\Auth\AuthSettingsService::KEY_FIREBASE_API_KEY, 'AIzaSyMockKey', \App\Services\Auth\AuthSettingsService::GROUP);
        \App\Models\SiteSetting::set(\App\Services\Auth\AuthSettingsService::KEY_FIREBASE_AUTH_DOMAIN, 'listen.firebaseapp.com', \App\Services\Auth\AuthSettingsService::GROUP);
        \App\Models\SiteSetting::set(\App\Services\Auth\AuthSettingsService::KEY_FIREBASE_PROJECT_ID, 'listen-project', \App\Services\Auth\AuthSettingsService::GROUP);
        \App\Models\SiteSetting::set(\App\Services\Auth\AuthSettingsService::KEY_FIREBASE_APP_ID, '1:123456789:web:abcdef012345', \App\Services\Auth\AuthSettingsService::GROUP);
    }

    private function enableFirebaseGoogleSettings(): void
    {
        \App\Models\SiteSetting::set(\App\Services\Auth\AuthSettingsService::KEY_FIREBASE_GOOGLE_ENABLED, '1', \App\Services\Auth\AuthSettingsService::GROUP);
        \App\Models\SiteSetting::set(\App\Services\Auth\AuthSettingsService::KEY_FIREBASE_API_KEY, 'AIzaSyMockKey', \App\Services\Auth\AuthSettingsService::GROUP);
        \App\Models\SiteSetting::set(\App\Services\Auth\AuthSettingsService::KEY_FIREBASE_AUTH_DOMAIN, 'listen.firebaseapp.com', \App\Services\Auth\AuthSettingsService::GROUP);
        \App\Models\SiteSetting::set(\App\Services\Auth\AuthSettingsService::KEY_FIREBASE_PROJECT_ID, 'listen-project', \App\Services\Auth\AuthSettingsService::GROUP);
        \App\Models\SiteSetting::set(\App\Services\Auth\AuthSettingsService::KEY_FIREBASE_APP_ID, '1:123456789:web:abcdef012345', \App\Services\Auth\AuthSettingsService::GROUP);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
