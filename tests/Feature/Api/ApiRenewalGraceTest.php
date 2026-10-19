<?php

namespace Tests\Feature\Api;

use App\Models\BillingSubscription;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Api\ApiAccountSummary;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The developer API's account answers while a failed renewal's grace days
 * run: the plan, its limits and the credits of the month paid for go on, and
 * the answer says the payment is past due and until when the plan stays on.
 */
class ApiRenewalGraceTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    private User $member;

    private string $plain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
        // Credits that follow plan dates since before the month below began.
        SiteSetting::set(CreditPeriod::SWITCH_SETTING, '2026-09-01 00:00:00', 'billing');

        // Paid for the month from 14 September; its renewal on 14 October failed.
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00', 'UTC'));
        $this->member = User::factory()->create(['subscription_status_checked_at' => now()]);
        app(PlanAccessService::class)->assignPlan($this->member, $this->apiPlan(), 'active', Carbon::parse('2026-10-14 10:00:00'), Carbon::parse('2026-09-14 10:00:00'));
        BillingSubscription::query()->create([
            'user_id' => $this->member->id, 'type' => 'default', 'stripe_id' => 'sub_api_grace',
            'stripe_status' => 'past_due', 'stripe_price' => 'price_creator_m', 'quantity' => 1,
        ]);
        CreditPeriod::counter((int) $this->member->id)->increment('total_chars', 30000);
        $this->plain = $this->issueKey($this->member)['plain'];

        $this->travelTo(Carbon::parse('2026-10-14 11:05:00', 'UTC'));
        Subscription::query()->where('user_id', $this->member->id)->update([
            'billing_payment_state' => 'past_due',
            'billing_grace_ends_at' => Carbon::parse('2026-10-21 11:05:00'),
        ]);
        $this->travelTo(Carbon::parse('2026-10-16 09:00:00', 'UTC'));
        $this->member->forceFill(['subscription_status_checked_at' => now()])->save();
    }

    public function test_the_subscription_says_past_due_and_until_when_the_plan_stays_on(): void
    {
        $body = $this->apiGet($this->plain, '/user/subscription')->assertOk()->json();

        $this->assertSame('creator', $body['plan']['slug']);
        $this->assertSame('past_due', $body['status']);
        $this->assertSame('2026-10-21T11:05:00+00:00', $body['current_period_end']);
        // The credits of the month paid for, with what was used of them.
        $this->assertSame(30000, $body['credits']['used']);
        $this->assertSame(90000, $body['credits']['limit']);
        $this->assertSame('2026-09-14', $body['credits']['period']);
        $this->assertSame('2026-09-14T10:00:00+00:00', $body['credits']['period_starts_at']);
        $this->assertSame('2026-10-21T11:05:00+00:00', $body['credits']['resets_at']);
        $this->assertSame(Carbon::parse('2026-10-21 11:05:00')->getTimestamp(), $body['credits']['resets_at_unix']);
        // The plan's own limits go on.
        $this->assertSame(60, $body['limits']['requests_per_minute']);
        $this->assertSame(10000, $body['limits']['max_characters_per_async_request']);
    }

    public function test_the_dashboard_says_the_plan_is_active_until_the_grace_ends(): void
    {
        $summary = app(ApiAccountSummary::class)->for($this->member);

        $this->assertTrue($summary['plan_ends']);
        $this->assertEquals(Carbon::parse('2026-10-21 11:05:00'), $summary['resets_at']);
        $this->assertSame(30000, (int) $summary['usage']['total_chars']);
    }

    public function test_once_the_grace_ran_out_the_plan_and_its_api_access_are_gone(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 11:10:00', 'UTC'));
        $this->member->forceFill(['subscription_status_checked_at' => now()])->save();

        $this->assertApiError($this->apiGet($this->plain, '/user/subscription'), 403, 'api_access_not_in_plan');
    }
}
