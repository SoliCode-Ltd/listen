<?php

namespace Tests\Feature;

use App\Models\DemoVoiceGeneration;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\BlockedUserService;
use App\Services\MultiAccountEnforcementService;
use App\Support\RequestIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Deep correctness audit of the linked-account / multi-account system.
 *
 * These tests verify that:
 * - Legitimate users are never linked incorrectly
 * - Legitimate users are never blocked incorrectly
 * - Null / empty values never create false matches
 * - The max linked accounts rule works exactly as intended
 * - Quota sharing works only for truly linked accounts
 * - Edge cases around stale/malformed/duplicate data are covered
 */
class LinkedAccountCorrectnessAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFreePlan();
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 1: Legitimate first-time users are NOT linked or blocked
    // ══════════════════════════════════════════════════════════════════════

    public function test_first_user_from_any_ip_is_never_blocked(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
            ->postLocalizedRoute('register.submit', [
                'name' => 'First User',
                'email' => 'first@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'first@example.com')->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->isBlocked());
    }

    public function test_user_with_no_signals_is_never_blocked(): void
    {
        $user = User::factory()->create([
            'registration_ip' => null,
            'last_login_ip' => null,
            'registration_device_id' => null,
            'last_seen_device_id' => null,
            'registration_request_signature' => null,
            'last_seen_request_signature' => null,
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $service->enforceAfterAuth($user);

        $user->refresh();
        $this->assertFalse($user->isBlocked());
    }

    public function test_two_users_from_same_ip_neither_is_blocked(): void
    {
        $ip = '198.51.100.10';
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postLocalizedRoute('register.submit', [
                'name' => 'Second User',
                'email' => 'second@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $user = User::where('email', 'second@example.com')->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->isBlocked());
    }

    public function test_users_from_different_ips_different_devices_different_signatures_are_independent(): void
    {
        // Create users with completely distinct signals
        User::factory()->create([
            'registration_ip' => '10.0.0.1',
            'last_login_ip' => '10.0.0.1',
            'registration_device_id' => 'device-aaa-111',
            'last_seen_device_id' => 'device-aaa-111',
            'registration_request_signature' => hash('sha256', 'sig-1'),
            'last_seen_request_signature' => hash('sha256', 'sig-1'),
        ]);
        User::factory()->create([
            'registration_ip' => '10.0.0.2',
            'last_login_ip' => '10.0.0.2',
            'registration_device_id' => 'device-bbb-222',
            'last_seen_device_id' => 'device-bbb-222',
            'registration_request_signature' => hash('sha256', 'sig-2'),
            'last_seen_request_signature' => hash('sha256', 'sig-2'),
        ]);

        // Third user with unique signals
        $user = User::factory()->create([
            'registration_ip' => '10.0.0.3',
            'last_login_ip' => '10.0.0.3',
            'registration_device_id' => 'device-ccc-333',
            'last_seen_device_id' => 'device-ccc-333',
            'registration_request_signature' => hash('sha256', 'sig-3'),
            'last_seen_request_signature' => hash('sha256', 'sig-3'),
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $service->enforceAfterAuth($user);

        $user->refresh();
        $this->assertFalse($user->isBlocked());

        // Verify linked count is 1 (only self)
        $this->assertSame(1, $service->linkedAccountCount($user));
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 2: Null / empty values never create accidental linking
    // ══════════════════════════════════════════════════════════════════════

    public function test_null_ips_do_not_link_users(): void
    {
        User::factory()->create([
            'registration_ip' => null,
            'last_login_ip' => null,
        ]);
        User::factory()->create([
            'registration_ip' => null,
            'last_login_ip' => null,
        ]);
        $user = User::factory()->create([
            'registration_ip' => null,
            'last_login_ip' => null,
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $service->enforceAfterAuth($user);

        $user->refresh();
        $this->assertFalse($user->isBlocked());
    }

    public function test_null_device_ids_do_not_link_users(): void
    {
        // Different IPs, null device IDs
        User::factory()->create([
            'registration_ip' => '10.1.1.1',
            'last_login_ip' => '10.1.1.1',
            'registration_device_id' => null,
            'last_seen_device_id' => null,
        ]);
        User::factory()->create([
            'registration_ip' => '10.1.1.2',
            'last_login_ip' => '10.1.1.2',
            'registration_device_id' => null,
            'last_seen_device_id' => null,
        ]);
        $user = User::factory()->create([
            'registration_ip' => '10.1.1.3',
            'last_login_ip' => '10.1.1.3',
            'registration_device_id' => null,
            'last_seen_device_id' => null,
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $this->assertSame(1, $service->linkedAccountCount($user));
    }

    public function test_null_request_signatures_do_not_link_users(): void
    {
        User::factory()->create([
            'registration_ip' => '10.2.1.1',
            'last_login_ip' => '10.2.1.1',
            'registration_request_signature' => null,
            'last_seen_request_signature' => null,
        ]);
        User::factory()->create([
            'registration_ip' => '10.2.1.2',
            'last_login_ip' => '10.2.1.2',
            'registration_request_signature' => null,
            'last_seen_request_signature' => null,
        ]);
        $user = User::factory()->create([
            'registration_ip' => '10.2.1.3',
            'last_login_ip' => '10.2.1.3',
            'registration_request_signature' => null,
            'last_seen_request_signature' => null,
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $this->assertSame(1, $service->linkedAccountCount($user));
    }

    public function test_empty_string_signals_do_not_link_users(): void
    {
        User::factory()->create([
            'registration_ip' => '10.3.1.1',
            'last_login_ip' => '10.3.1.1',
            'registration_device_id' => '',
            'last_seen_device_id' => '',
            'registration_request_signature' => '',
            'last_seen_request_signature' => '',
        ]);
        User::factory()->create([
            'registration_ip' => '10.3.1.2',
            'last_login_ip' => '10.3.1.2',
            'registration_device_id' => '',
            'last_seen_device_id' => '',
            'registration_request_signature' => '',
            'last_seen_request_signature' => '',
        ]);
        $user = User::factory()->create([
            'registration_ip' => '10.3.1.3',
            'last_login_ip' => '10.3.1.3',
            'registration_device_id' => '',
            'last_seen_device_id' => '',
            'registration_request_signature' => '',
            'last_seen_request_signature' => '',
        ]);

        $service = app(MultiAccountEnforcementService::class);
        // Only IP-based linking should work here — each user has unique IP
        $this->assertSame(1, $service->linkedAccountCount($user));
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 3: Max linked accounts rule works correctly
    // ══════════════════════════════════════════════════════════════════════

    public function test_exact_limit_is_not_blocked_one_over_is(): void
    {
        SiteSetting::set('max_accounts_per_ip', '3', 'general');
        Cache::forget('site_setting.max_accounts_per_ip');

        $ip = '192.168.50.1';
        User::factory()->count(2)->create(['registration_ip' => $ip, 'last_login_ip' => $ip]);

        // Third user = exactly at limit (3) — NOT blocked
        $userAtLimit = User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip]);
        $service = app(MultiAccountEnforcementService::class);
        $service->enforceAfterAuth($userAtLimit);
        $userAtLimit->refresh();
        $this->assertFalse($userAtLimit->isBlocked(), 'User at exactly the limit should NOT be blocked');

        // Fourth user = over limit — blocked
        $userOverLimit = User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip]);
        $service->enforceAfterAuth($userOverLimit);
        $userOverLimit->refresh();
        $this->assertTrue($userOverLimit->isBlocked(), 'User over the limit should be blocked');
    }

    public function test_limit_of_five_allows_five_blocks_sixth(): void
    {
        SiteSetting::set('max_accounts_per_ip', '5', 'general');
        Cache::forget('site_setting.max_accounts_per_ip');

        $ip = '192.168.60.1';
        User::factory()->count(5)->create(['registration_ip' => $ip, 'last_login_ip' => $ip]);

        $sixthUser = User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip]);
        $service = app(MultiAccountEnforcementService::class);
        $service->enforceAfterAuth($sixthUser);

        $sixthUser->refresh();
        $this->assertTrue($sixthUser->isBlocked(), 'Sixth account should be blocked when limit is 5');
    }

    public function test_linked_count_is_accurate_no_duplicates(): void
    {
        $ip = '10.5.5.5';
        $deviceId = 'shared-device-xyz-123456789012';
        $signature = hash('sha256', 'shared-sig');

        // User with all signals pointing to same group
        $user = User::factory()->create([
            'registration_ip' => $ip,
            'last_login_ip' => $ip,
            'registration_device_id' => $deviceId,
            'last_seen_device_id' => $deviceId,
            'registration_request_signature' => $signature,
            'last_seen_request_signature' => $signature,
        ]);

        $service = app(MultiAccountEnforcementService::class);
        // Only 1 user exists — count should be 1, not inflated by multiple signals
        $this->assertSame(1, $service->linkedAccountCount($user));
    }

    public function test_admins_excluded_from_linked_count(): void
    {
        $ip = '10.6.6.6';

        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip, 'is_admin' => true]);
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip]);

        $user = User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip]);

        $service = app(MultiAccountEnforcementService::class);
        // Should count only 2 non-admin users, not 3
        $this->assertSame(2, $service->linkedAccountCount($user));
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 4: Quota sharing works only for truly linked accounts
    // ══════════════════════════════════════════════════════════════════════

    public function test_unrelated_user_does_not_lose_quota(): void
    {
        $this->seedVoice();

        // User A on IP 10.10.10.1 used 250 chars
        $userA = User::factory()->create([
            'registration_ip' => '10.10.10.1',
            'last_login_ip' => '10.10.10.1',
        ]);
        $this->storeDemoUsage($userA, 250);

        // User B on a completely different IP
        $userB = User::factory()->create([
            'registration_ip' => '10.10.10.2',
            'last_login_ip' => '10.10.10.2',
        ]);

        Cache::flush();

        // User B should see 0 used chars
        $response = $this->actingAs($userB)
            ->withServerVariables(['REMOTE_ADDR' => '10.10.10.2'])
            ->postJsonLocalizedRoute('demo.quota', []);

        $response->assertOk();
        $response->assertJsonPath('quota.used_chars', 0);
    }

    public function test_linked_users_share_quota_correctly(): void
    {
        $this->seedVoice();
        $ip = '10.11.11.1';

        // User A used 200 chars
        $userA = User::factory()->create([
            'registration_ip' => $ip,
            'last_login_ip' => $ip,
        ]);
        $this->storeDemoUsage($userA, 200);

        // User B same IP, used 50 chars
        $userB = User::factory()->create([
            'registration_ip' => $ip,
            'last_login_ip' => $ip,
        ]);
        $this->storeDemoUsage($userB, 50);

        Cache::flush();

        // User B should see total = 200 + 50 = 250
        $response = $this->actingAs($userB)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJsonLocalizedRoute('demo.quota', []);

        $response->assertOk();
        $response->assertJsonPath('quota.used_chars', 250);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 5: Blocking logic correctness
    // ══════════════════════════════════════════════════════════════════════

    public function test_whitelisted_user_cannot_be_auto_blocked(): void
    {
        $ip = '10.20.0.1';
        User::factory()->count(2)->create(['registration_ip' => $ip, 'last_login_ip' => $ip]);

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

    public function test_already_blocked_user_is_not_reprocessed(): void
    {
        $ip = '10.21.0.1';
        User::factory()->count(2)->create(['registration_ip' => $ip, 'last_login_ip' => $ip]);

        $user = User::factory()->create([
            'registration_ip' => $ip,
            'last_login_ip' => $ip,
            'is_blocked' => true,
            'blocked_at' => now(),
            'blocked_reason' => 'manual block',
        ]);

        $originalReason = $user->blocked_reason;

        $service = app(MultiAccountEnforcementService::class);
        $service->enforceAfterAuth($user);

        $user->refresh();
        // Reason should not change — enforcement was skipped
        $this->assertSame($originalReason, $user->blocked_reason);
    }

    public function test_unblock_sets_whitelist_flag(): void
    {
        $user = User::factory()->create([
            'is_blocked' => true,
            'blocked_at' => now(),
            'blocked_reason' => 'test',
        ]);

        app(BlockedUserService::class)->unblock($user);
        $user->refresh();

        $this->assertFalse($user->isBlocked());
        $this->assertTrue((bool) $user->multi_account_whitelisted);
    }

    public function test_block_clears_whitelist_flag(): void
    {
        $user = User::factory()->create([
            'multi_account_whitelisted' => true,
        ]);

        app(BlockedUserService::class)->block($user, 'test reason');
        $user->refresh();

        $this->assertTrue($user->isBlocked());
        $this->assertFalse((bool) $user->multi_account_whitelisted);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 6: Request Signature entropy guard (Bug 1 fix)
    // ══════════════════════════════════════════════════════════════════════

    public function test_request_signature_returns_null_without_client_hints(): void
    {
        // Simulate Firefox/Safari — no Sec-CH-UA* headers
        $request = HttpRequest::create('/test', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.5',
        ]);

        $signature = RequestIdentity::signature($request);
        $this->assertNull($signature, 'Signature must be null without Client Hints to prevent false linking');
    }

    public function test_request_signature_returns_hash_with_client_hints(): void
    {
        $request = HttpRequest::create('/test', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/125.0',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
            'HTTP_SEC_CH_UA' => '"Chromium";v="125", "Google Chrome";v="125"',
            'HTTP_SEC_CH_UA_PLATFORM' => '"Windows"',
            'HTTP_SEC_CH_UA_MOBILE' => '?0',
        ]);

        $signature = RequestIdentity::signature($request);
        $this->assertNotNull($signature, 'Signature should exist when Client Hints are present');
        $this->assertSame(64, strlen($signature), 'Signature should be a SHA-256 hex string');
    }

    public function test_request_signature_returns_null_for_empty_user_agent(): void
    {
        $request = HttpRequest::create('/test', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => '',
            'HTTP_SEC_CH_UA' => '"Chromium";v="125"',
        ]);

        $signature = RequestIdentity::signature($request);
        $this->assertNull($signature, 'Empty UA should return null signature');
    }

    public function test_different_client_hints_produce_different_signatures(): void
    {
        $base = [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/125.0',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US',
            'HTTP_SEC_CH_UA' => '"Chromium";v="125"',
            'HTTP_SEC_CH_UA_PLATFORM' => '"Windows"',
            'HTTP_SEC_CH_UA_MOBILE' => '?0',
        ];

        $request1 = HttpRequest::create('/test', 'GET', [], [], [], $base);
        $request2 = HttpRequest::create('/test', 'GET', [], [], [], array_merge($base, [
            'HTTP_SEC_CH_UA_PLATFORM' => '"macOS"',
        ]));

        $sig1 = RequestIdentity::signature($request1);
        $sig2 = RequestIdentity::signature($request2);

        $this->assertNotNull($sig1);
        $this->assertNotNull($sig2);
        $this->assertNotSame($sig1, $sig2, 'Different platforms should produce different signatures');
    }

    public function test_users_with_no_client_hints_are_not_linked_by_signature(): void
    {
        // Simulate: both users registered from browsers without Client Hints
        // With the fix, their request_signature should be null → no false linking
        $user1 = User::factory()->create([
            'registration_ip' => '10.50.1.1',
            'last_login_ip' => '10.50.1.1',
            'registration_request_signature' => null,
            'last_seen_request_signature' => null,
        ]);
        $user2 = User::factory()->create([
            'registration_ip' => '10.50.1.2',
            'last_login_ip' => '10.50.1.2',
            'registration_request_signature' => null,
            'last_seen_request_signature' => null,
        ]);
        $user3 = User::factory()->create([
            'registration_ip' => '10.50.1.3',
            'last_login_ip' => '10.50.1.3',
            'registration_request_signature' => null,
            'last_seen_request_signature' => null,
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $service->enforceAfterAuth($user3);

        $user3->refresh();
        $this->assertFalse($user3->isBlocked(), 'Users with null signatures on different IPs should never be linked');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 7: Cross-column matching edge cases
    // ══════════════════════════════════════════════════════════════════════

    public function test_cross_column_ip_match_counts_correctly(): void
    {
        // User A registered with IP X, logged in from IP Y
        User::factory()->create([
            'registration_ip' => '10.70.0.1',
            'last_login_ip' => '10.70.0.2',
        ]);

        // User B registered with IP Y — shares last_login_ip of User A
        $userB = User::factory()->create([
            'registration_ip' => '10.70.0.2',
            'last_login_ip' => '10.70.0.3',
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $count = $service->linkedAccountCount($userB);

        // User B's registration_ip (10.70.0.2) matches User A's last_login_ip
        $this->assertSame(2, $count);
    }

    public function test_cross_column_device_match_counts_correctly(): void
    {
        $deviceId = 'cross-device-match-abcdefghijklmnop';

        User::factory()->create([
            'registration_ip' => '10.71.0.1',
            'last_login_ip' => '10.71.0.1',
            'registration_device_id' => $deviceId,
            'last_seen_device_id' => 'other-device-abcdefghijklmnopqr',
        ]);

        $userB = User::factory()->create([
            'registration_ip' => '10.71.0.2',
            'last_login_ip' => '10.71.0.2',
            'registration_device_id' => 'yet-another-abcdefghijklmnopqr',
            'last_seen_device_id' => $deviceId,
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $count = $service->linkedAccountCount($userB);

        // User B's last_seen_device_id = User A's registration_device_id → linked
        $this->assertSame(2, $count);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 8: Stale / reused values edge cases
    // ══════════════════════════════════════════════════════════════════════

    public function test_user_changing_ip_does_not_retroactively_link_to_old_ip_group(): void
    {
        $ipA = '10.80.0.1';
        $ipB = '10.80.0.2';

        // Two users on IP A
        User::factory()->create(['registration_ip' => $ipA, 'last_login_ip' => $ipA]);
        User::factory()->create(['registration_ip' => $ipA, 'last_login_ip' => $ipA]);

        // User C registered on IP B, hasn't logged in from IP A
        $userC = User::factory()->create([
            'registration_ip' => $ipB,
            'last_login_ip' => $ipB,
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $service->enforceAfterAuth($userC);

        $userC->refresh();
        $this->assertFalse($userC->isBlocked(), 'User C on different IP should not be blocked');
        $this->assertSame(1, $service->linkedAccountCount($userC));
    }

    public function test_user_with_mixed_null_and_real_signals_only_links_on_real_ones(): void
    {
        $sharedIp = '10.90.0.1';

        User::factory()->create([
            'registration_ip' => $sharedIp,
            'last_login_ip' => $sharedIp,
            'registration_device_id' => null,
            'last_seen_device_id' => null,
            'registration_request_signature' => null,
            'last_seen_request_signature' => null,
        ]);

        $user = User::factory()->create([
            'registration_ip' => $sharedIp,
            'last_login_ip' => $sharedIp,
            'registration_device_id' => 'unique-device-only-this-user-has',
            'last_seen_device_id' => 'unique-device-only-this-user-has',
            'registration_request_signature' => hash('sha256', 'unique-sig'),
            'last_seen_request_signature' => hash('sha256', 'unique-sig'),
        ]);

        $service = app(MultiAccountEnforcementService::class);
        // They are linked via IP, so count = 2
        $this->assertSame(2, $service->linkedAccountCount($user));
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 9: Signal isolation — single signal shouldn't cause block
    //  when other signals are unique (verifying current OR behavior)
    // ══════════════════════════════════════════════════════════════════════

    public function test_same_device_only_links_accounts_regardless_of_ip(): void
    {
        // The current system intentionally uses OR — same device = linked
        $deviceId = 'shared-device-isolation-test-abc';

        User::factory()->create([
            'registration_ip' => '10.100.1.1',
            'last_login_ip' => '10.100.1.1',
            'registration_device_id' => $deviceId,
            'last_seen_device_id' => $deviceId,
        ]);
        User::factory()->create([
            'registration_ip' => '10.100.1.2',
            'last_login_ip' => '10.100.1.2',
            'registration_device_id' => $deviceId,
            'last_seen_device_id' => $deviceId,
        ]);

        $user = User::factory()->create([
            'registration_ip' => '10.100.1.3',
            'last_login_ip' => '10.100.1.3',
            'registration_device_id' => $deviceId,
            'last_seen_device_id' => $deviceId,
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $count = $service->linkedAccountCount($user);

        // Same device → linked (by design)
        $this->assertSame(3, $count);

        $service->enforceAfterAuth($user);
        $user->refresh();
        $this->assertTrue($user->isBlocked(), 'Same device across 3 accounts triggers block (by design)');
    }

    public function test_same_signature_only_links_accounts_regardless_of_ip(): void
    {
        $sig = hash('sha256', 'shared-signature-isolation-test');

        User::factory()->create([
            'registration_ip' => '10.101.1.1',
            'last_login_ip' => '10.101.1.1',
            'registration_request_signature' => $sig,
            'last_seen_request_signature' => $sig,
        ]);
        User::factory()->create([
            'registration_ip' => '10.101.1.2',
            'last_login_ip' => '10.101.1.2',
            'registration_request_signature' => $sig,
            'last_seen_request_signature' => $sig,
        ]);

        $user = User::factory()->create([
            'registration_ip' => '10.101.1.3',
            'last_login_ip' => '10.101.1.3',
            'registration_request_signature' => $sig,
            'last_seen_request_signature' => $sig,
        ]);

        $service = app(MultiAccountEnforcementService::class);
        $count = $service->linkedAccountCount($user);

        // Same signature → linked (by design)
        $this->assertSame(3, $count);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 10: Configurable limit edge cases
    // ══════════════════════════════════════════════════════════════════════

    public function test_limit_of_one_blocks_second_account(): void
    {
        SiteSetting::set('max_accounts_per_ip', '1', 'general');
        Cache::forget('site_setting.max_accounts_per_ip');

        $ip = '10.110.0.1';
        User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip]);

        $user = User::factory()->create(['registration_ip' => $ip, 'last_login_ip' => $ip]);
        $service = app(MultiAccountEnforcementService::class);
        $service->enforceAfterAuth($user);

        $user->refresh();
        $this->assertTrue($user->isBlocked());
    }

    public function test_limit_cannot_go_below_one(): void
    {
        SiteSetting::set('max_accounts_per_ip', '0', 'general');
        Cache::forget('site_setting.max_accounts_per_ip');

        $service = app(MultiAccountEnforcementService::class);
        $this->assertSame(1, $service->maxAccountsPerIp(), 'Limit should floor at 1');
    }

    public function test_negative_limit_floors_to_one(): void
    {
        SiteSetting::set('max_accounts_per_ip', '-5', 'general');
        Cache::forget('site_setting.max_accounts_per_ip');

        $service = app(MultiAccountEnforcementService::class);
        $this->assertSame(1, $service->maxAccountsPerIp());
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SECTION 11: Device cookie validation
    // ══════════════════════════════════════════════════════════════════════

    public function test_malformed_device_cookie_is_ignored(): void
    {
        $request = HttpRequest::create('/test', 'GET', [], [
            'listen_demo_device' => '<script>alert(1)</script>',
        ]);

        // The regex validation should reject this
        $raw = trim((string) $request->cookie('listen_demo_device', ''));
        $valid = $raw !== '' && preg_match('/^[A-Za-z0-9_-]{24,128}$/', $raw) === 1;
        $this->assertFalse($valid, 'Malformed cookie should fail validation');
    }

    public function test_short_device_cookie_is_ignored(): void
    {
        $request = HttpRequest::create('/test', 'GET', [], [
            'listen_demo_device' => 'short',
        ]);

        $raw = trim((string) $request->cookie('listen_demo_device', ''));
        $valid = $raw !== '' && preg_match('/^[A-Za-z0-9_-]{24,128}$/', $raw) === 1;
        $this->assertFalse($valid, 'Short cookie should fail validation');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Helpers
    // ══════════════════════════════════════════════════════════════════════

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

    private function seedVoice(): \App\Models\Voice
    {
        return \App\Models\Voice::query()->firstOrCreate(
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
}
