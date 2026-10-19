<?php

namespace Tests\Feature;

use App\Models\DemoVoiceGeneration;
use App\Models\MemberDevice;
use App\Models\Plan;
use App\Models\ReferralCampaign;
use App\Models\User;
use App\Models\Voice;
use App\Services\DemoLinkedAccounts;
use App\Services\Referral\ReferralAbuseGuard;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * One device, one free demo: a new account made in an incognito window (no
 * device cookie, and here another network address) on a device an account
 * already used the demo from shares that account's demo. Identical phones,
 * and identical computers, report the same device fingerprint, so strangers
 * who share one are linked only when their accounts were made close together.
 */
class DemoSameDeviceTest extends TestCase
{
    use RefreshDatabase;

    private const COMPUTER = 'aa11bb22cc33dd44ee55ff66aa11bb22cc33dd44ee55ff66aa11bb22cc33dd44';

    private const PHONE = '0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a69788796a5b4c3d2e1f0';

    protected function setUp(): void
    {
        parent::setUp();

        Plan::query()->firstOrCreate(['slug' => 'free'], [
            'name' => 'Free', 'price_cents' => 0, 'monthly_minutes' => 10, 'monthly_chars' => 10000,
            'monthly_requests' => 60, 'max_chars_per_request' => 1200, 'voice_tier' => 'standard',
            'tts_provider_policy' => 'google_cloud', 'dialogue_enabled' => false, 'custom_prompt_enabled' => false, 'is_active' => true,
        ]);
        Voice::query()->firstOrCreate(['internal_voice_id' => 'en-US-Wavenet-C'], [
            'language' => 'en', 'gender' => 'female', 'tier' => 'standard', 'display_name' => 'Emma',
            'provider' => 'google_cloud', 'provider_voice_id' => 'en-US-Wavenet-C', 'is_active' => true,
        ]);
    }

