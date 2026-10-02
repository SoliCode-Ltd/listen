<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureUserIsNotBlocked;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Auth\AuthSettingsService;
use App\Services\Auth\PhoneAuthBridgeService;
use App\Services\BlockedUserService;
use App\Services\MultiAccountEnforcementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

/**
 * Comprehensive test suite covering the full suspicious-users pipeline:
 *
 * - Device ID stamping on every auth path
 * - BlockedUserService (block / unblock / localized reasons / fallback chain)
 * - Multi-account enforcement (auto-blocks on shared device cookie only; IPs are
 *   shown to admins but never block on their own)
 * - EnsureUserIsNotBlocked middleware (JSON + web)
 * - Edge cases and combined scenarios
 */
class SuspiciousUsersComprehensiveTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE_COOKIE = 'listen_demo_device';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFreePlan();
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 1: Device ID stamping during auth flows
    // ══════════════════════════════════════════════════════════════════════

    public function test_email_registration_stamps_device_id_from_cookie(): void
    {
        $deviceId = 'dev_abc123456789012345678901';

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withCookies([self::DEVICE_COOKIE => $deviceId])
            ->postLocalizedRoute('register.submit', [
                'name' => 'Device User',
                'email' => 'device-reg@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'device-reg@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame($deviceId, $user->registration_device_id);
        $this->assertSame($deviceId, $user->last_seen_device_id);
    }

    public function test_email_registration_without_device_cookie_stores_null(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postLocalizedRoute('register.submit', [
                'name' => 'No Device User',
                'email' => 'no-device@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'no-device@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->registration_device_id);
        $this->assertNull($user->last_seen_device_id);
    }

    public function test_email_login_updates_last_seen_device_id(): void
    {
        $originalDevice = 'dev_original_device_id_012345678';
        $newDevice = 'dev_new_device_id_0123456789ab';

        $user = User::factory()->create([
            'password' => Hash::make('Password123!'),
            'registration_device_id' => $originalDevice,
            'last_seen_device_id' => $originalDevice,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withCookies([self::DEVICE_COOKIE => $newDevice])
            ->postLocalizedRoute('login.submit', [
                'email' => $user->email,
                'password' => 'Password123!',
            ]);

        $user->refresh();
        $this->assertSame($originalDevice, $user->registration_device_id, 'registration_device_id must not change on login');
        $this->assertSame($newDevice, $user->last_seen_device_id, 'last_seen_device_id must update to new device');
    }

    public function test_email_login_backfills_registration_device_id_when_null(): void
    {
        $deviceId = 'dev_backfill_device_0123456789ab';

        $user = User::factory()->create([
            'password' => Hash::make('Password123!'),
            'registration_device_id' => null,
            'last_seen_device_id' => null,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withCookies([self::DEVICE_COOKIE => $deviceId])
            ->postLocalizedRoute('login.submit', [
                'email' => $user->email,
                'password' => 'Password123!',
            ]);

        $user->refresh();
        $this->assertSame($deviceId, $user->registration_device_id);
        $this->assertSame($deviceId, $user->last_seen_device_id);
    }

    public function test_invalid_device_id_format_is_ignored(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withCookies([self::DEVICE_COOKIE => 'short']) // too short, < 24 chars
            ->postLocalizedRoute('register.submit', [
                'name' => 'Invalid Device',
                'email' => 'invalid-device@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'invalid-device@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->registration_device_id);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 2: Request signature stamping during auth flows
    // ══════════════════════════════════════════════════════════════════════

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 3: BlockedUserService — block / unblock / reasons
    // ══════════════════════════════════════════════════════════════════════

    public function test_block_sets_all_blocking_fields(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $service = app(BlockedUserService::class);

        $service->block($user, null, $admin, ['en' => 'Custom EN', 'ar' => 'Custom AR']);

        $user->refresh();
        $this->assertTrue($user->isBlocked());
        $this->assertNotNull($user->blocked_at);
        $this->assertSame('Custom EN', $user->blocked_reason_en);
        $this->assertSame('Custom AR', $user->blocked_reason_ar);
        $this->assertSame($admin->id, $user->blocked_by);
        $this->assertFalse((bool) $user->multi_account_whitelisted);
    }

    public function test_block_uses_default_reasons_when_none_provided(): void
    {
        $user = User::factory()->create();
        $service = app(BlockedUserService::class);

        $service->block($user);

        $user->refresh();
        $this->assertTrue($user->isBlocked());
        $this->assertSame(BlockedUserService::DEFAULT_REASON_EN, $user->blocked_reason_en);
        $this->assertSame(BlockedUserService::DEFAULT_REASON_AR, $user->blocked_reason_ar);
    }

    public function test_block_detects_arabic_fallback_reason(): void
    {
        $user = User::factory()->create();
        $service = app(BlockedUserService::class);

        $arabicReason = 'تم الحظر بسبب سوء الاستخدام';
        $service->block($user, $arabicReason);

        $user->refresh();
        $this->assertSame($arabicReason, $user->blocked_reason_ar);
        $this->assertSame(BlockedUserService::DEFAULT_REASON_EN, $user->blocked_reason_en);
    }

    public function test_block_detects_english_fallback_reason(): void
    {
        $user = User::factory()->create();
        $service = app(BlockedUserService::class);

        $service->block($user, 'Blocked for abuse');

        $user->refresh();
        $this->assertSame('Blocked for abuse', $user->blocked_reason_en);
        $this->assertSame(BlockedUserService::DEFAULT_REASON_AR, $user->blocked_reason_ar);
    }

    public function test_block_invalidates_remember_token(): void
    {
        $user = User::factory()->create(['remember_token' => 'old-token-value']);
        $service = app(BlockedUserService::class);

        $service->block($user, 'test');

        $user->refresh();
        $this->assertNotSame('old-token-value', $user->remember_token);
    }

    public function test_unblock_clears_all_blocking_fields_and_whitelists(): void
    {
        $user = User::factory()->create([
            'is_blocked' => true,
            'blocked_at' => now(),
            'blocked_reason' => 'test',
            'blocked_reason_ar' => 'سبب',
            'blocked_reason_en' => 'reason',
            'blocked_by' => 1,
        ]);

        $service = app(BlockedUserService::class);
        $service->unblock($user);

        $user->refresh();
        $this->assertFalse($user->isBlocked());
        $this->assertNull($user->blocked_at);
        $this->assertNull($user->blocked_reason);
        $this->assertNull($user->blocked_reason_ar);
        $this->assertNull($user->blocked_reason_en);
        $this->assertNull($user->blocked_by);
        $this->assertTrue((bool) $user->multi_account_whitelisted);
    }

    public function test_is_blocked_returns_false_for_null_user(): void
    {
        $service = app(BlockedUserService::class);
        $this->assertFalse($service->isBlocked(null));
    }

    public function test_message_for_uses_locale_ar_fallback_chain(): void
    {
        $user = User::factory()->create([
            'is_blocked' => true,
            'blocked_reason_ar' => 'سبب عربي',
            'blocked_reason_en' => null,
            'blocked_reason' => null,
        ]);

        $service = app(BlockedUserService::class);
        $originalLocale = App::currentLocale();

        App::setLocale('ar');
        $message = $service->messageFor($user);
        $this->assertStringContainsString('سبب عربي', $message);

        App::setLocale($originalLocale);
    }

    public function test_message_for_uses_locale_en_fallback_chain(): void
    {
        $user = User::factory()->create([
            'is_blocked' => true,
            'blocked_reason_ar' => null,
            'blocked_reason_en' => 'English reason only',
            'blocked_reason' => null,
        ]);

        $service = app(BlockedUserService::class);
        $originalLocale = App::currentLocale();

        App::setLocale('en');
        $message = $service->messageFor($user);
        $this->assertStringContainsString('English reason only', $message);

        App::setLocale($originalLocale);
    }

    public function test_message_for_falls_back_to_legacy_blocked_reason(): void
    {
        $user = User::factory()->create([
            'is_blocked' => true,
            'blocked_reason_ar' => null,
            'blocked_reason_en' => null,
            'blocked_reason' => 'Legacy reason',
        ]);

        $service = app(BlockedUserService::class);
        App::setLocale('en');
        $message = $service->messageFor($user);
        $this->assertStringContainsString('Legacy reason', $message);
    }

    public function test_message_for_uses_default_when_all_reasons_null(): void
    {
        $user = User::factory()->create([
            'is_blocked' => true,
            'blocked_reason_ar' => null,
            'blocked_reason_en' => null,
            'blocked_reason' => null,
        ]);

        $service = app(BlockedUserService::class);

        App::setLocale('en');
        $message = $service->messageFor($user);
        $this->assertStringContainsString(BlockedUserService::DEFAULT_REASON_EN, $message);

        App::setLocale('ar');
        $message = $service->messageFor($user);
        $this->assertStringContainsString(BlockedUserService::DEFAULT_REASON_AR, $message);
    }

    public function test_notice_payload_structure(): void
    {
        $user = User::factory()->create([
            'is_blocked' => true,
            'blocked_reason_en' => 'Some reason',
        ]);

        $service = app(BlockedUserService::class);
        $payload = $service->noticePayload($user);

        $this->assertArrayHasKey('title', $payload);
        $this->assertArrayHasKey('body', $payload);
        $this->assertArrayHasKey('reason', $payload);
        $this->assertArrayHasKey('email', $payload);
        $this->assertSame('help@listenai.studio', $payload['email']);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 5: Device ID-based multi-account enforcement
    // ══════════════════════════════════════════════════════════════════════

    public function test_third_account_same_device_id_different_ips_auto_blocked(): void
    {
        $sharedDevice = 'dev_shared_device_abc0123456789';

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
        $this->assertTrue($abuser->isBlocked(), 'Third device_id account should be auto-blocked');
    }

    public function test_two_accounts_same_device_different_ips_not_blocked(): void
    {
        $sharedDevice = 'dev_two_accounts_device_012345';

        User::factory()->create([
            'registration_ip' => '10.2.0.1',
            'last_login_ip' => '10.2.0.1',
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => $sharedDevice,
        ]);

        $second = User::factory()->create([
            'registration_ip' => '10.2.0.2',
            'last_login_ip' => '10.2.0.2',
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => $sharedDevice,
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $service->enforceAfterAuth($second);

        $second->refresh();
        $this->assertFalse($second->isBlocked(), 'Two accounts with same device should not be blocked');
    }

    public function test_different_devices_same_ip_third_account_not_auto_blocked(): void
    {
        // Auto-blocking uses only the device cookie: a shared IP (mobile carriers,
        // offices, universities) is shown to admins but is not proof on its own.
        $ip = '10.3.0.1';

        User::factory()->create([
            'registration_ip' => $ip,
            'last_login_ip' => $ip,
            'registration_device_id' => 'dev_device_a_0123456789abcdef',
            'last_seen_device_id' => 'dev_device_a_0123456789abcdef',
        ]);
        User::factory()->create([
            'registration_ip' => $ip,
            'last_login_ip' => $ip,
            'registration_device_id' => 'dev_device_b_0123456789abcdef',
            'last_seen_device_id' => 'dev_device_b_0123456789abcdef',
        ]);

        $third = User::factory()->create([
            'registration_ip' => $ip,
            'last_login_ip' => $ip,
            'registration_device_id' => 'dev_device_c_0123456789abcdef',
            'last_seen_device_id' => 'dev_device_c_0123456789abcdef',
        ]);

        app(MultiAccountEnforcementService::class)->enforceAfterAuth($third);

        $this->assertFalse($third->fresh()->isBlocked(), 'A shared IP alone must not auto-block');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 6: Cross-signal enforcement (IP + device + signature combos)
    // ══════════════════════════════════════════════════════════════════════

    public function test_cross_column_device_id_matching_triggers_enforcement(): void
    {
        $sharedDevice = 'dev_cross_column_device_01234567';

        // User A has it as registration_device_id
        User::factory()->create([
            'registration_ip' => '10.4.0.1',
            'last_login_ip' => '10.4.0.1',
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => null,
        ]);
        // User B has it as last_seen_device_id
        User::factory()->create([
            'registration_ip' => '10.4.0.2',
            'last_login_ip' => '10.4.0.2',
            'registration_device_id' => null,
            'last_seen_device_id' => $sharedDevice,
        ]);

        // User C also has it
        $abuser = User::factory()->create([
            'registration_ip' => '10.4.0.3',
            'last_login_ip' => '10.4.0.3',
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => $sharedDevice,
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $service->enforceAfterAuth($abuser);

        $abuser->refresh();
        $this->assertTrue($abuser->isBlocked(), 'Cross-column device_id match should trigger blocking');
    }

    public function test_shared_ip_does_not_count_towards_the_device_limit(): void
    {
        $sharedIp = '10.6.0.1';
        $sharedDevice = 'dev_mixed_signal_device_01234567';

        // User A shares only the IP.
        User::factory()->create([
            'registration_ip' => $sharedIp,
            'last_login_ip' => $sharedIp,
            'registration_device_id' => 'dev_unrelated_device_a_01234567',
        ]);

        // User B shares only the device.
        User::factory()->create([
            'registration_ip' => '10.6.0.2',
            'last_login_ip' => '10.6.0.2',
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => $sharedDevice,
        ]);

        // User C: two accounts on the device (within the limit of 2) plus one on the IP.
        $userC = User::factory()->create([
            'registration_ip' => $sharedIp,
            'last_login_ip' => $sharedIp,
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => $sharedDevice,
        ]);

        app(MultiAccountEnforcementService::class)->enforceAfterAuth($userC);
        $this->assertFalse($userC->fresh()->isBlocked(), 'IP matches must not push the device count over the limit');

        // A third account on the same device is over the limit.
        $userD = User::factory()->create([
            'registration_ip' => '10.6.0.9',
            'last_login_ip' => '10.6.0.9',
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => $sharedDevice,
        ]);
        app(MultiAccountEnforcementService::class)->enforceAfterAuth($userD);
        $this->assertTrue($userD->fresh()->isBlocked());
    }

    public function test_no_signals_at_all_does_not_block(): void
    {
        $user = User::factory()->create([
            'registration_ip' => null,
            'last_login_ip' => null,
            'registration_device_id' => null,
            'last_seen_device_id' => null,
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $service->enforceAfterAuth($user);

        $user->refresh();
        $this->assertFalse($user->isBlocked());
    }

    public function test_linked_signals_returns_correct_structure(): void
    {
        $user = User::factory()->create([
            'registration_ip' => '10.7.0.1',
            'last_login_ip' => '10.7.0.2',
            'registration_device_id' => 'dev_signals_test_device_01234567',
            'last_seen_device_id' => 'dev_signals_test_new_device_0123',
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $signals = $service->linkedSignals($user);

        $this->assertArrayHasKey('ips', $signals);
        $this->assertArrayHasKey('device_ids', $signals);
        $this->assertArrayNotHasKey('request_signatures', $signals);

        $this->assertCount(2, $signals['ips']);
        $this->assertContains('10.7.0.1', $signals['ips']);
        $this->assertContains('10.7.0.2', $signals['ips']);

        $this->assertCount(2, $signals['device_ids']);
    }

    public function test_linked_signals_deduplicates_same_values(): void
    {
        $sameIp = '10.8.0.1';
        $sameDevice = 'dev_same_device_id_0123456789ab';
        $sameSig = hash('sha256', 'same-sig');

        $user = User::factory()->create([
            'registration_ip' => $sameIp,
            'last_login_ip' => $sameIp,
            'registration_device_id' => $sameDevice,
            'last_seen_device_id' => $sameDevice,
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $signals = $service->linkedSignals($user);

        $this->assertCount(1, $signals['ips']);
        $this->assertCount(1, $signals['device_ids']);
    }

    public function test_linked_account_count_includes_ip_and_device_links(): void
    {
        $sharedIp = '10.9.0.1';
        $sharedDevice = 'dev_count_test_device_0123456789';

        // User A: shares IP only
        User::factory()->create([
            'registration_ip' => $sharedIp,
            'last_login_ip' => $sharedIp,
        ]);

        // User B: shares device_id only (different IP)
        User::factory()->create([
            'registration_ip' => '10.9.0.2',
            'last_login_ip' => '10.9.0.2',
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => $sharedDevice,
        ]);

        // Unrelated user
        User::factory()->create([
            'registration_ip' => '10.9.0.3',
            'last_login_ip' => '10.9.0.3',
        ]);

        $target = User::factory()->create([
            'registration_ip' => $sharedIp,
            'last_login_ip' => $sharedIp,
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => $sharedDevice,
        ]);

        // Admin display counts IP and device links: A, B and the target itself.
        $this->assertSame(3, app(MultiAccountEnforcementService::class)->linkedAccountCount($target));
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 7: EnsureUserIsNotBlocked middleware
    // ══════════════════════════════════════════════════════════════════════

    public function test_middleware_allows_non_blocked_user_through(): void
    {
        $user = User::factory()->create(['is_blocked' => false]);

        $response = $this->actingAs($user)
            ->getLocalizedRoute('dashboard');

        $response->assertStatus(200);
    }

    public function test_middleware_returns_403_for_blocked_api_user(): void
    {
        $user = User::factory()->create();
        $apiKey = $user->issueApiKey();

        app(BlockedUserService::class)->block($user, 'API abuse');

        $response = $this->getJson('/api/reader/profile?api_key=' . $apiKey);

        $response->assertStatus(403);
        $response->assertSee(__('ui.backend.account_blocked_with_reason', [
            'reason' => 'API abuse',
            'email' => 'help@listenai.studio',
        ]));
    }

    public function test_middleware_redirects_blocked_web_user_with_notice(): void
    {
        $user = User::factory()->create([
            'is_blocked' => true,
            'blocked_at' => now(),
            'blocked_reason_en' => 'Test reason',
        ]);

        $response = $this->actingAs($user)->getLocalizedRoute('dashboard');

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('blocked_notice');
        $this->assertGuest();
    }

    public function test_middleware_invalidates_session_for_blocked_user(): void
    {
        $user = User::factory()->create([
            'is_blocked' => true,
            'blocked_at' => now(),
        ]);

        $this->actingAs($user)->getLocalizedRoute('dashboard');

        $this->assertGuest();
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 8: Admin panel access — blocked admin
    // ══════════════════════════════════════════════════════════════════════

    public function test_blocked_admin_cannot_access_panel(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_blocked' => true,
            'blocked_at' => now(),
        ]);

        $panel = Mockery::mock(\Filament\Panel::class);
        $this->assertFalse($admin->canAccessPanel($panel));
    }

    public function test_non_blocked_admin_can_access_panel(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_blocked' => false,
        ]);

        $panel = Mockery::mock(\Filament\Panel::class);
        $this->assertTrue($admin->canAccessPanel($panel));
    }

    public function test_non_admin_cannot_access_panel(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'is_blocked' => false,
        ]);

        $panel = Mockery::mock(\Filament\Panel::class);
        $this->assertFalse($user->canAccessPanel($panel));
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 9: Whitelist and re-blocking prevention
    // ══════════════════════════════════════════════════════════════════════

    public function test_whitelisted_user_skipped_by_enforcement_even_with_many_linked(): void
    {
        $ip = '10.10.0.1';
        User::factory()->count(5)->create(['registration_ip' => $ip, 'last_login_ip' => $ip]);

        $whitelisted = User::factory()->create([
            'registration_ip' => $ip,
            'last_login_ip' => $ip,
            'multi_account_whitelisted' => true,
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $service->enforceAfterAuth($whitelisted);

        $whitelisted->refresh();
        $this->assertFalse($whitelisted->isBlocked());
    }

    public function test_already_blocked_user_not_re_processed(): void
    {
        $ip = '10.11.0.1';
        User::factory()->count(3)->create(['registration_ip' => $ip, 'last_login_ip' => $ip]);

        $blocked = User::factory()->create([
            'registration_ip' => $ip,
            'last_login_ip' => $ip,
            'is_blocked' => true,
            'blocked_at' => now(),
            'blocked_reason_en' => 'Already blocked',
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $service->enforceAfterAuth($blocked);

        $blocked->refresh();
        // Should still have original reason, not overwritten
        $this->assertSame('Already blocked', $blocked->blocked_reason_en);
    }

    public function test_full_block_unblock_re_login_lifecycle(): void
    {
        $ip = '10.12.0.1';

        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => 'dev_lifecycle_shared_device_0123']);
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => 'dev_lifecycle_shared_device_0123']);

        // 3rd user gets auto-blocked
        $user = User::factory()->create([
            'registration_ip' => $ip,
            'last_login_ip' => $ip,
            'registration_device_id' => 'dev_lifecycle_shared_device_0123',
            'password' => Hash::make('Password123!'),
        ]);

        $enforcement = app(MultiAccountEnforcementService::class);
        $blocker = app(BlockedUserService::class);

        $enforcement->enforceAfterAuth($user);
        $user->refresh();
        $this->assertTrue($user->isBlocked());

        // Admin unblocks
        $blocker->unblock($user);
        $user->refresh();
        $this->assertFalse($user->isBlocked());
        $this->assertTrue((bool) $user->multi_account_whitelisted);

        // User logs in again — enforcement must skip whitelisted user
        $enforcement->enforceAfterAuth($user);
        $user->refresh();
        $this->assertFalse($user->isBlocked(), 'Whitelisted user must NOT be re-blocked');

        // Now if admin manually blocks again, whitelist is cleared
        $blocker->block($user, 'Manual re-block');
        $user->refresh();
        $this->assertTrue($user->isBlocked());
        $this->assertFalse((bool) $user->multi_account_whitelisted);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 10: Configurable block reasons via site settings
    // ══════════════════════════════════════════════════════════════════════

    public function test_custom_block_reasons_from_site_settings(): void
    {
        SiteSetting::set(MultiAccountEnforcementService::BLOCK_REASON_EN_SETTING_KEY, 'Custom EN block reason', 'general');
        SiteSetting::set(MultiAccountEnforcementService::BLOCK_REASON_AR_SETTING_KEY, 'سبب حظر مخصص', 'general');
        Cache::forget('site_setting.' . MultiAccountEnforcementService::BLOCK_REASON_EN_SETTING_KEY);
        Cache::forget('site_setting.' . MultiAccountEnforcementService::BLOCK_REASON_AR_SETTING_KEY);

        $ip = '10.13.0.1';
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => 'dev_custom_reason_device_0123456']);
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => 'dev_custom_reason_device_0123456']);

        $abuser = User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => 'dev_custom_reason_device_0123456']);

        $service = app(MultiAccountEnforcementService::class);
        $service->enforceAfterAuth($abuser);

        $abuser->refresh();
        $this->assertTrue($abuser->isBlocked());
        $this->assertSame('Custom EN block reason', $abuser->blocked_reason_en);
        $this->assertSame('سبب حظر مخصص', $abuser->blocked_reason_ar);
    }

    public function test_empty_site_setting_reason_falls_back_to_default(): void
    {
        SiteSetting::set(MultiAccountEnforcementService::BLOCK_REASON_EN_SETTING_KEY, '', 'general');
        Cache::forget('site_setting.' . MultiAccountEnforcementService::BLOCK_REASON_EN_SETTING_KEY);

        $service = app(MultiAccountEnforcementService::class);
        $this->assertSame(MultiAccountEnforcementService::BLOCK_REASON_EN, $service->blockReasonEn());
    }

    public function test_max_accounts_per_ip_never_below_one(): void
    {
        SiteSetting::set('max_accounts_per_ip', '0', 'general');
        Cache::forget('site_setting.max_accounts_per_ip');

        $service = app(MultiAccountEnforcementService::class);
        $this->assertSame(1, $service->maxAccountsPerIp());
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 11: Backfill command edge cases
    // ══════════════════════════════════════════════════════════════════════

    public function test_backfill_dry_run_does_not_block_anyone(): void
    {
        $ip = '10.14.0.1';
        User::factory()->count(3)->create(['registration_ip' => $ip, 'last_login_ip' => $ip]);

        $this->artisan('listen:multi-account-backfill --dry-run')
            ->assertExitCode(0);

        $blockedCount = User::where('registration_ip', $ip)->where('is_blocked', true)->count();
        $this->assertSame(0, $blockedCount);
    }

    public function test_backfill_skips_whitelisted_users(): void
    {
        $ip = '10.15.0.1';
        User::factory()->count(3)->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'multi_account_whitelisted' => true]);

        $this->artisan('listen:multi-account-backfill')
            ->assertExitCode(0);

        $blockedCount = User::where('registration_ip', $ip)->where('is_blocked', true)->count();
        $this->assertSame(0, $blockedCount);
    }

    public function test_backfill_skips_admins(): void
    {
        $ip = '10.16.0.1';
        User::factory()->count(3)->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'is_admin' => true]);

        $this->artisan('listen:multi-account-backfill')
            ->assertExitCode(0);

        $blockedCount = User::where('registration_ip', $ip)->where('is_blocked', true)->count();
        $this->assertSame(0, $blockedCount);
    }

    public function test_backfill_with_device_id_signals(): void
    {
        $sharedDevice = 'dev_backfill_device_test_01234567';

        User::factory()->create([
            'registration_ip' => '10.17.0.1',
            'last_login_ip' => '10.17.0.1',
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => $sharedDevice,
        ]);
        User::factory()->create([
            'registration_ip' => '10.17.0.2',
            'last_login_ip' => '10.17.0.2',
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => $sharedDevice,
        ]);
        $u3 = User::factory()->create([
            'registration_ip' => '10.17.0.3',
            'last_login_ip' => '10.17.0.3',
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => $sharedDevice,
        ]);

        $this->artisan('listen:multi-account-backfill')
            ->assertExitCode(0);

        // All 3 should be blocked since each one's linked count exceeds max (2)
        $blockedCount = User::where('registration_device_id', $sharedDevice)->where('is_blocked', true)->count();
        $this->assertSame(3, $blockedCount);
    }

    public function test_backfill_reports_zero_when_no_eligible_users(): void
    {
        // No users at all
        $this->artisan('listen:multi-account-backfill')
            ->expectsOutput('No eligible users found for backfill.')
            ->assertExitCode(0);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 12: Blocked user login behavior across auth methods
    // ══════════════════════════════════════════════════════════════════════

    public function test_blocked_user_cannot_login_via_email(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123!'),
            'is_blocked' => true,
            'blocked_at' => now(),
            'blocked_reason_en' => 'Blocked for test',
        ]);

        $response = $this->postLocalizedRoute('login.submit', [
            'email' => $user->email,
            'password' => 'Password123!',
        ]);

        $this->assertGuest();
        $response->assertSessionHas('blocked_notice');
    }

    public function test_blocked_user_phone_auth_returns_403(): void
    {
        $this->enablePhoneSettings();

        $user = User::factory()->create([
            'firebase_uid' => 'blocked-phone-uid',
            'phone_number' => '+201000000077',
            'phone_verified_at' => now(),
            'is_blocked' => true,
            'blocked_at' => now(),
            'blocked_reason_en' => 'blocked',
        ]);

        $mock = Mockery::mock(PhoneAuthBridgeService::class);
        $mock->shouldReceive('resolveUserFromIdToken')
            ->once()
            ->andReturn($user);
        $this->app->instance(PhoneAuthBridgeService::class, $mock);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postJsonLocalizedRoute('auth.phone.verify', [
                'id_token' => 'mock_token',
                'intent' => 'login',
            ]);

        $response->assertStatus(403);
    }

    public function test_blocked_user_firebase_google_auth_returns_403(): void
    {
        $this->enableFirebaseGoogleSettings();

        $user = User::factory()->create([
            'firebase_uid' => 'blocked-fb-google-uid',
            'is_blocked' => true,
            'blocked_at' => now(),
            'blocked_reason_en' => 'blocked',
        ]);

        $mock = Mockery::mock(PhoneAuthBridgeService::class);
        $mock->shouldReceive('resolveUserFromGoogleIdToken')
            ->once()
            ->andReturn($user);
        $this->app->instance(PhoneAuthBridgeService::class, $mock);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postJsonLocalizedRoute('auth.firebase-google.verify', [
                'id_token' => 'mock_google_token',
            ]);

        $response->assertStatus(403);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 13: Registration flow device + signature combined
    // ══════════════════════════════════════════════════════════════════════

    public function test_registration_captures_device_and_ip(): void
    {
        $deviceId = 'dev_combined_reg_test_0123456789';
        $headers = $this->chromeHeaders();

        $this->withServerVariables(['REMOTE_ADDR' => '10.18.0.1'])
            ->withCookies([self::DEVICE_COOKIE => $deviceId])
            ->withHeaders($headers)
            ->postLocalizedRoute('register.submit', [
                'name' => 'Combined User',
                'email' => 'combined@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'combined@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame($deviceId, $user->registration_device_id);
        $this->assertSame($deviceId, $user->last_seen_device_id);
        $this->assertSame('10.18.0.1', $user->registration_ip);
    }

    public function test_third_account_auto_blocked_on_registration_with_device_cookie(): void
    {
        $ip = '10.19.0.1';
        $deviceId = 'dev_auto_block_reg_test_01234567';

        User::factory()->create([
            'registration_ip' => $ip,
            'last_login_ip' => $ip,
            'registration_device_id' => $deviceId,
        ]);
        User::factory()->create([
            'registration_ip' => $ip,
            'last_login_ip' => $ip,
            'registration_device_id' => $deviceId,
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withCookies([self::DEVICE_COOKIE => $deviceId])
            ->postLocalizedRoute('register.submit', [
                'name' => 'Blocked Reg',
                'email' => 'blocked-reg-device@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'blocked-reg-device@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->isBlocked());
        $response->assertSessionHas('blocked_notice');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 14: Suspicious users count cache invalidation
    // ══════════════════════════════════════════════════════════════════════

    public function test_blocking_invalidates_suspicious_users_count_cache(): void
    {
        Cache::put('admin:suspicious_users_count', 5, 600);

        $ip = '10.20.0.1';
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => 'dev_cache_invalidation_device_01']);
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => 'dev_cache_invalidation_device_01']);

        $abuser = User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'registration_device_id' => 'dev_cache_invalidation_device_01']);

        $service = app(MultiAccountEnforcementService::class);
        $service->enforceAfterAuth($abuser);

        $this->assertNull(Cache::get('admin:suspicious_users_count'));
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 15: SuspiciousUsers Filament page — query & detection logic
    // ══════════════════════════════════════════════════════════════════════

    public function test_suspicious_users_query_finds_shared_registration_ips(): void
    {
        $sharedIp = '192.168.50.1';

        $u1 = User::factory()->create(['registration_ip' => $sharedIp, 'last_login_ip' => $sharedIp]);
        $u2 = User::factory()->create(['registration_ip' => $sharedIp, 'last_login_ip' => $sharedIp]);
        $loner = User::factory()->create(['registration_ip' => '10.0.0.99', 'last_login_ip' => '10.0.0.99']);

        $page = new \App\Filament\Pages\SuspiciousUsers();
        $query = $this->invokeProtected($page, 'getSuspiciousUsersQuery');
        $ids = $query->pluck('id')->toArray();

        $this->assertContains($u1->id, $ids);
        $this->assertContains($u2->id, $ids);
        $this->assertNotContains($loner->id, $ids);
    }

    public function test_suspicious_users_query_finds_cross_column_ip_matches(): void
    {
        $sharedIp = '172.16.99.1';

        // User A's registration_ip = User B's last_login_ip
        $u1 = User::factory()->create(['registration_ip' => $sharedIp, 'last_login_ip' => '1.2.3.4']);
        $u2 = User::factory()->create(['registration_ip' => '5.6.7.8', 'last_login_ip' => $sharedIp]);

        $page = new \App\Filament\Pages\SuspiciousUsers();
        $query = $this->invokeProtected($page, 'getSuspiciousUsersQuery');
        $ids = $query->pluck('id')->toArray();

        $this->assertContains($u1->id, $ids);
        $this->assertContains($u2->id, $ids);
    }

    public function test_suspicious_users_query_finds_shared_device_ids(): void
    {
        $sharedDevice = 'dev_suspicious_shared_device_01234';

        $u1 = User::factory()->create([
            'registration_ip' => '10.50.0.1',
            'last_login_ip' => '10.50.0.1',
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => $sharedDevice,
        ]);
        $u2 = User::factory()->create([
            'registration_ip' => '10.50.0.2',
            'last_login_ip' => '10.50.0.2',
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => $sharedDevice,
        ]);

        $page = new \App\Filament\Pages\SuspiciousUsers();
        $query = $this->invokeProtected($page, 'getSuspiciousUsersQuery');
        $ids = $query->pluck('id')->toArray();

        $this->assertContains($u1->id, $ids);
        $this->assertContains($u2->id, $ids);
    }

    public function test_suspicious_users_query_finds_cross_column_device_id_matches(): void
    {
        $sharedDevice = 'dev_cross_col_suspicious_0123456';

        // User A's registration_device_id = User B's last_seen_device_id
        $u1 = User::factory()->create([
            'registration_ip' => '10.51.0.1',
            'last_login_ip' => '10.51.0.1',
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => null,
        ]);
        $u2 = User::factory()->create([
            'registration_ip' => '10.51.0.2',
            'last_login_ip' => '10.51.0.2',
            'registration_device_id' => null,
            'last_seen_device_id' => $sharedDevice,
        ]);

        $page = new \App\Filament\Pages\SuspiciousUsers();
        $query = $this->invokeProtected($page, 'getSuspiciousUsersQuery');
        $ids = $query->pluck('id')->toArray();

        $this->assertContains($u1->id, $ids);
        $this->assertContains($u2->id, $ids);
    }

    public function test_suspicious_users_query_returns_empty_when_no_shared_signals(): void
    {
        User::factory()->create(['registration_ip' => '10.60.0.1', 'last_login_ip' => '10.60.0.1']);
        User::factory()->create(['registration_ip' => '10.60.0.2', 'last_login_ip' => '10.60.0.2']);
        User::factory()->create(['registration_ip' => '10.60.0.3', 'last_login_ip' => '10.60.0.3']);

        $page = new \App\Filament\Pages\SuspiciousUsers();
        $query = $this->invokeProtected($page, 'getSuspiciousUsersQuery');

        $this->assertSame(0, $query->count());
    }

    public function test_suspicious_users_linked_count_is_correct(): void
    {
        $sharedIp = '192.168.70.1';

        User::factory()->create(['registration_ip' => $sharedIp, 'last_login_ip' => $sharedIp]);
        User::factory()->create(['registration_ip' => $sharedIp, 'last_login_ip' => $sharedIp]);
        $target = User::factory()->create(['registration_ip' => $sharedIp, 'last_login_ip' => $sharedIp]);

        $page = new \App\Filament\Pages\SuspiciousUsers();
        $query = $this->invokeProtected($page, 'getSuspiciousUsersQuery');
        $row = $query->where('users.id', $target->id)->first();

        $this->assertNotNull($row);
        // linked_count = other users sharing signals = 2
        $this->assertSame(2, (int) $row->linked_count);
    }

    public function test_get_linked_users_returns_all_ip_linked_users(): void
    {
        $sharedIp = '192.168.80.1';

        $u1 = User::factory()->create(['registration_ip' => $sharedIp, 'last_login_ip' => $sharedIp]);
        $u2 = User::factory()->create(['registration_ip' => $sharedIp, 'last_login_ip' => $sharedIp]);
        $u3 = User::factory()->create(['registration_ip' => $sharedIp, 'last_login_ip' => $sharedIp]);
        $loner = User::factory()->create(['registration_ip' => '10.99.0.1', 'last_login_ip' => '10.99.0.1']);

        $page = new \App\Filament\Pages\SuspiciousUsers();
        $linked = $this->invokeProtected($page, 'getLinkedUsers', $u1);
        $linkedIds = $linked->pluck('id')->toArray();

        $this->assertContains($u1->id, $linkedIds);
        $this->assertContains($u2->id, $linkedIds);
        $this->assertContains($u3->id, $linkedIds);
        $this->assertNotContains($loner->id, $linkedIds);
    }

    public function test_get_linked_users_includes_device_linked(): void
    {
        $sharedDevice = 'dev_linked_users_test_0123456789';

        $u1 = User::factory()->create([
            'registration_ip' => '10.80.0.1',
            'last_login_ip' => '10.80.0.1',
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => $sharedDevice,
        ]);
        $unrelated = User::factory()->create([
            'registration_ip' => '10.80.0.2',
            'last_login_ip' => '10.80.0.2',
        ]);

        $target = User::factory()->create([
            'registration_ip' => '10.80.0.3',
            'last_login_ip' => '10.80.0.3',
            'registration_device_id' => $sharedDevice,
            'last_seen_device_id' => $sharedDevice,
        ]);

        $page = new \App\Filament\Pages\SuspiciousUsers();
        $linkedIds = $this->invokeProtected($page, 'getLinkedUsers', $target)->pluck('id')->toArray();

        $this->assertContains($u1->id, $linkedIds);
        $this->assertContains($target->id, $linkedIds);
        $this->assertNotContains($unrelated->id, $linkedIds);
    }

    public function test_get_linked_users_returns_empty_when_no_signals(): void
    {
        $user = User::factory()->create([
            'registration_ip' => null,
            'last_login_ip' => null,
            'registration_device_id' => null,
            'last_seen_device_id' => null,
        ]);

        $page = new \App\Filament\Pages\SuspiciousUsers();
        $linked = $this->invokeProtected($page, 'getLinkedUsers', $user);

        $this->assertTrue($linked->isEmpty());
    }

    public function test_navigation_badge_shows_count_of_suspicious_users(): void
    {
        Cache::forget('admin:suspicious_users_count');

        $sharedIp = '192.168.90.1';
        User::factory()->create(['registration_ip' => $sharedIp, 'last_login_ip' => $sharedIp]);
        User::factory()->create(['registration_ip' => $sharedIp, 'last_login_ip' => $sharedIp]);

        $badge = \App\Filament\Pages\SuspiciousUsers::getNavigationBadge();

        $this->assertNotNull($badge);
        $this->assertSame('2', $badge);
    }

    public function test_navigation_badge_returns_null_when_no_suspicious_users(): void
    {
        Cache::forget('admin:suspicious_users_count');

        // Only unique IPs
        User::factory()->create(['registration_ip' => '10.70.0.1', 'last_login_ip' => '10.70.0.1']);
        User::factory()->create(['registration_ip' => '10.70.0.2', 'last_login_ip' => '10.70.0.2']);

        $badge = \App\Filament\Pages\SuspiciousUsers::getNavigationBadge();

        $this->assertNull($badge);
    }

    public function test_navigation_badge_uses_cache(): void
    {
        Cache::put('admin:suspicious_users_count', 42, 600);

        $badge = \App\Filament\Pages\SuspiciousUsers::getNavigationBadge();
        $this->assertSame('42', $badge);
    }

    public function test_navigation_badge_includes_device_id_shared_users(): void
    {
        Cache::forget('admin:suspicious_users_count');

        $sharedDevice = 'dev_badge_test_device_0123456789';
        User::factory()->create([
            'registration_ip' => '10.71.0.1',
            'last_login_ip' => '10.71.0.1',
            'registration_device_id' => $sharedDevice,
        ]);
        User::factory()->create([
            'registration_ip' => '10.71.0.2',
            'last_login_ip' => '10.71.0.2',
            'registration_device_id' => $sharedDevice,
        ]);

        $badge = \App\Filament\Pages\SuspiciousUsers::getNavigationBadge();

        $this->assertNotNull($badge);
        $this->assertSame('2', $badge);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 16: Demo usage aggregation in suspicious users query
    // ══════════════════════════════════════════════════════════════════════

    public function test_suspicious_users_query_aggregates_demo_chars_used(): void
    {
        $sharedIp = '192.168.100.1';

        $user = User::factory()->create(['registration_ip' => $sharedIp, 'last_login_ip' => $sharedIp]);
        User::factory()->create(['registration_ip' => $sharedIp, 'last_login_ip' => $sharedIp]);

        \App\Models\DemoVoiceGeneration::query()->create([
            'user_id' => $user->id,
            'primary_identity_hash' => 'u:' . $user->id,
            'input_text' => str_repeat('x', 150),
            'speech_text' => str_repeat('x', 150),
            'source_language' => 'en',
            'target_language' => 'en',
            'translate' => false,
            'voice_name' => 'en-US-Wavenet-C',
            'voice_provider' => 'google_cloud',
            'voice_tier' => 'standard',
            'billable_chars' => 150,
        ]);

        $page = new \App\Filament\Pages\SuspiciousUsers();
        $query = $this->invokeProtected($page, 'getSuspiciousUsersQuery');
        $row = $query->where('users.id', $user->id)->first();

        $this->assertNotNull($row);
        $this->assertSame(150, (int) $row->demo_chars_used);
    }

    public function test_suspicious_users_query_aggregates_ip_group_total_chars(): void
    {
        $sharedIp = '192.168.101.1';

        $u1 = User::factory()->create(['registration_ip' => $sharedIp, 'last_login_ip' => $sharedIp]);
        $u2 = User::factory()->create(['registration_ip' => $sharedIp, 'last_login_ip' => $sharedIp]);

        foreach ([[$u1, 100], [$u2, 200]] as [$user, $chars]) {
            \App\Models\DemoVoiceGeneration::query()->create([
                'user_id' => $user->id,
                'primary_identity_hash' => 'u:' . $user->id,
                'input_text' => str_repeat('x', $chars),
                'speech_text' => str_repeat('x', $chars),
                'source_language' => 'en',
                'target_language' => 'en',
                'translate' => false,
                'voice_name' => 'en-US-Wavenet-C',
                'voice_provider' => 'google_cloud',
                'voice_tier' => 'standard',
                'billable_chars' => $chars,
            ]);
        }

        $page = new \App\Filament\Pages\SuspiciousUsers();
        $query = $this->invokeProtected($page, 'getSuspiciousUsersQuery');
        $row = $query->where('users.id', $u1->id)->first();

        $this->assertNotNull($row);
        // ip_group_total_chars should include both u1 (100) and u2 (200) = 300
        $this->assertSame(300, (int) $row->ip_group_total_chars);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 17: Device cookie generation
    // ══════════════════════════════════════════════════════════════════════

    public function test_login_page_sets_device_cookie_when_absent(): void
    {
        $response = $this->getLocalizedRoute('login');

        $response->assertCookie(self::DEVICE_COOKIE);
    }

    public function test_login_page_does_not_replace_existing_device_cookie(): void
    {
        $existingDevice = 'dev_existing_cookie_0123456789abc';

        $response = $this->withCookies([self::DEVICE_COOKIE => $existingDevice])
            ->getLocalizedRoute('login');

        $response->assertCookieMissing(self::DEVICE_COOKIE);
    }

    public function test_register_page_sets_device_cookie_when_absent(): void
    {
        $response = $this->getLocalizedRoute('register');

        $response->assertCookie(self::DEVICE_COOKIE);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 18: Phone/Firebase auth device & signature stamping via stampLoginIp
    // ══════════════════════════════════════════════════════════════════════

    public function test_phone_auth_updates_last_login_ip_and_device_via_stamp_login_ip(): void
    {
        $this->enablePhoneSettings();

        $user = User::factory()->create([
            'firebase_uid' => 'phone-device-stamp-uid',
            'phone_number' => '+201000000055',
            'phone_verified_at' => now(),
            'registration_ip' => '10.0.0.1',
            'last_login_ip' => '10.0.0.1',
            'registration_device_id' => null,
            'last_seen_device_id' => null,
        ]);

        $mock = Mockery::mock(PhoneAuthBridgeService::class);
        $mock->shouldReceive('resolveUserFromIdToken')
            ->once()
            ->andReturn($user);
        $this->app->instance(PhoneAuthBridgeService::class, $mock);

        // Phone auth is a JSON API — verify IP is updated
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->postJsonLocalizedRoute('auth.phone.verify', [
                'id_token' => 'mock_token',
                'intent' => 'login',
            ]);

        $user->refresh();
        $this->assertSame('10.0.0.99', $user->last_login_ip, 'Phone auth must update last_login_ip via stampLoginIp');
    }

    public function test_stamp_login_ip_backfills_device_when_null(): void
    {
        // Test the stampLoginIp method behavior directly:
        // When a user has null device_id/signature and logs in with them,
        // both registration and last_seen fields should be populated
        $deviceId = 'dev_stamp_backfill_test_01234567';
        $user = User::factory()->create([
            'password' => Hash::make('Password123!'),
            'registration_device_id' => null,
            'last_seen_device_id' => null,
        ]);

        $headers = $this->chromeHeaders();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withCookies([self::DEVICE_COOKIE => $deviceId])
            ->withHeaders($headers)
            ->postLocalizedRoute('login.submit', [
                'email' => $user->email,
                'password' => 'Password123!',
            ]);

        $user->refresh();
        // Both registration and last_seen should be backfilled
        $this->assertSame($deviceId, $user->registration_device_id, 'stampLoginIp should backfill registration_device_id');
        $this->assertSame($deviceId, $user->last_seen_device_id, 'stampLoginIp should set last_seen_device_id');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 19: Edge cases — special device ID formats
    // ══════════════════════════════════════════════════════════════════════

    public function test_device_id_with_special_chars_rejected(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withCookies([self::DEVICE_COOKIE => 'dev_with spaces and $pecial!chars'])
            ->postLocalizedRoute('register.submit', [
                'name' => 'Special Chars',
                'email' => 'special-chars@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'special-chars@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->registration_device_id);
    }

    public function test_device_id_at_max_length_accepted(): void
    {
        $deviceId = str_repeat('A', 128); // max length

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withCookies([self::DEVICE_COOKIE => $deviceId])
            ->postLocalizedRoute('register.submit', [
                'name' => 'Max Length',
                'email' => 'max-length@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'max-length@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame($deviceId, $user->registration_device_id);
    }

    public function test_device_id_over_max_length_rejected(): void
    {
        $deviceId = str_repeat('A', 129); // over max

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withCookies([self::DEVICE_COOKIE => $deviceId])
            ->postLocalizedRoute('register.submit', [
                'name' => 'Over Max',
                'email' => 'over-max@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'over-max@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->registration_device_id);
    }

    public function test_device_id_at_min_length_accepted(): void
    {
        $deviceId = str_repeat('x', 24); // min length

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withCookies([self::DEVICE_COOKIE => $deviceId])
            ->postLocalizedRoute('register.submit', [
                'name' => 'Min Length',
                'email' => 'min-length@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'min-length@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame($deviceId, $user->registration_device_id);
    }

    public function test_device_id_under_min_length_rejected(): void
    {
        $deviceId = str_repeat('x', 23); // under min

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withCookies([self::DEVICE_COOKIE => $deviceId])
            ->postLocalizedRoute('register.submit', [
                'name' => 'Under Min',
                'email' => 'under-min@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'under-min@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->registration_device_id);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Helpers
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Invoke a protected/private method on an object.
     */
    private function invokeProtected(object $object, string $method, ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
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

    private function chromeHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Sec-CH-UA' => '"Chromium";v="131", "Google Chrome";v="131"',
            'Sec-CH-UA-Platform' => '"Windows"',
            'Sec-CH-UA-Mobile' => '?0',
        ];
    }

    private function fakeRequest(array $serverVars): HttpRequest
    {
        return HttpRequest::create('/', 'GET', [], [], [], $serverVars);
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
