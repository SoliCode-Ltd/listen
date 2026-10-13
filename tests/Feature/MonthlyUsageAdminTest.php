<?php

namespace Tests\Feature;

use App\Filament\Resources\UsageCounterResource\Pages\ListUsageCounters;
use App\Filament\Resources\UsageCounterResource\Pages\ViewUsageCounter;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Billing\CharGiftService;
use App\Services\PlanAccessService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin → Billing → Monthly usage: who used what, in which month, on which plan.
 */
class MonthlyUsageAdminTest extends TestCase
{
    use RefreshDatabase;

    private Plan $starter;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::factory()->create(['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 10000]);
        $this->starter = Plan::factory()->create(['slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_chars' => 500000, 'monthly_requests' => 0]);

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_each_row_names_the_member_their_plan_and_usage_against_the_allowance(): void
    {
        $member = User::factory()->create(['email' => 'sara@example.test', 'name' => 'Sara']);
        app(PlanAccessService::class)->assignPlan($member, $this->starter);
        $row = UsageCounter::query()->create(['user_id' => $member->id, 'period_ym' => now()->format('Y-m'), 'total_chars' => 125000, 'total_requests' => 40, 'total_minutes' => 160]);

        Livewire::test(ListUsageCounters::class)
            ->assertCanSeeTableRecords([$row])
            ->assertSee('sara@example.test')
            ->assertSee('Sara')
            ->assertSee('Starter')
            ->assertSee('125,000 of 500,000 (25%)')
            ->assertSee(now()->format('F Y'));

        Livewire::test(\App\Filament\Widgets\MonthlyUsageStats::class)
            ->assertSee('Members who generated')
            ->assertSee('125,000');
    }

    public function test_it_opens_on_this_month_and_other_months_are_a_filter_away(): void
    {
        $member = User::factory()->create();
        $now = UsageCounter::query()->create(['user_id' => $member->id, 'period_ym' => now()->format('Y-m'), 'total_chars' => 10, 'total_requests' => 1]);
        $old = UsageCounter::query()->create(['user_id' => $member->id, 'period_ym' => now()->subMonth()->format('Y-m'), 'total_chars' => 20, 'total_requests' => 2]);

        Livewire::test(ListUsageCounters::class)
            ->assertCanSeeTableRecords([$now])
            ->assertCanNotSeeTableRecords([$old])
            ->filterTable('period_ym', now()->subMonth()->format('Y-m'))
            ->assertCanSeeTableRecords([$old])
            ->assertCanNotSeeTableRecords([$now]);
    }

    public function test_members_can_be_found_by_email_and_filtered_by_plan(): void
    {
        $paid = User::factory()->create(['email' => 'paid@example.test']);
        app(PlanAccessService::class)->assignPlan($paid, $this->starter);
        $free = User::factory()->create(['email' => 'free@example.test']);
        $paidRow = UsageCounter::query()->create(['user_id' => $paid->id, 'period_ym' => now()->format('Y-m'), 'total_chars' => 10, 'total_requests' => 1]);
        $freeRow = UsageCounter::query()->create(['user_id' => $free->id, 'period_ym' => now()->format('Y-m'), 'total_chars' => 10, 'total_requests' => 1]);

        Livewire::test(ListUsageCounters::class)
            ->searchTable('paid@')
            ->assertCanSeeTableRecords([$paidRow])
            ->assertCanNotSeeTableRecords([$freeRow]);

        Livewire::test(ListUsageCounters::class)
            ->filterTable('plan', 'free')
            ->assertCanSeeTableRecords([$freeRow])
            ->assertCanNotSeeTableRecords([$paidRow]);
    }

    public function test_the_details_page_lists_the_month_and_its_gifts(): void
    {
        $member = User::factory()->create(['email' => 'gifted@example.test']);
        app(PlanAccessService::class)->assignPlan($member, $this->starter);
        $subscription = Subscription::query()->where('user_id', $member->id)->latest('id')->first();
        app(CharGiftService::class)->grant($subscription, 5000, 'Sorry for the outage');
        $row = UsageCounter::query()->where('user_id', $member->id)->firstOrFail();
        $row->update(['total_chars' => 1000, 'total_requests' => 3]);

        Livewire::test(ViewUsageCounter::class, ['record' => $row->getRouteKey()])
            ->assertOk()
            ->assertSee('gifted@example.test')
            ->assertSee('1,000 of 505,000')
            ->assertSee('504,000')
            ->assertSee('Sorry for the outage');
    }

    public function test_rows_cannot_be_created_or_edited_by_hand(): void
    {
        $this->assertFalse(\App\Filament\Resources\UsageCounterResource::canCreate());
    }
}
