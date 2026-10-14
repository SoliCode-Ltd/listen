<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PlanAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SubscriptionPeriodStartTest extends TestCase
{
    use RefreshDatabase;

    private Plan $creator;

    protected function setUp(): void
    {
        parent::setUp();
        Plan::create(['slug' => 'free', 'name' => 'FREE', 'price_cents' => 0, 'monthly_chars' => 1000, 'monthly_requests' => 50, 'is_active' => true]);
        $this->creator = Plan::create(['slug' => 'creator', 'name' => 'Creator', 'price_cents' => 2200, 'monthly_chars' => 450000, 'monthly_requests' => 0, 'is_active' => true]);
    }

    public function test_seeing_the_same_period_again_keeps_when_it_started(): void
    {
        $user = User::factory()->create();
        $access = app(PlanAccessService::class);
        $start = Carbon::parse('2026-09-25 22:18:03');
        $end = Carbon::parse('2026-10-25 22:18:02');
        $access->assignPlan($user, $this->creator, 'active', $end, $start);

        // A later billing refresh with the same plan and period end, and no start given.
        $this->travelTo(Carbon::parse('2026-10-14 05:45:14'));
        $access->assignPlan($user, $this->creator, 'active', $end->copy());

        $sub = Subscription::query()->where('user_id', $user->id)->latest('id')->first();
        $this->assertSame('2026-09-25 22:18:03', $sub->current_period_start->toDateTimeString());
    }

    public function test_a_new_period_moves_the_start(): void
    {
        $user = User::factory()->create();
        $access = app(PlanAccessService::class);
        $access->assignPlan($user, $this->creator, 'active', Carbon::parse('2026-10-25 22:18:02'), Carbon::parse('2026-09-25 22:18:03'));

        // Renewed: Stripe reports the next period with its own start.
        $access->assignPlan($user, $this->creator, 'active', Carbon::parse('2026-11-25 22:18:02'), Carbon::parse('2026-10-25 22:18:02'));
        $sub = Subscription::query()->where('user_id', $user->id)->latest('id')->first();
        $this->assertSame('2026-10-25 22:18:02', $sub->current_period_start->toDateTimeString());

        // A manual renewal (new end, no start given) starts now.
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00'));
        $access->assignPlan($user, $this->creator, 'active', Carbon::parse('2026-12-20 10:00:00'));
        $this->assertSame('2026-11-20 10:00:00', $sub->fresh()->current_period_start->toDateTimeString());
    }
}
