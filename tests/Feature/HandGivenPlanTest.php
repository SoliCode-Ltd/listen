<?php

namespace Tests\Feature;

use App\Filament\Resources\SubscriptionResource\Pages\CreateSubscription;
use App\Filament\Resources\SubscriptionResource\Pages\EditSubscription;
use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BillingSyncService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A plan the owner gives a member from the admin panel stays exactly as set:
 * the billing sync never takes it back or moves its end date.
 */
class HandGivenPlanTest extends TestCase
{
    use RefreshDatabase;

    private Plan $free;

    private Plan $starter;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.key' => '', 'services.stripe.secret' => '']);
        $this->travelTo(Carbon::parse('2026-10-17 10:00:00', 'UTC'));

        $this->free = Plan::query()->where('slug', 'free')->first() ?? Plan::create([
            'slug' => 'free', 'name' => 'FREE', 'price_cents' => 0, 'monthly_minutes' => 5,
            'monthly_chars' => 4500, 'monthly_requests' => 50, 'max_chars_per_request' => 600, 'is_active' => true,
        ]);
        $this->starter = Plan::create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_minutes' => 250,
            'monthly_chars' => 225000, 'monthly_requests' => 3000, 'daily_requests' => 100,
            'max_chars_per_request' => 3000, 'is_active' => true,
        ]);
    }

    private function asAdmin(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function givenPlan(User $user, string $state, string $end): Subscription
    {
        Subscription::query()->where('user_id', $user->id)->delete();

        return Subscription::create([
            'user_id' => $user->id, 'plan_id' => $this->starter->id, 'status' => 'active',
            'billing_payment_state' => $state, 'current_period_end' => Carbon::parse($end, 'UTC'),
        ]);
    }

    public function test_a_plan_given_in_the_admin_is_kept_as_set_even_when_paid_is_picked(): void
    {
        $member = User::factory()->create();
        $this->asAdmin();

        Livewire::test(CreateSubscription::class)
            ->fillForm([
                'user_id' => $member->id,
                'plan_id' => $this->starter->id,
                'status' => 'active',
                'billing_payment_state' => 'paid',
                'current_period_end' => '2026-11-14 08:37:51',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $row = Subscription::query()->where('user_id', $member->id)->latest('id')->first();
        $this->assertSame((int) $this->starter->id, (int) $row->plan_id);
        $this->assertSame('manual_override', $row->billing_payment_state);
    }

    public function test_a_card_member_keeps_the_state_the_admin_picks(): void
    {
        $member = User::factory()->create(['stripe_id' => 'cus_card']);
        BillingSubscription::create([
            'user_id' => $member->id, 'type' => 'default', 'stripe_id' => 'sub_card',
            'stripe_status' => 'active', 'stripe_price' => 'price_starter', 'quantity' => 1,
        ]);
        $this->asAdmin();

        Livewire::test(CreateSubscription::class)
            ->fillForm([
                'user_id' => $member->id,
                'plan_id' => $this->starter->id,
                'status' => 'active',
                'billing_payment_state' => 'paid',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('paid', Subscription::query()->where('user_id', $member->id)->latest('id')->value('billing_payment_state'));
    }

    public function test_editing_a_hand_given_plan_keeps_it_as_set(): void
    {
        $member = User::factory()->create();
        $row = $this->givenPlan($member, 'paid', '2026-11-14 08:37:51');
        $this->asAdmin();

        Livewire::test(EditSubscription::class, ['record' => $row->getRouteKey()])
            ->fillForm(['current_period_end' => '2026-12-14 08:37:51'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('manual_override', $row->fresh()->billing_payment_state);
    }

    public function test_the_sync_never_moves_the_end_date_of_a_plan_with_no_stripe_customer(): void
    {
        // Saved before this fix, as "Paid": the sync used to push it to a month from each visit.
        $member = User::factory()->create();
        $row = $this->givenPlan($member, 'paid', '2026-11-14 08:37:51');

        app(BillingSyncService::class)->syncIfStale($member, true);

        $row->refresh();
        $this->assertSame((int) $this->starter->id, (int) $row->plan_id);
        $this->assertSame('2026-11-14 08:37:51', $row->current_period_end->format('Y-m-d H:i:s'));
    }

    public function test_the_plan_still_ends_on_its_date(): void
    {
        $member = User::factory()->create();
        $this->givenPlan($member, 'paid', '2026-10-10 12:00:00');

        app(BillingSyncService::class)->syncIfStale($member, true);

        $this->assertSame((int) $this->free->id, (int) Subscription::query()->where('user_id', $member->id)->latest('id')->value('plan_id'));
    }

    public function test_a_member_with_an_old_card_keeps_a_plan_given_as_manual_override(): void
    {
        // Has a Stripe customer from an earlier card payment, but no subscription now.
        $member = User::factory()->create(['stripe_id' => 'cus_old_card']);
        $row = $this->givenPlan($member, 'manual_override', '2026-11-14 08:37:51');

        app(BillingSyncService::class)->syncIfStale($member, true);

        $row->refresh();
        $this->assertSame((int) $this->starter->id, (int) $row->plan_id);
        $this->assertSame('2026-11-14 08:37:51', $row->current_period_end->format('Y-m-d H:i:s'));
    }
}
