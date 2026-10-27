<?php

namespace Tests\Feature;

use App\Filament\Resources\UsageCounterResource\Pages\ListUsageCounters;
use App\Filament\Resources\UsageCounterResource\Pages\ViewUsageCounter;
use App\Filament\Widgets\MonthlyUsageBreakdown;
use App\Filament\Widgets\MonthlyUsageChart;
use App\Filament\Widgets\MonthlyUsageStats;
use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TextItem;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Billing\CharGiftService;
use App\Services\PlanAccessService;
use App\Support\GenerationChoiceLabels;
use App\Support\TtsStudioConfig;
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
            ->assertSee('Current period')
            ->assertSee(now()->format('F Y'));

        $this->generation($member, ['billable_chars' => 125000]);
        Livewire::test(MonthlyUsageStats::class)
            ->assertSee('Members who generated')
            ->assertSee('125,000');
    }

    public function test_an_ended_month_also_shows_its_percentage(): void
    {
        $member = User::factory()->create();
        app(PlanAccessService::class)->assignPlan($member, $this->starter);
        $old = UsageCounter::query()->create(['user_id' => $member->id, 'period_ym' => now()->subMonth()->format('Y-m'), 'total_chars' => 250000, 'total_requests' => 9]);

        Livewire::test(ListUsageCounters::class)
            ->filterTable('period_ym', now()->subMonth()->format('Y-m'))
            ->assertCanSeeTableRecords([$old])
            ->assertSee('250,000 of 500,000 (50%)')
            ->assertSee('Ended');

        Livewire::test(ViewUsageCounter::class, ['record' => $old->getRouteKey()])
            ->assertSee('250,000 of 500,000 (50%)')
            ->assertDontSee('Left this month');
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

    public function test_the_numbers_follow_the_month_picked_and_compare_with_the_month_before(): void
    {
        $member = User::factory()->create();
        $last = now()->subMonth()->format('Y-m');
        foreach ([1000, 1000, 1000] as $chars) {
            $this->generation($member, ['billable_chars' => $chars]);
        }
        $this->generation($member, ['billable_chars' => 700000, 'created_at' => now()->subMonth()->startOfMonth()->addDay()]);
        $this->generation($member, ['billable_chars' => 77777, 'created_at' => now()->subMonth()->startOfMonth()->addDays(2)]);

        Livewire::test(MonthlyUsageStats::class)
            ->assertSee('3,000')
            ->assertSee('+50% vs '.now()->subMonth()->format('F Y'));

        Livewire::test(MonthlyUsageStats::class, ['tableFilters' => ['period_ym' => ['value' => $last]]])
            ->assertSee('777,777')
            ->assertDontSee('3,000');
    }

    public function test_admin_accounts_are_left_out_unless_asked_for(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'boss@example.test']);
        $row = UsageCounter::query()->create(['user_id' => $admin->id, 'period_ym' => now()->format('Y-m'), 'total_chars' => 10, 'total_requests' => 1]);

        Livewire::test(ListUsageCounters::class)
            ->assertCanNotSeeTableRecords([$row])
            ->filterTable('members_only', false)
            ->assertCanSeeTableRecords([$row]);
    }

    public function test_the_month_shows_what_members_chose_and_where(): void
    {
        $member = User::factory()->create();
        $emotion = (string) TtsStudioConfig::emotionCatalog()[0]['value'];
        $studio = TextItem::create(['user_id' => $member->id, 'source_type' => 'message', 'content' => 'Hi', 'status' => 'done']);
        $extension = TextItem::create(['user_id' => $member->id, 'source_type' => 'extension', 'content' => 'Hi', 'status' => 'done']);
        $this->generation($member, ['text_item_id' => $studio->id, 'dialect' => 'ar-EG', 'emotion' => $emotion, 'is_dialogue' => true]);
        $this->generation($member, ['text_item_id' => $extension->id, 'translate' => true]);
        $this->generation($member, ['status' => 'failed']);
        $this->generation(User::factory()->create(['is_admin' => true]), ['text_item_id' => $extension->id]);

        Livewire::test(MonthlyUsageBreakdown::class)
            ->assertSee('What members made in '.now()->format('F Y'))
            ->assertSee('2 generations made')
            ->assertSee('Studio')
            ->assertSee('Browser extension')
            ->assertSee('Dialogue (several speakers)')
            ->assertSee('Translated, then read')
            ->assertSee('Egyptian')
            ->assertSee(GenerationChoiceLabels::emotion($emotion));

        Livewire::test(MonthlyUsageChart::class)->assertOk();

        Livewire::test(MonthlyUsageStats::class)
            ->assertSee('Failed generations')
            ->assertSee('33.3% of 3 tries')
            ->assertSee('First-time creators');
    }

    public function test_paying_members_who_made_nothing_are_listed(): void
    {
        $busy = User::factory()->create(['email' => 'busy@example.test']);
        $idle = User::factory()->create(['email' => 'idle@example.test']);
        app(PlanAccessService::class)->assignPlan($busy, $this->starter);
        app(PlanAccessService::class)->assignPlan($idle, $this->starter);
        $this->generation($busy);

        Livewire::test(MonthlyUsageStats::class)
            ->assertSee('1 of 2')
            ->assertSee('1 paying now made nothing');

        Livewire::test(ListUsageCounters::class)
            ->mountAction('idlePaying')
            ->assertSee('idle@example.test')
            ->assertDontSee('busy@example.test');
    }

    public function test_the_details_page_shows_what_the_member_made_in_the_period(): void
    {
        $member = User::factory()->create(['email' => 'maker@example.test']);
        $row = UsageCounter::query()->create(['user_id' => $member->id, 'period_ym' => now()->format('Y-m'), 'total_chars' => 50, 'total_requests' => 2]);
        $this->generation($member, ['dialect' => 'ar-EG', 'duration_seconds' => 12]);
        $this->generation($member, ['duration_seconds' => 8]);
        $this->generation($member, ['created_at' => now()->subMonths(2)]);

        Livewire::test(ViewUsageCounter::class, ['record' => $row->getRouteKey()])
            ->assertSee('What they made')
            ->assertSee('Days with a generation')
            ->assertSee('10.0 s')
            ->assertSee('Egyptian')
            ->assertSee('Their generations');
    }

    public function test_rows_cannot_be_created_or_edited_by_hand(): void
    {
        $this->assertFalse(\App\Filament\Resources\UsageCounterResource::canCreate());
    }

    /** @param  array<string, mixed>  $overrides */
    private function generation(User $user, array $overrides = []): AudioGeneration
    {
        $generation = AudioGeneration::query()->create(array_merge([
            'user_id' => $user->id,
            'input_text' => 'Hello there',
            'source_language' => 'en',
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_name' => 'en-US-Wavenet-C',
            'translate' => false,
            'status' => 'done',
            'billable_chars' => 11,
            'duration_seconds' => 3,
        ], array_diff_key($overrides, ['created_at' => true])));
        if (isset($overrides['created_at'])) {
            $generation->forceFill(['created_at' => $overrides['created_at']])->save();
        }

        return $generation;
    }
}
