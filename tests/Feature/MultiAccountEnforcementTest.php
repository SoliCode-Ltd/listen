<?php

namespace Tests\Feature;

use App\Models\DemoVoiceGeneration;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Voice;
use App\Services\Auth\AuthSettingsService;
use App\Services\Auth\PhoneAuthBridgeService;
use App\Services\MultiAccountEnforcementService;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

/**
 * Tests for the multi-account enforcement system:
 * - Auto-blocking users who exceed max accounts per device (browser cookie)
 * - Admin protection (admins never auto-blocked)
 * - Configurable limit via site settings
 * - VPN bypass detection (current request IP cross-check)
 * - All auth methods covered (email, Google OAuth, Firebase Google, phone)
 */
class MultiAccountEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Auto-blocking is decided by the device cookie only (a shared IP alone is not
     * proof). Scenarios that model "the same person" share this device.
     */
    private const SHARED_DEVICE = 'dev_shared_browser_0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFreePlan();
    }

    // ──────────────────────────────────────────────────────────────────
    //  1.  Auto-blocking on email registration
    // ──────────────────────────────────────────────────────────────────

    public function test_third_account_from_same_device_is_auto_blocked_on_email_registration(): void
    {
        $ip = '10.0.0.1';

        // Create 2 existing accounts (the default limit)
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);

        // Register a 3rd account from the same IP
        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withCookies(['listen_demo_device' => self::SHARED_DEVICE])
            ->postLocalizedRoute('register.submit', [
                'name' => 'Abuser Three',
                'email' => 'abuser3@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'abuser3@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->isBlocked(), 'Third account should be auto-blocked');
        $this->assertNotNull($user->blocked_reason_en);
        $this->assertStringContainsString('multiple accounts', $user->blocked_reason_en);
    }

    public function test_second_account_from_same_device_is_not_blocked(): void
    {
        $ip = '10.0.0.2';

        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withCookies(['listen_demo_device' => self::SHARED_DEVICE])
            ->postLocalizedRoute('register.submit', [
                'name' => 'Second User',
                'email' => 'second@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'second@example.com')->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->isBlocked(), 'Second account should NOT be blocked');
    }

    public function test_third_account_from_same_device_is_auto_blocked_even_with_different_ips(): void
    {
        $sharedDevice = self::SHARED_DEVICE;

        User::factory()->create([
            'registration_ip' => '10.1.0.1',
            'last_login_ip' => '10.1.0.1',
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => $sharedDevice,
        ]);

        User::factory()->create([
            'registration_ip' => '10.1.0.2',
            'last_login_ip' => '10.1.0.2',
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => $sharedDevice,
        ]);

        $abuser = User::factory()->create([
            'registration_ip' => '10.1.0.3',
            'last_login_ip' => '10.1.0.3',
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => $sharedDevice,
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $service->enforceAfterAuth($abuser);

        $abuser->refresh();
        $this->assertTrue($abuser->isBlocked(), 'Third account on the same device should be auto-blocked');
    }

    // ──────────────────────────────────────────────────────────────────
    //  2.  Admin protection — admins are NEVER auto-blocked
    // ──────────────────────────────────────────────────────────────────

    public function test_admin_is_never_auto_blocked(): void
    {
        $ip = '10.0.0.3';

        // Create 2 accounts to meet the limit
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);

        // Create a 3rd account that's an admin
        $admin = User::factory()->create([
            'registration_ip' => $ip,
            'registration_device_id' => self::SHARED_DEVICE,
            'last_login_ip' => $ip,
            'is_admin' => true,
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $service->enforceAfterAuth($admin);

        $admin->refresh();
        $this->assertFalse($admin->isBlocked(), 'Admin should never be auto-blocked');
    }

    public function test_admin_accounts_are_excluded_from_count(): void
    {
        $ip = '10.0.0.4';

        // 1 admin + 1 regular = only 1 non-admin counts
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'is_admin' => true]);
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);

        // Register a 3rd account — only 2 non-admin accounts, so should NOT be blocked
        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withCookies(['listen_demo_device' => self::SHARED_DEVICE])
            ->postLocalizedRoute('register.submit', [
                'name' => 'Not Blocked',
                'email' => 'notblocked@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'notblocked@example.com')->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->isBlocked(), 'Should not be blocked since admin is excluded from count');
    }

    // ──────────────────────────────────────────────────────────────────
    //  3.  Configurable limit via site settings
    // ──────────────────────────────────────────────────────────────────

    public function test_configurable_limit_allows_more_accounts_when_increased(): void
    {
        $ip = '10.0.0.5';
        SiteSetting::set('max_accounts_per_ip', '4', 'general');
        Cache::forget('site_setting.max_accounts_per_ip');

        // Create 3 accounts — under the new limit of 4
        User::factory()->count(3)->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withCookies(['listen_demo_device' => self::SHARED_DEVICE])
            ->postLocalizedRoute('register.submit', [
                'name' => 'Fourth User',
                'email' => 'fourth@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'fourth@example.com')->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->isBlocked(), 'Should not be blocked with limit=4');
    }

    public function test_fifth_account_blocked_when_limit_is_four(): void
    {
        $ip = '10.0.0.6';
        SiteSetting::set('max_accounts_per_ip', '4', 'general');
        Cache::forget('site_setting.max_accounts_per_ip');

        User::factory()->count(4)->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withCookies(['listen_demo_device' => self::SHARED_DEVICE])
            ->postLocalizedRoute('register.submit', [
                'name' => 'Fifth User',
                'email' => 'fifth@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'fifth@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->isBlocked(), 'Fifth account should be blocked with limit=4');
    }

    // ──────────────────────────────────────────────────────────────────
    //  4.  All auth methods trigger enforcement
    // ──────────────────────────────────────────────────────────────────

    public function test_email_login_triggers_enforcement_for_existing_user(): void
    {
        $ip = '10.0.0.7';

        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);

        // Create 3rd user with different IP initially
        $user = User::factory()->create([
            'password' => Hash::make('Password123!'),
            'registration_ip' => '99.99.99.99',
            'last_login_ip' => null,
        ]);

        // Login from the shared IP — stampLoginIp updates last_login_ip, then enforce runs
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withCookies(['listen_demo_device' => self::SHARED_DEVICE])
            ->postLocalizedRoute('login.submit', [
                'email' => $user->email,
                'password' => 'Password123!',
            ]);

        $user->refresh();
        $this->assertTrue($user->isBlocked(), 'User logging in from an IP with 2 existing accounts should be blocked');
    }

    public function test_phone_auth_triggers_enforcement(): void
    {
        $this->enablePhoneSettings();
        $ip = '10.0.0.8';

        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);

        $user = User::factory()->create([
            'firebase_uid' => 'phone-enforce-uid',
            'phone_number' => '+201000000099',
            'phone_verified_at' => now(),
            'registration_ip' => $ip,
            'registration_device_id' => self::SHARED_DEVICE,
            'last_login_ip' => $ip,
        ]);

        $mock = Mockery::mock(PhoneAuthBridgeService::class);
        $mock->shouldReceive('resolveUserFromIdToken')
            ->once()
            ->andReturn($user);
        $this->app->instance(PhoneAuthBridgeService::class, $mock);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withCookies(['listen_demo_device' => self::SHARED_DEVICE])
            ->postJsonLocalizedRoute('auth.phone.verify', [
                'id_token' => 'mock_token',
                'intent' => 'login',
            ]);

        $user->refresh();
        $this->assertTrue($user->isBlocked(), 'Phone auth should trigger multi-account enforcement');
        $response->assertStatus(403);
    }

    public function test_firebase_google_auth_triggers_enforcement(): void
    {
        $this->enableFirebaseGoogleSettings();
        $ip = '10.0.0.9';

        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);

        $user = User::factory()->create([
            'firebase_uid' => 'fb-google-enforce-uid',
            'registration_ip' => $ip,
            'registration_device_id' => self::SHARED_DEVICE,
            'last_login_ip' => $ip,
        ]);

        $mock = Mockery::mock(PhoneAuthBridgeService::class);
        $mock->shouldReceive('resolveUserFromGoogleIdToken')
            ->once()
            ->andReturn($user);
        $this->app->instance(PhoneAuthBridgeService::class, $mock);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withCookies(['listen_demo_device' => self::SHARED_DEVICE])
            ->postJsonLocalizedRoute('auth.firebase-google.verify', [
                'id_token' => 'mock_google_token',
            ]);

        $user->refresh();
        $this->assertTrue($user->isBlocked(), 'Firebase Google auth should trigger enforcement');
        $response->assertStatus(403);
    }

    // ──────────────────────────────────────────────────────────────────
    //  5.  VPN bypass: current request IP catches linked accounts
    // ──────────────────────────────────────────────────────────────────

    public function test_vpn_user_demo_quota_includes_accounts_sharing_request_ip(): void
    {
        $this->seedVoice();
        $homeIp = '10.20.30.40';
        $vpnIp = '99.99.99.99';

        // User A registered from home IP, used 260 chars
        $userA = User::factory()->create([
            'registration_ip' => $homeIp,
            'last_login_ip' => $homeIp,
        ]);
        $this->storeDemoUsage($userA, 260);

        // User B registered with VPN (different IP), no shared IPs at all
        $userB = User::factory()->create([
            'registration_ip' => $vpnIp,
            'last_login_ip' => $vpnIp,
        ]);

        Cache::flush();

        // User B now turns off VPN and uses demo from home IP (same as user A's registration_ip)
        // The requestIpLinkedUserIds should catch this
        $response = $this->actingAs($userB)
            ->withServerVariables(['REMOTE_ADDR' => $homeIp])
            ->postJsonLocalizedRoute('demo.quota', []);

        $response->assertOk();
        // User B should see user A's 260 chars as used
        $response->assertJsonPath('quota.used_chars', 260);
        $response->assertJsonPath('quota.remaining_chars', 40);
    }

    public function test_vpn_user_blocked_when_demo_limit_exceeded_via_request_ip(): void
    {
        $voice = $this->seedVoice();
        $homeIp = '10.20.30.50';
        $vpnIp = '88.88.88.88';

        $userA = User::factory()->create([
            'registration_ip' => $homeIp,
            'last_login_ip' => $homeIp,
        ]);
        $this->storeDemoUsage($userA, 280);

        $userB = User::factory()->create([
            'registration_ip' => $vpnIp,
            'last_login_ip' => $vpnIp,
        ]);

        Cache::flush();

        // User B tries to use demo from home IP — should be denied
        $response = $this->actingAs($userB)
            ->withServerVariables(['REMOTE_ADDR' => $homeIp])
            ->postJsonLocalizedRoute('demo.run', [
                'text' => str_repeat('x', 30), // 280+30 > 300
                'target_language' => 'en',
                'voice_tier' => 'standard',
                'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('text');
    }

    // ──────────────────────────────────────────────────────────────────
    //  6.  Cross-auth-method enforcement
    // ──────────────────────────────────────────────────────────────────

    public function test_email_plus_phone_from_same_device_third_gets_blocked(): void
    {
        $ip = '10.0.0.10';

        User::factory()->create([
            'registration_ip' => $ip,
            'registration_device_id' => self::SHARED_DEVICE,
            'last_login_ip' => $ip,
            'auth_provider' => null, // email user
        ]);
        User::factory()->create([
            'registration_ip' => $ip,
            'registration_device_id' => self::SHARED_DEVICE,
            'last_login_ip' => $ip,
            'auth_provider' => 'phone',
            'firebase_uid' => 'phone-uid-cross',
            'phone_number' => '+201111111111',
        ]);

        // Third account via email
        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withCookies(['listen_demo_device' => self::SHARED_DEVICE])
            ->postLocalizedRoute('register.submit', [
                'name' => 'Third Cross',
                'email' => 'thirdcross@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'thirdcross@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->isBlocked());
    }

    public function test_google_plus_firebase_google_from_same_device_third_gets_blocked(): void
    {
        $ip = '10.0.0.11';

        User::factory()->create([
            'registration_ip' => $ip,
            'registration_device_id' => self::SHARED_DEVICE,
            'last_login_ip' => $ip,
            'google_id' => 'google-id-1',
        ]);
        User::factory()->create([
            'registration_ip' => $ip,
            'registration_device_id' => self::SHARED_DEVICE,
            'last_login_ip' => $ip,
            'auth_provider' => 'firebase_google',
            'firebase_uid' => 'fb-google-uid-1',
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withCookies(['listen_demo_device' => self::SHARED_DEVICE])
            ->postLocalizedRoute('register.submit', [
                'name' => 'Third Google',
                'email' => 'thirdgoogle@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'thirdgoogle@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->isBlocked());
    }

    // ──────────────────────────────────────────────────────────────────
    //  7.  Blocked user is redirected on registration
    // ──────────────────────────────────────────────────────────────────

    public function test_auto_blocked_user_is_redirected_to_login_with_notice(): void
    {
        $ip = '10.0.0.12';

        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withCookies(['listen_demo_device' => self::SHARED_DEVICE])
            ->postLocalizedRoute('register.submit', [
                'name' => 'Blocked Redirect',
                'email' => 'blockedredirect@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        // Should redirect (to login) and have the blocked notice
        $response->assertRedirect();
        $response->assertSessionHas('blocked_notice');
    }

    public function test_auto_blocked_user_is_logged_out_on_email_login(): void
    {
        $ip = '10.0.0.13';

        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);

        // 3rd user has a different registration_ip, but will login from the shared IP
        $user = User::factory()->create([
            'password' => Hash::make('Password123!'),
            'registration_ip' => '77.77.77.77',
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withCookies(['listen_demo_device' => self::SHARED_DEVICE])
            ->postLocalizedRoute('login.submit', [
                'email' => $user->email,
                'password' => 'Password123!',
            ]);

        $this->assertGuest();
        $response->assertSessionHas('blocked_notice');
    }

    // ──────────────────────────────────────────────────────────────────
    //  8.  Service unit tests
    // ──────────────────────────────────────────────────────────────────

    public function test_service_does_not_double_block(): void
    {
        $ip = '10.0.0.14';

        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);

        $user = User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);

        $service = app(MultiAccountEnforcementService::class);
        $service->enforceAfterAuth($user);

        $user->refresh();
        $this->assertTrue($user->isBlocked());

        // Call again — should not throw
        $service->enforceAfterAuth($user);
        $user->refresh();
        $this->assertTrue($user->isBlocked());
    }

    public function test_backfill_command_blocks_existing_users_exceeding_linked_limit(): void
    {
        SiteSetting::set('max_accounts_per_ip', '2', 'general');
        Cache::forget('site_setting.max_accounts_per_ip');

        $sharedDevice = self::SHARED_DEVICE;

        $u1 = User::factory()->create([
            'registration_ip' => '172.16.10.1',
            'last_login_ip' => '172.16.10.1',
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => $sharedDevice,
        ]);
        $u2 = User::factory()->create([
            'registration_ip' => '172.16.10.2',
            'last_login_ip' => '172.16.10.2',
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => $sharedDevice,
        ]);
        $u3 = User::factory()->create([
            'registration_ip' => '172.16.10.3',
            'last_login_ip' => '172.16.10.3',
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => $sharedDevice,
        ]);

        $this->artisan('listen:multi-account-backfill --dry-run')
            ->assertExitCode(0);

        $this->assertSame(0, User::query()->whereIn('id', [$u1->id, $u2->id, $u3->id])->where('is_blocked', true)->count());

        $this->artisan('listen:multi-account-backfill')
            ->assertExitCode(0);

        $blockedCount = User::query()
            ->whereIn('id', [$u1->id, $u2->id, $u3->id])
            ->where('is_blocked', true)
            ->count();

        $this->assertSame(3, $blockedCount);
    }

    public function test_service_skips_user_with_no_ips(): void
    {
        $user = User::factory()->create([
            'registration_ip' => null,
            'last_login_ip' => null,
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $service->enforceAfterAuth($user);

        $user->refresh();
        $this->assertFalse($user->isBlocked());
    }

    public function test_default_max_accounts_is_two(): void
    {
        $service = app(MultiAccountEnforcementService::class);
        $this->assertSame(2, $service->maxAccountsPerIp());
    }

    // ──────────────────────────────────────────────────────────────────
    //  8b. Unblocked user is not re-blocked on next login
    // ──────────────────────────────────────────────────────────────────

    public function test_unblocked_user_is_not_re_blocked_on_next_login(): void
    {
        $ip = '10.0.0.20';

        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);

        // 3rd user gets auto-blocked
        $user = User::factory()->create([
            'registration_ip' => $ip,
            'registration_device_id' => self::SHARED_DEVICE,
            'last_login_ip' => $ip,
            'password' => Hash::make('Password123!'),
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $service->enforceAfterAuth($user);
        $user->refresh();
        $this->assertTrue($user->isBlocked());

        // Admin unblocks the user
        app(\App\Services\BlockedUserService::class)->unblock($user);
        $user->refresh();
        $this->assertFalse($user->isBlocked());
        $this->assertTrue((bool) $user->multi_account_whitelisted);

        // User logs in again — should NOT be re-blocked
        $service->enforceAfterAuth($user);
        $user->refresh();
        $this->assertFalse($user->isBlocked());
    }

    public function test_whitelisted_user_can_login_via_email(): void
    {
        $ip = '10.0.0.21';

        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);

        $user = User::factory()->create([
            'registration_ip' => $ip,
            'registration_device_id' => self::SHARED_DEVICE,
            'last_login_ip' => $ip,
            'password' => Hash::make('Password123!'),
            'multi_account_whitelisted' => true,
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withCookies(['listen_demo_device' => self::SHARED_DEVICE])
            ->postLocalizedRoute('login.submit', [
                'email' => $user->email,
                'password' => 'Password123!',
            ]);

        $this->assertAuthenticated();
        $user->refresh();
        $this->assertFalse($user->isBlocked());
    }

    // ──────────────────────────────────────────────────────────────────
    //  9.  Different IPs should not trigger enforcement
    // ──────────────────────────────────────────────────────────────────

    public function test_accounts_from_different_ips_are_not_blocked(): void
    {
        User::factory()->create(['registration_ip' => '10.0.0.1', 'last_login_ip' => '10.0.0.1']);
        User::factory()->create(['registration_ip' => '10.0.0.2', 'last_login_ip' => '10.0.0.2']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])
            ->postLocalizedRoute('register.submit', [
                'name' => 'Different IP',
                'email' => 'diffip@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'diffip@example.com')->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->isBlocked());
    }

    // ──────────────────────────────────────────────────────────────────
    //  10.  Blocking reason is correct
    // ──────────────────────────────────────────────────────────────────

    public function test_block_reason_explains_multi_account_abuse(): void
    {
        $ip = '10.0.0.15';

        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => self::SHARED_DEVICE]);

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withCookies(['listen_demo_device' => self::SHARED_DEVICE])
            ->postLocalizedRoute('register.submit', [
                'name' => 'Reason Check',
                'email' => 'reasoncheck@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'reasoncheck@example.com')->first();
        $this->assertTrue($user->isBlocked());
        $this->assertStringContainsString('multiple accounts', $user->blocked_reason_en);
        $this->assertStringContainsString('حسابات متعددة', $user->blocked_reason_ar);
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
            'primary_identity_hash' => 'u:' . $user->id,
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
        SiteSetting::set(AuthSettingsService::KEY_PHONE_ENABLED, '1', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_PHONE_ENABLED, '1', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_API_KEY, 'AIzaSyMockKey', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_AUTH_DOMAIN, 'listen.firebaseapp.com', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_PROJECT_ID, 'listen-project', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_APP_ID, '1:123456789:web:abcdef012345', AuthSettingsService::GROUP);
    }

    private function enableFirebaseGoogleSettings(): void
    {
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_GOOGLE_ENABLED, '1', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_API_KEY, 'AIzaSyMockKey', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_AUTH_DOMAIN, 'listen.firebaseapp.com', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_PROJECT_ID, 'listen-project', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_APP_ID, '1:123456789:web:abcdef012345', AuthSettingsService::GROUP);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
