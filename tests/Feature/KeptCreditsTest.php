<?php

namespace Tests\Feature;

use App\Filament\Resources\PlanResource\Pages\EditPlan;
use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\Subscription;
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
 * chooses whether the members on it now keep their credits for the period
 * they are in, or for as long as they stay on the plan. Nobody loses credits
 * in the period they paid for, however late its renewal is paid.
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

    private function row(User $user): Subscription
    {
        return Subscription::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
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

    public function test_the_free_plan_is_lowered_at_once_without_asking(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $free = Plan::query()->where('slug', 'free')->firstOrFail();

        Livewire::test(EditPlan::class, ['record' => $free->getRouteKey()])
            ->fillForm(['monthly_chars' => 0])
            ->assertFormFieldIsHidden('lower_credits_for')
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(0, (int) $free->fresh()->monthly_chars);
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

    public function test_the_period_lasts_through_a_late_renewal_and_its_grace_days(): void
    {
        $current = $this->member($this->basic, daysLeft: 10);
        $paidUntil = $this->row($current)->current_period_end;
        // Already in the grace days of a failed renewal when the plan is lowered.
        $late = $this->member($this->basic, daysLeft: -2);
        $this->row($late)->update(['billing_payment_state' => 'past_due', 'billing_grace_ends_at' => now()->addDays(5)]);

        $this->lowerBasic(KeptCredits::NEXT_PERIOD)->assertHasNoFormErrors();
        $this->assertSame(18000, $this->allowance($late), 'the grace days are still the period they paid for');

        // The renewal fails: its grace days are still the period paid for.
        $this->travelTo($paidUntil->copy()->addHour());
        $this->row($current)->update(['billing_payment_state' => 'past_due', 'billing_grace_ends_at' => $paidUntil->copy()->addDays(7)]);
        $this->assertSame(18000, $this->allowance($current));

        // Paid at last: the new period has the new amount.
        $this->travel(3)->days();
        app(PlanAccessService::class)->assignPlan($current, $this->basic, 'active', $paidUntil->copy()->addMonth(), $paidUntil);
        $this->assertSame(9000, $this->allowance($current));
        app(PlanAccessService::class)->assignPlan($late, $this->basic, 'active', now()->addMonth(), now());
        $this->assertSame(9000, $this->allowance($late));
    }

    public function test_new_subscribers_only(): void
    {
        $current = $this->member($this->basic, daysLeft: 10);

        $this->lowerBasic(KeptCredits::NEW_ONLY)->assertHasNoFormErrors();

        $this->assertSame(18000, $this->allowance($current));
        $this->assertSame(9000, $this->allowance($this->member($this->basic)));

        // Renewals keep it, month after month, even one paid a few days late.
        $plans = app(PlanAccessService::class);
        $this->travel(13)->days();
        $plans->assignPlan($current, $this->basic, 'active', now()->addMonth(), now());
        $this->assertSame(18000, $this->allowance($current));
        $this->travel(1)->month();
        $plans->assignPlan($current, $this->basic, 'active', now()->addMonth(), now());
        $this->assertSame(18000, $this->allowance($current));

        // Another plan and straight back (changed by mistake): nothing is lost.
        $plans->assignPlan($current, $this->starter, 'active', now()->addMonth(), now());
        $this->assertSame(45000, $this->allowance($current));
        $plans->assignPlan($current, $this->basic, 'active', now()->addMonth(), now());
        $this->assertSame(18000, $this->allowance($current));

        // Another plan for two months, then back to Basic: a new subscriber.
        $plans->assignPlan($current, $this->starter, 'active', now()->addMonth(), now());
        $this->travel(2)->months();
        $plans->assignPlan($current, $this->basic, 'active', now()->addMonth(), now());
        $this->assertSame(9000, $this->allowance($current));
        $this->assertNull($this->row($current)->kept_plan_id);
    }

    public function test_a_renewal_that_arrives_after_the_member_was_moved_to_free_keeps_the_credits(): void
    {
        $current = $this->member($this->basic, daysLeft: 10);
        $paidUntil = $this->row($current)->current_period_end;
        $this->lowerBasic(KeptCredits::NEW_ONLY);

        // The renewal is slow to reach the site: meanwhile the member is put on Free.
        $this->travelTo($paidUntil->copy()->addHours(5));
        app(PlanAccessService::class)->ensureFreePlanForUser($current);
        $this->assertSame('free', app(PlanAccessService::class)->resolveActivePlanForUserId((int) $current->id)->slug);

        app(PlanAccessService::class)->assignPlan($current, $this->basic, 'active', $paidUntil->copy()->addMonth(), $paidUntil);
        $this->assertSame(18000, $this->allowance($current));
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
        $this->assertTrue((bool) $quota->getAttribute('kept_credits'));
        $this->assertSame(20, (int) $quota->monthly_minutes);
        $this->assertSame(Credits::minutesFor(18000), (int) $quota->monthly_minutes);
        $this->assertSame(9000, (int) $this->basic->fresh()->monthly_chars, 'the plan itself is not touched');

        // Their dashboard shows the minutes they keep; a new subscriber's shows the plan's.
        // Routes have no locale prefix in tests (TestCase::localizedRoute).
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ]);
        $minutes = fn (User $user) => collect($this->actingAs($user)->get(route('dashboard'))->assertOk()->viewData('planLines'))->pluck('value');
        $this->assertContains('20 min', $minutes($current));
        $this->assertContains('10 min', $minutes($this->member($this->basic)));
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

        // Lowered once more, for the next period: this period keeps 27,000, the next goes back to what they keep for good.
        $this->lowerBasic(KeptCredits::NEXT_PERIOD, 9000);
        $this->assertSame(27000, $this->allowance($current));
        $this->travel(11)->days();
        app(PlanAccessService::class)->assignPlan($current, $this->basic, 'active', now()->addMonth(), now());
        $this->assertSame(18000, $this->allowance($current));
    }

    public function test_members_in_a_free_trial_not_paid_yet_are_new_subscribers(): void
    {
        $trialing = $this->member($this->basic);
        BillingTrial::query()->create([
            'user_id' => $trialing->id, 'plan_id' => $this->basic->id, 'quota_plan_id' => $this->starter->id, 'status' => BillingTrial::STATUS_TRIALING,
            'stripe_customer_id' => 'cus_kept', 'stripe_subscription_id' => 'sub_kept', 'amount_cents' => 200,
            'started_at' => now()->subDays(2), 'trial_ends_at' => now()->addDays(5),
        ]);
        $paying = $this->member($this->basic);

        $this->lowerBasic(KeptCredits::NEW_ONLY)->assertHasNoFormErrors();

        $this->assertSame(18000, $this->allowance($paying));
        $this->assertNull($this->row($trialing)->kept_plan_id);
        BillingTrial::query()->where('user_id', $trialing->id)->update(['status' => BillingTrial::STATUS_PAID, 'paid_at' => now()]);
        $this->assertSame(9000, $this->allowance($trialing));
    }

    public function test_before_the_database_update_runs_nothing_breaks_and_credits_are_not_lowered(): void
    {
        (require database_path('migrations/2026_10_28_120000_add_kept_credits_to_subscriptions.php'))->down();
        $current = $this->member($this->basic);

        // Renewals and plan changes go on as before.
        app(PlanAccessService::class)->assignPlan($current, $this->starter, 'active', now()->addMonth(), now());
        app(PlanAccessService::class)->assignPlan($current, $this->basic, 'active', now()->addMonth(), now());
        $this->assertSame(18000, $this->allowance($current));

        $this->lowerBasic(KeptCredits::NEW_ONLY)->assertNotified('Credits not lowered');
        $this->assertSame(18000, (int) $this->basic->fresh()->monthly_chars);
    }

    public function test_the_choice_is_asked_again_after_each_save(): void
    {
        $this->member($this->basic);

        $this->lowerBasic(KeptCredits::NEW_ONLY, 13500)
            ->assertHasNoFormErrors()
            ->assertFormSet(['lower_credits_for' => null])
            ->fillForm(['monthly_chars' => 9000])
            ->call('save')
            ->assertHasFormErrors(['lower_credits_for' => 'required']);
    }
}
