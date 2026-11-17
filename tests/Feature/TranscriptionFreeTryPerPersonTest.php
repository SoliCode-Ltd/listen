<?php

namespace Tests\Feature;

use App\Models\DemoLeftBehind;
use App\Models\SiteSetting;
use App\Models\Transcription;
use App\Models\User;
use App\Services\DemoFraudService;
use App\Services\Transcription\TranscriptionFlow;
use App\Services\Transcription\TranscriptionRefused;
use App\Services\UserDeletionService;
use App\Support\Transcription\TranscriptionAccess;
use App\Support\Transcription\TranscriptionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Speech to text's free try is one for each person, as the demo counts one person
 * (App\Services\DemoSamePerson): not again from a second account in the same browser,
 * nor from accounts made in a row on one network, nor once the first account is
 * deleted; never from an unconfirmed email or an automated or blocked browser. Two
 * people each have their own.
 */
class TranscriptionFreeTryPerPersonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteSetting::set('transcription_audience', TranscriptionSettings::AUDIENCE_EVERYONE, TranscriptionSettings::GROUP);
    }

    public function test_a_second_account_in_the_same_browser_gets_no_second_free_try(): void
    {
        $first = $this->member('198.51.100.60', 'browser_stt_same_0123456789abcdefg', 'stt1@example.com');
        $this->freeTry($first, 'browser_stt_same_0123456789abcdefg');
        $this->assertSame('browser_stt_same_0123456789abcdefg', Transcription::query()->where('user_id', $first->id)->value('browser'), 'the try keeps the browser it was asked from');

        $this->travel(3)->weeks();
        $second = $this->member('203.0.113.60', 'browser_stt_other_0123456789abcdef', 'someone-new@example.org');

        $this->assertFalse(TranscriptionAccess::freeTryLeft($second, $this->request('browser_stt_same_0123456789abcdefg', '203.0.113.60')));
        $this->assertRefused('free_used', $second, 'browser_stt_same_0123456789abcdefg', '203.0.113.60');
    }

    public function test_accounts_made_in_a_row_on_one_network_share_one_free_try(): void
    {
        $first = $this->member('198.51.100.61', 'browser_stt_row_a_0123456789abcdef', 'row1@example.com');
        $this->freeTry($first, 'browser_stt_row_a_0123456789abcdef');
        $this->travel(5)->minutes();
        $second = $this->member('198.51.100.61', 'browser_stt_row_b_0123456789abcdef', 'row2@example.com');

        $this->assertFalse(TranscriptionAccess::freeTryLeft($second, $this->request('browser_stt_row_b_0123456789abcdef', '198.51.100.61')));
    }

    public function test_two_people_each_have_their_own_free_try(): void
    {
        $first = $this->member('198.51.100.62', 'browser_stt_one_0123456789abcdefgh', 'person.one@example.com');
        $this->freeTry($first, 'browser_stt_one_0123456789abcdefgh');
        $this->travel(2)->weeks();
        $second = $this->member('203.0.113.62', 'browser_stt_two_0123456789abcdefgh', 'person.two@example.org');

        $this->assertTrue(TranscriptionAccess::freeTryLeft($second, $this->request('browser_stt_two_0123456789abcdefgh', '203.0.113.62')));
        $this->assertFalse(TranscriptionAccess::freeTryLeft($first, $this->request('browser_stt_one_0123456789abcdefgh', '198.51.100.62')), 'and the first has had theirs');
    }

    public function test_a_failed_try_does_not_count(): void
    {
        $member = $this->member('198.51.100.63', 'browser_stt_fail_0123456789abcdefg', 'failed@example.com');
        Transcription::query()->create(['user_id' => $member->id, 'title' => 'x', 'status' => Transcription::FAILED, 'is_free' => true,
            'charged_seconds' => 20, 'period_ym' => 'x', 'browser' => 'browser_stt_fail_0123456789abcdefg']);

        $this->assertTrue(TranscriptionAccess::freeTryLeft($member, $this->request('browser_stt_fail_0123456789abcdefg', '198.51.100.63')));
    }

    public function test_deleting_the_account_that_had_it_does_not_give_its_twin_a_new_one(): void
    {
        $first = $this->member('198.51.100.64', 'browser_stt_twin_0123456789abcdefg', 'twin.stt1@example.com');
        $this->freeTry($first, 'browser_stt_twin_0123456789abcdefg');
        $this->travel(2)->weeks();
        $second = $this->member('203.0.113.64', 'browser_stt_twin_0123456789abcdefg', 'twin.stt2@example.com');

        $this->assertNull(app(UserDeletionService::class)->delete($first, null, withDemoHistory: true));
        $this->assertTrue((bool) DemoLeftBehind::query()->where('former_user_id', $first->id)->value('free_stt_used'), 'kept with the deleted account');

        $this->assertFalse(TranscriptionAccess::freeTryLeft($second->fresh(), $this->request('browser_stt_twin_0123456789abcdefg', '203.0.113.64')));
        $this->travel(1)->week();
        $third = $this->member('192.0.2.64', 'browser_stt_twin_0123456789abcdefg', 'twin.stt3@example.com');
        $this->assertFalse(TranscriptionAccess::freeTryLeft($third, $this->request('browser_stt_twin_0123456789abcdefg', '192.0.2.64')));
    }

    public function test_an_unconfirmed_email_and_a_blocked_browser_get_no_free_try(): void
    {
        $unconfirmed = $this->member('198.51.100.65', 'browser_stt_mail_0123456789abcdefg', 'unconfirmed@example.com');
        $unconfirmed->forceFill(['email_verified_at' => null])->save();
        config(['listen.demo.require_verified_email' => true]);
        $this->assertRefused('verify_email', $unconfirmed->fresh(), 'browser_stt_mail_0123456789abcdefg', '198.51.100.65');

        $blocked = $this->member('198.51.100.66', 'browser_stt_bot_0123456789abcdefgh', 'bot@example.com');
        $fraud = \Mockery::mock(DemoFraudService::class);
        $fraud->shouldReceive('demoBlockReason')->andReturn('automation');
        $this->app->instance(DemoFraudService::class, $fraud);
        $this->assertRefused('free_blocked', $blocked, 'browser_stt_bot_0123456789abcdefgh', '198.51.100.66');
    }

    private function member(string $ip, string $browser, string $email): User
    {
        return User::factory()->create([
            'email' => $email, 'registration_ip' => $ip, 'last_login_ip' => $ip,
            'registration_device_id' => $browser, 'last_seen_device_id' => $browser, 'email_verified_at' => now(),
        ]);
    }

    /** The free try, as the page starts it: from that browser, kept with its marks. */
    private function freeTry(User $member, string $browser): void
    {
        Transcription::query()->create(['user_id' => $member->id, 'title' => 'try', 'status' => Transcription::READY, 'is_free' => true,
            'charged_seconds' => 20, 'period_ym' => 'x'] + TranscriptionAccess::freeTryMarks($this->request($browser, (string) $member->registration_ip)));
    }

    private function assertRefused(string $reason, User $member, string $browser, string $ip): void
    {
        try {
            app(TranscriptionFlow::class)->check($member, 20, 'upload', $this->request($browser, $ip, $member));
            $this->fail("refused: {$reason}");
        } catch (TranscriptionRefused $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    private function request(string $browser, string $ip, ?User $user = null): Request
    {
        $request = Request::create('/dashboard/transcribe/check', 'POST', [], ['listen_demo_device' => $browser], [], ['REMOTE_ADDR' => $ip]);
        if ($user !== null) {
            $request->setUserResolver(fn () => $user);
        }

        return $request;
    }
}
