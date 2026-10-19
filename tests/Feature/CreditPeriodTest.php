<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A paid plan's monthly credits follow the plan's own dates once the switch is
 * on (App\Support\CreditPeriod). The switch gives nobody new credits and takes
 * none away.
 */
class CreditPeriodTest extends TestCase
{
    use RefreshDatabase;

    private Plan $creator;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::factory()->create(['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 4500, 'monthly_requests' => 0]);
        $this->creator = Plan::factory()->create(['slug' => 'creator', 'name' => 'Creator', 'price_cents' => 1900, 'monthly_chars' => 900000, 'monthly_requests' => 0]);
    }

    public function test_a_credit_month_begun_before_the_switch_counts_by_calendar_month_until_it_ends(): void
    {
        $user = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-10-20 10:05:00'));
        $this->onPlan($user, $this->creator, '2026-10-20 10:00:00', '2026-11-20 10:00:00');
        // October's counter also holds the 1st to the 19th, from the period paid before.
        $this->spend($user, 4000);

        $this->switchOn('2026-10-21 12:00:00');

        $this->travelTo(Carbon::parse('2026-10-25 09:00:00'));
        $period = CreditPeriod::current($user->id);
        $this->assertSame('2026-10', $period['key']);
        $this->assertSame('2026-11-01 00:00:00', $period['ends_at']->toDateTimeString());
        $this->assertSame(4000, CreditPeriod::counter($user->id)->total_chars);

        // The 1st starts a new count, as calendar months always did.
        $this->travelTo(Carbon::parse('2026-11-05 09:00:00'));
        $period = CreditPeriod::current($user->id);
        $this->assertSame('2026-11', $period['key']);
        $this->assertSame('2026-11-01 00:00:00', $period['starts_at']->toDateTimeString());
        $this->assertSame('2026-11-20 10:00:00', $period['ends_at']->toDateTimeString());
        $this->assertSame(0, CreditPeriod::counter($user->id)->total_chars);
        $this->spend($user, 700);

        // The renewal starts the plan's own dates, with a fresh count.
        $this->travelTo(Carbon::parse('2026-11-20 10:20:00'));
        $this->onPlan($user, $this->creator, '2026-11-20 10:00:00', '2026-12-20 10:00:00');
        $this->assertSame('2026-11-20 10:00', CreditPeriod::current($user->id)['key']);
        $this->assertSame(0, CreditPeriod::counter($user->id)->total_chars);
    }

    public function test_a_plan_taken_after_the_switch_follows_its_own_dates(): void
    {
        $this->switchOn('2026-10-01 00:00:00');
        $user = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-10-14 08:40:00'));
        $this->onPlan($user, $this->creator, '2026-10-14 08:37:00', '2026-11-14 08:37:00');

        $this->travelTo(Carbon::parse('2026-11-03 12:00:00'));
        $period = CreditPeriod::current($user->id);
        $this->assertSame('2026-10-14 08:37', $period['key']);
        $this->assertSame('2026-11-14 08:37:00', $period['ends_at']->toDateTimeString());

        // The free plan stays on calendar months.
        $this->assertSame('2026-11', CreditPeriod::current(User::factory()->create()->id)['key']);
    }

    private function switchOn(string $at): void
    {
        SiteSetting::set(CreditPeriod::SWITCH_SETTING, $at, 'billing');
    }

    private function onPlan(User $user, Plan $plan, string $start, string $end): void
    {
        app(PlanAccessService::class)->assignPlan($user, $plan, 'active', Carbon::parse($end), Carbon::parse($start));
    }

    private function spend(User $user, int $chars): void
    {
        CreditPeriod::counter($user->id)->increment('total_chars', $chars);
    }
}
