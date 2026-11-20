<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\ApiKeyUsage;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Billing\CharGiftService;
use App\Services\Billing\UnusedCredits;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use App\Support\UsageSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A move up to a higher plan is counted in credits, not days
 * (App\Services\Billing\UnusedCredits): the member gets back the share of what
 * they paid that their unused plan credits are worth, and the higher plan
 * starts with its full credits. The card and transfer paths that use it are in
 * BillingEdgeCasesTest and ManualUpgradeTest.
 */
class UnusedCreditsTest extends TestCase
{
    use RefreshDatabase;

    private Plan $basic;

    private Plan $starter;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = fn (string $slug, string $name, int $cents, int $chars): Plan => tap(
            Plan::query()->where('slug', $slug)->first() ?? Plan::factory()->make(['slug' => $slug]),
            fn (Plan $p) => $p->forceFill(['name' => $name, 'price_cents' => $cents, 'monthly_chars' => $chars, 'monthly_requests' => 0, 'is_active' => true])->save(),
        );
        $plan('free', 'Free', 0, 5000);
        $this->basic = $plan('basic', 'Basic', 400, 18000);
        $this->starter = $plan('starter', 'Starter', 900, 90000);
        SiteSetting::set(CreditPeriod::SWITCH_SETTING, '2026-10-01 00:00:00', 'billing');
    }

    public function test_the_member_this_rule_was_made_for(): void
    {
        // Basic at half price ($2 paid) on 3 November, 17,490 of its 18,000 credits used by
        // the 20th. Counted in days he got $0.88 back and Starter began 17,490 credits down.
        $user = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-11-03 16:54:00'));
        $this->onPlan($user, $this->basic, '2026-11-03 16:54:00', '2026-12-03 16:54:00');
        $this->spend($user, 17490);

        $this->travelTo(Carbon::parse('2026-11-20 12:42:00'));
        $credits = app(UnusedCredits::class);
        $standing = $credits->standing($user->id);
        $this->assertSame(['period' => '2026-11-03 16:54', 'used' => 17490, 'allowance' => 18000, 'unused' => 510, 'months_left' => 0, 'months_total' => 1], $standing);
        // 510 of 18,000 credits unused: 5 cents of the $2, so Starter costs $8.95 today.
        $this->assertSame(5, UnusedCredits::worth(200, $standing));

        $credits->mark($user->id, $standing);
        $this->onPlan($user, $this->starter, '2026-11-20 12:42:00', '2026-12-20 12:42:00');

        // And Starter starts with all 90,000.
        $usage = $this->usage($user);
        $this->assertSame([90000, 0, 90000], [$usage['plan_chars'], $usage['total_chars'], $usage['remaining_chars']]);
        // Basic's own month keeps its record.
        $this->assertSame(17490, (int) UsageCounter::query()->where('user_id', $user->id)->where('period_ym', '2026-11-03 16:54')->value('total_chars'));
    }

    public function test_what_unused_credits_are_worth(): void
    {
        $month = fn (int $unused, int $allowance = 18000): array => ['unused' => $unused, 'allowance' => $allowance, 'months_left' => 0, 'months_total' => 1];

        $this->assertSame(400, UnusedCredits::worth(400, $month(18000)), 'Nothing used: all of it back.');
        $this->assertSame(200, UnusedCredits::worth(400, $month(9000)));
        $this->assertSame(0, UnusedCredits::worth(400, $month(0)), 'Everything used: nothing back.');
        // Rounded down, never above what was paid, and nothing for a plan that cost nothing.
        $this->assertSame(133, UnusedCredits::worth(400, $month(6000)));
        $this->assertSame(11, UnusedCredits::worth(400, $month(510)));
        $this->assertSame(400, UnusedCredits::worth(400, $month(50000)));
        $this->assertSame(0, UnusedCredits::worth(0, $month(18000)));

        // A year paid at once, in its third month with half that month's credits used: the
        // nine months not begun yet in full, and half of this one.
        $this->assertSame(3800, UnusedCredits::worth(4800, ['unused' => 9000, 'allowance' => 18000, 'months_left' => 9, 'months_total' => 12]));
    }

    public function test_a_plan_paid_for_a_year_counts_the_months_not_begun_yet(): void
    {
        $user = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-10-10 09:00:00'));
        $this->onPlan($user, $this->basic, '2026-10-10 09:00:00', '2027-10-10 09:00:00');

        // In the third credit month (10 December to 10 January), a third of it used.
        $this->travelTo(Carbon::parse('2026-12-20 09:00:00'));
        $this->spend($user, 6000);
        $standing = app(UnusedCredits::class)->standing($user->id);

        $this->assertSame('2026-12-10 09:00', $standing['period']);
        $this->assertSame([9, 12], [$standing['months_left'], $standing['months_total']]);
        $this->assertSame(12000, $standing['unused']);
        // $48 paid for the year: nine months ($36) and two thirds of this one ($2.66).
        $this->assertSame(3866, UnusedCredits::worth(4800, $standing));
    }

    public function test_a_settled_move_up_starts_afresh_with_what_is_left_of_gifts_and_fresh_key_budgets(): void
    {
        $user = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-11-03 10:00:00'));
        $this->onPlan($user, $this->basic, '2026-11-03 10:00:00', '2026-12-03 10:00:00');
        app(CharGiftService::class)->grant(Subscription::query()->where('user_id', $user->id)->sole(), 5000, 'Sorry for the outage');
        $key = ApiKey::issue($user, 'Agency', null, 20000)['key'];
        ApiKeyUsage::apply($key->id, CreditPeriod::current($user->id)['key'], 3, 12000);
        // All 18,000 of the plan's credits and 2,000 of the gift.
        $this->spend($user, 20000);

        $this->travelTo(Carbon::parse('2026-11-10 10:00:00'));
        $credits = app(UnusedCredits::class);
        $standing = $credits->standing($user->id);
        $this->assertSame(0, $standing['unused'], 'Gift credits are not paid back.');
        $credits->mark($user->id, $standing);
        $this->onPlan($user, $this->starter, '2026-11-10 10:00:00', '2026-12-10 10:00:00');

        // Starter's 90,000 and the 3,000 still left of the gift.
        $usage = $this->usage($user);
        $this->assertSame(5000, $usage['gift_chars']);
        $this->assertSame(2000, $usage['total_chars']);
        $this->assertSame(93000, $usage['remaining_chars']);
        // The key's own budget starts again with the new plan's credits.
        $this->assertSame(0, $key->creditsUsed());
    }

    public function test_a_change_of_plan_nobody_paid_back_still_carries_the_usage_on(): void
    {
        // Moved by the team, or down and back up: no mark, so no fresh allowance (CreditPeriodTest).
        $user = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-11-03 10:00:00'));
        $this->onPlan($user, $this->basic, '2026-11-03 10:00:00', '2026-12-03 10:00:00');
        $this->spend($user, 12000);

        $this->travelTo(Carbon::parse('2026-11-10 10:00:00'));
        $this->onPlan($user, $this->starter, '2026-11-10 10:00:00', '2026-12-10 10:00:00');

        $this->assertSame(12000, $this->usage($user)['total_chars']);
    }

    public function test_a_mark_whose_payment_never_came_does_not_last(): void
    {
        $user = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-11-03 10:00:00'));
        $this->onPlan($user, $this->basic, '2026-11-03 10:00:00', '2026-12-03 10:00:00');
        $this->spend($user, 12000);
        $credits = app(UnusedCredits::class);
        $credits->mark($user->id, $credits->standing($user->id));

        // The bank's check was never passed. Two days later the team moves the member up by hand.
        $this->travelTo(Carbon::parse('2026-11-05 10:00:01'));
        $this->onPlan($user, $this->starter, '2026-11-05 10:00:01', '2026-12-05 10:00:01');

        $this->assertSame(12000, $this->usage($user)['total_chars']);
    }

    public function test_a_mark_taken_back_leaves_no_trace(): void
    {
        $user = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-11-03 10:00:00'));
        $this->onPlan($user, $this->basic, '2026-11-03 10:00:00', '2026-12-03 10:00:00');
        $this->spend($user, 12000);
        $credits = app(UnusedCredits::class);
        $standing = $credits->standing($user->id);
        $credits->mark($user->id, $standing);
        $this->assertSame(12000, CreditPeriod::counter($user->id)->credited_chars);

        $credits->unmark($user->id, $standing['period']);

        $counter = CreditPeriod::counter($user->id);
        $this->assertNull($counter->credited_chars);
        $this->assertNull($counter->credited_at);
        $this->assertSame(12000, (int) $counter->total_chars);
    }

    public function test_nothing_is_counted_in_credits_without_plan_credits_or_plan_dated_periods(): void
    {
        $credits = app(UnusedCredits::class);

        // The free plan's credits were not paid for: whatever is unused is worth nothing.
        $free = User::factory()->create();
        $this->assertSame(0, UnusedCredits::worth(0, $credits->standing($free->id)));

        // Credits still counted by calendar month.
        $user = User::factory()->create();
        $this->onPlan($user, $this->basic, now()->subDay()->toDateTimeString(), now()->addMonth()->toDateTimeString());
        SiteSetting::set(CreditPeriod::SWITCH_SETTING, '', 'billing');
        $this->assertNull($credits->standing($user->id));
    }

    private function onPlan(User $user, Plan $plan, string $start, string $end): void
    {
        app(PlanAccessService::class)->assignPlan($user, $plan, 'active', Carbon::parse($end), Carbon::parse($start));
    }

    private function spend(User $user, int $chars): void
    {
        $counter = CreditPeriod::counter($user->id);
        $counter->increment('total_requests');
        $counter->increment('total_chars', $chars);
    }

    private function usage(User $user): array
    {
        return UsageSnapshot::for($user->id, app(PlanAccessService::class)->resolveQuotaPlanForUserId($user->id), CreditPeriod::counter($user->id));
    }
}
