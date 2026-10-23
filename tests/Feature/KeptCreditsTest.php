<?php

namespace Tests\Feature;

use App\Filament\Resources\PlanResource\Pages\EditPlan;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\KeptCredits;
use App\Services\PlanAccessService;
use App\Support\Credits;
use App\Support\PlanUsageGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lowering a plan's monthly credits (and so its voice minutes): the admin
 * chooses whether the members on it now keep their credits until their paid
 * period ends, or for as long as they stay on the plan. Nobody loses credits
 * in the period they paid for.
 */
class KeptCreditsTest extends TestCase
{
    use RefreshDatabase;

    private Plan $basic;

    private Plan $starter;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.secret' => '']);
        // Some migrations already add these plans: set them as the test needs them.
        $plan = function (string $slug, array $attributes): Plan {
            $plan = Plan::query()->firstOrNew(['slug' => $slug]);
            $plan->forceFill(array_merge(Plan::factory()->raw(), ['slug' => $slug, 'kind' => Plan::KIND_SUBSCRIPTION, 'is_active' => true], $attributes))->save();

            return $plan;
        };
        $plan('free', ['name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 900]);
        // 20 voice minutes a month (18,000 credits).
        $this->basic = $plan('basic', ['name' => 'Basic', 'price_cents' => 200, 'monthly_chars' => 18000, 'monthly_minutes' => 20, 'monthly_requests' => 0]);
        $this->starter = $plan('starter', ['name' => 'Starter', 'price_cents' => 900, 'monthly_chars' => 45000, 'monthly_minutes' => 50, 'monthly_requests' => 0]);
    }

    private function member(Plan $plan, int $daysLeft = 10): User
    {
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        app(PlanAccessService::class)->assignPlan($user, $plan, 'active', now()->addDays($daysLeft), now()->subDays(30 - $daysLeft));

        return $user;
    }

    private function allowance(User $user): int
    {
        return PlanUsageGuard::planChars(app(PlanAccessService::class)->resolveQuotaPlanForUserId((int) $user->id));
    }

    /** The admin lowers Basic to 10 minutes (9,000 credits) in the plan editor. */
    private function lowerBasic(?string $for, int $credits = 9000)
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        return Livewire::test(EditPlan::class, ['record' => $this->basic->getRouteKey()])
            ->fillForm(array_filter(['monthly_chars' => $credits, 'lower_credits_for' => $for], fn ($v) => $v !== null))
            ->call('save');
    }

    public function test_lowering_asks_who_gets_the_lower_credits(): void
    {
        $this->member($this->basic);

        $this->lowerBasic(null)->assertHasFormErrors(['lower_credits_for' => 'required']);
        $this->assertSame(18000, (int) $this->basic->fresh()->monthly_chars, 'nothing is saved without a choice');

        // Raising needs no choice and keeps nothing.
        Livewire::test(EditPlan::class, ['record' => $this->basic->getRouteKey()])
            ->assertFormFieldIsHidden('lower_credits_for')
            ->fillForm(['monthly_chars' => 27000])
            ->assertFormFieldIsHidden('lower_credits_for')
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(30, (int) $this->basic->fresh()->monthly_minutes);
        $this->assertDatabaseMissing('subscriptions', ['kept_plan_id' => $this->basic->id]);
    }

    public function test_everyone_from_their_next_billing_period(): void
    {
        $current = $this->member($this->basic, daysLeft: 10);

        $this->lowerBasic(KeptCredits::NEXT_PERIOD)->assertHasNoFormErrors()->assertNotified('Credits lowered');

        $this->assertSame(9000, (int) $this->basic->fresh()->monthly_chars);
        $this->assertSame(10, (int) $this->basic->fresh()->monthly_minutes);
        $this->assertSame(18000, $this->allowance($current), 'the period they paid for keeps its 20 minutes');
        $this->assertSame(9000, $this->allowance($this->member($this->basic)), 'a new subscriber gets 10 minutes');

        // Their plan renews: the new period has the new amount.
        $this->travel(11)->days();
        app(PlanAccessService::class)->assignPlan($current, $this->basic, 'active', now()->addMonth(), now());
        $this->assertSame(9000, $this->allowance($current));
    }

    public function test_new_subscribers_only(): void
    {
        $current = $this->member($this->basic, daysLeft: 10);

        $this->lowerBasic(KeptCredits::NEW_ONLY)->assertHasNoFormErrors();

        $this->assertSame(18000, $this->allowance($current));
        $this->assertSame(9000, $this->allowance($this->member($this->basic)));

        // Renewals keep it, month after month.
        $this->travel(11)->days();
        app(PlanAccessService::class)->assignPlan($current, $this->basic, 'active', now()->addMonth(), now());
        $this->assertSame(18000, $this->allowance($current));

        // Another plan, then back to Basic: they come back as a new subscriber.
        app(PlanAccessService::class)->assignPlan($current, $this->starter, 'active', now()->addMonth(), now());
        $this->assertSame(45000, $this->allowance($current));
        app(PlanAccessService::class)->assignPlan($current, $this->basic, 'active', now()->addMonth(), now());
        $this->assertSame(9000, $this->allowance($current));
    }

    public function test_a_member_who_comes_back_long_after_the_plan_ended_is_a_new_subscriber(): void
    {
        $current = $this->member($this->basic, daysLeft: 10);
        $this->lowerBasic(KeptCredits::NEW_ONLY);

        $this->travel(40)->days();
        app(PlanAccessService::class)->assignPlan($current, $this->basic, 'active', now()->addMonth(), now());

        $this->assertSame(9000, $this->allowance($current));
    }

    public function test_the_kept_credits_are_used_for_the_limit_and_the_minutes(): void
    {
        $current = $this->member($this->basic);
        $this->lowerBasic(KeptCredits::NEXT_PERIOD);

        $quota = app(PlanAccessService::class)->resolveQuotaPlanForUserId((int) $current->id);
        $this->assertSame($this->basic->id, $quota->id, 'still the Basic plan');
        $this->assertSame(20, (int) $quota->monthly_minutes);
        $this->assertSame(Credits::minutesFor(18000), (int) $quota->monthly_minutes);
        $this->assertSame(9000, (int) $this->basic->fresh()->monthly_chars, 'the plan itself is not touched');
    }

    public function test_lowered_twice_members_keep_the_most_they_had_and_raising_it_again_gives_the_plan(): void
    {
        $current = $this->member($this->basic);

        $this->lowerBasic(KeptCredits::NEW_ONLY, 13500);
        $this->lowerBasic(KeptCredits::NEXT_PERIOD, 9000);
        $this->assertSame(18000, $this->allowance($current), 'kept for good stays for good');

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Livewire::test(EditPlan::class, ['record' => $this->basic->getRouteKey()])
            ->fillForm(['monthly_chars' => 27000])->call('save')->assertHasNoFormErrors();
        $this->assertSame(27000, $this->allowance($current), 'more than they kept: the plan gives it');
    }
}