    public function test_an_incognito_window_on_the_same_computer_shares_the_demo(): void
    {
        $first = $this->member('10.1.0.1', 'browser_first_window_0123456789ab');
        $this->openDemo($first, 'browser_first_window_0123456789ab', self::COMPUTER);
        $this->usedDemo($first, 280);

        // Incognito: a new device cookie, and here another network as well.
        $this->travel(10)->minutes();
        $second = $this->member('172.16.5.9', 'browser_incognito_window_0123456789');

        $this->openDemo($second, 'browser_incognito_window_0123456789', self::COMPUTER)
            ->assertJsonPath('quota.used_chars', 280)
            ->assertJsonPath('quota.remaining_chars', 20);

        $this->actingAs($second)
            ->postJsonLocalizedRoute('demo.run', [
                'text' => str_repeat('z', 30), // 280 + 30 > 300
                'target_language' => 'en',
                'voice_tier' => 'standard',
                'voice_key' => VoiceCatalog::keyFromId((int) Voice::query()->value('id')),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('text');
    }

    public function test_the_device_also_brings_the_accounts_that_share_its_browser_and_it_counts_both_ways(): void
    {
        // The main account, made months ago in this browser, used the demo then.
        $main = $this->member('10.2.0.1', 'browser_main_profile_0123456789abcd');
        $this->usedDemo($main, 250);

        // A second account in the same browser today: linked by the browser.
        $this->travel(8)->months();
        $second = $this->member('10.2.0.1', 'browser_main_profile_0123456789abcd');
        $this->openDemo($second, 'browser_main_profile_0123456789abcd', self::COMPUTER)
            ->assertJsonPath('quota.used_chars', 250);

        // A third in an incognito window, from another network.
        $this->travel(15)->minutes();
        $third = $this->member('172.16.9.9', 'browser_incognito_third_0123456789');
        $this->openDemo($third, 'browser_incognito_third_0123456789', self::COMPUTER)
            ->assertJsonPath('quota.used_chars', 250);
        $this->usedDemo($third, 40);

        // The main account sees what the incognito one used.
        $this->openDemo($main, 'browser_main_profile_0123456789abcd')
            ->assertJsonPath('quota.used_chars', 290);
    }

    public function test_one_phone_in_a_private_tab_shares_the_demo(): void
    {
        $first = $this->member('100.64.1.1', 'browser_phone_tab_0123456789abcdef');
        $this->openDemo($first, 'browser_phone_tab_0123456789abcdef', self::PHONE, touch: true);
        $this->usedDemo($first, 300);

        $this->travel(2)->hours();
        $second = $this->member('100.64.7.7', 'browser_phone_private_0123456789ab');

        $this->openDemo($second, 'browser_phone_private_0123456789ab', self::PHONE, touch: true)
            ->assertJsonPath('quota.remaining_chars', 0)
            ->assertJsonPath('quota.is_exhausted', true);
    }

    public function test_identical_phones_weeks_apart_keep_their_own_demo(): void
    {
        $owner = $this->member('100.64.2.1', 'browser_phone_owner_0123456789abcd');
        $this->openDemo($owner, 'browser_phone_owner_0123456789abcd', self::PHONE, touch: true);
        $this->usedDemo($owner, 300);

        $this->travel(3)->weeks();
        $stranger = $this->member('100.64.2.2', 'browser_phone_stranger_0123456789a');

        $this->openDemo($stranger, 'browser_phone_stranger_0123456789a', self::PHONE, touch: true)
            ->assertJsonPath('quota.used_chars', 0)
            ->assertJsonPath('quota.remaining_chars', 300);
    }

    public function test_a_phone_is_a_phone_whatever_the_page_says(): void
    {
        $owner = $this->member('100.64.3.1', 'browser_phone_ua_owner_0123456789a');
        $this->openDemo($owner, 'browser_phone_ua_owner_0123456789a', self::PHONE, touch: true);
        $this->usedDemo($owner, 300);

        $this->travel(3)->weeks();
        $stranger = $this->member('100.64.3.2', 'browser_phone_ua_stranger_01234567');

        // The page said "not touch", but the browser says it is a phone.
        $this->openDemo($stranger, 'browser_phone_ua_stranger_01234567', self::PHONE, touch: false, extraHeaders: [
            'User-Agent' => 'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36',
        ])->assertJsonPath('quota.used_chars', 0);
    }

    public function test_identical_computers_more_than_a_month_apart_keep_their_own_demo(): void
    {
        $owner = $this->member('10.3.0.1', 'browser_lab_owner_0123456789abcdef');
        $this->openDemo($owner, 'browser_lab_owner_0123456789abcdef', self::COMPUTER);
        $this->usedDemo($owner, 300);

        $this->travel(40)->days();
        $stranger = $this->member('10.3.0.2', 'browser_lab_stranger_0123456789abc');

        $this->openDemo($stranger, 'browser_lab_stranger_0123456789abc', self::COMPUTER)
            ->assertJsonPath('quota.used_chars', 0);
    }

    public function test_the_device_is_remembered_before_the_first_voice_and_a_bad_one_is_ignored(): void
    {
        $member = $this->member('10.4.0.1', 'browser_remembered_0123456789abcdef');
        $this->openDemo($member, 'browser_remembered_0123456789abcdef', self::COMPUTER);

        $this->assertDatabaseHas('member_devices', ['user_id' => $member->id, 'kind' => MemberDevice::DEVICE, 'signal' => self::COMPUTER, 'touch' => false]);
        $this->assertDatabaseHas('member_devices', ['user_id' => $member->id, 'kind' => MemberDevice::BROWSER, 'signal' => 'browser_remembered_0123456789abcdef']);

        $other = $this->member('10.4.0.2', 'browser_bad_header_0123456789abcdef');
        $this->openDemo($other, 'browser_bad_header_0123456789abcdef', extraHeaders: ['X-Client-Device' => 'not-a-fingerprint']);
        $this->assertDatabaseMissing('member_devices', ['user_id' => $other->id, 'kind' => MemberDevice::DEVICE]);
    }

    public function test_a_referral_from_the_same_device_is_held_for_review(): void
    {
        $referrer = $this->member('10.5.0.1', 'browser_referrer_0123456789abcdefgh');
        $this->openDemo($referrer, 'browser_referrer_0123456789abcdefgh', self::COMPUTER);

        $this->travel(30)->minutes();
        $invited = $this->member('172.16.50.5', 'browser_invited_incognito_012345678');
        $this->openDemo($invited, 'browser_invited_incognito_012345678', self::COMPUTER);

        $this->assertContains((int) $referrer->id, app(DemoLinkedAccounts::class)->linkedUserIds($invited));
        $verdict = app(ReferralAbuseGuard::class)->evaluate($referrer, $invited, new ReferralCampaign);
        $this->assertContains('shared_device', $verdict['indicators']);
        $this->assertSame('flag', $verdict['verdict']);
    }

    private function member(string $ip, string $browser): User
    {
        return User::factory()->create([
            'registration_ip' => $ip,
            'last_login_ip' => $ip,
            'registration_device_id' => $browser,
            'last_seen_device_id' => $browser,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * @param  array<string, string>  $extraHeaders
     */
    private function openDemo(User $member, string $browser, ?string $device = null, bool $touch = false, array $extraHeaders = []): TestResponse
    {
        // Each visit is its own browser: nothing carries over from the last one.
        $this->flushHeaders();
        $this->defaultCookies = [];
        $headers = $device === null ? [] : ['X-Client-Device' => $device, 'X-Client-Device-Touch' => $touch ? '1' : '0'];
        $headers += $extraHeaders;

        return $this->withCredentials()->withCookies(['listen_demo_device' => $browser])
            ->withHeaders($headers)
            ->actingAs($member)
            ->postJsonLocalizedRoute('demo.quota', [])
            ->assertOk();
    }

    private function usedDemo(User $member, int $chars): void
    {
        DemoVoiceGeneration::query()->create([
            'user_id' => $member->id,
            'primary_identity_hash' => 'u:'.$member->id,
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
}
