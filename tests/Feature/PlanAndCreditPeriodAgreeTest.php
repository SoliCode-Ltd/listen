<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The plan a member is on and the credit month their usage counts in must
 * always agree, including in the two hours a card renewal may take to arrive:
 * otherwise a member is held to one plan's limits on another plan's counter.
 */
class PlanAndCreditPeriodAgreeTest extends TestCase
{
    use RefreshDatabase;

    private Plan $creator;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::factory()->create(['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 4500]);
        $this->creator = Plan::factory()->create(['slug' => 'creator', 'name' => 'Creator', 'price_cents' => 1900, 'monthly_chars' => 900000]);
        SiteSetting::set(CreditPeriod::SWITCH_SETTING, '2026-09-01 00:00:00', 'billing');
    }

    public function test_a_card_renewal_on_its_way_keeps_both_the_plan_and_its_credit_month(): void
    {
        $user = $this->cardMember(endsAt: null);

        $this->travelTo(Carbon::parse('2026-11-14 11:00:00'));

        $this->assertSame('creator', $this->planSlug($user));
        $this->assertSame('2026-10-14 10:00', CreditPeriod::current($user->id)['key']);

        // Past the two hours with no renewal: both are back to the free plan's calendar month.
        $this->travelTo(Carbon::parse('2026-11-14 12:30:00'));
        $this->assertNotSame('creator', $this->planSlug($user));
        $this->assertSame('2026-11', CreditPeriod::current($user->id)['key']);
    }

    public function test_a_subscription_cancelled_to_end_gets_no_extra_time_in_either(): void
    {
        $user = $this->cardMember(endsAt: '2026-11-14 10:00:00');

        $this->travelTo(Carbon::parse('2026-11-14 11:00:00'));

        $this->assertNotSame('creator', $this->planSlug($user));
        $this->assertSame('2026-11', CreditPeriod::current($user->id)['key']);
    }

    private function cardMember(?string $endsAt): User
    {
        $user = User::factory()->create(['stripe_id' => 'cus_agree']);
        $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
        app(PlanAccessService::class)->assignPlan($user, $this->creator, 'active', Carbon::parse('2026-11-14 10:00:00'), Carbon::parse('2026-10-14 10:00:00'));
        BillingSubscription::query()->create([
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => 'sub_agree',
            'stripe_status' => 'active',
            'ends_at' => $endsAt ? Carbon::parse($endsAt) : null,
        ]);

        return $user;
    }

    private function planSlug(User $user): ?string
    {
        return app(PlanAccessService::class)->resolveActivePlanForUserId($user->id)?->slug;
    }
}
