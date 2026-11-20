<?php

namespace Tests\Feature;

use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\ManualUpgrade;
use App\Services\LocalizedPricingService;
use App\Services\ManualPaymentService;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * Moving up to a higher plan by InstaPay or Vodafone Cash works as a card upgrade
 * does (App\Services\Billing\ManualUpgrade): the member pays the new plan's price
 * less what the unused credits of the plan they paid by hand are worth, and the new
 * plan starts when the payment is approved, with a month of its own and its full
 * credits.
 */
class ManualUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private Plan $basic;

    private Plan $starter;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        // Migrations may already seed some of these plans: set them as these tests need them.
        $plan = fn (string $slug, string $name, int $cents, int $chars): Plan => tap(
            Plan::query()->where('slug', $slug)->first() ?? Plan::factory()->make(['slug' => $slug]),
            fn (Plan $p) => $p->forceFill(['name' => $name, 'price_cents' => $cents, 'monthly_chars' => $chars, 'monthly_requests' => 0, 'is_active' => true, 'discount_percentage' => null, 'discount_ends_at' => null])->save(),
        );
        $plan('free', 'Free', 0, 5000);
        $this->basic = $plan('basic', 'Basic', 400, 18000);
        $this->starter = $plan('starter', 'Starter', 900, 90000);
        $this->reviewer = User::factory()->create(['is_admin' => true]);

        SiteSetting::set('manual_instapay_enabled', '1', 'billing');
        SiteSetting::set('manual_instapay_number', '0123456789', 'billing');
        SiteSetting::set('manual_vodafone_cash_enabled', '1', 'billing');
        SiteSetting::set('manual_vodafone_cash_number', '01098765432', 'billing');
        SiteSetting::set('pricing_exchange_rate_EGP', '52.35', 'billing');
        SiteSetting::set(CreditPeriod::SWITCH_SETTING, '2026-11-01 00:00:00', 'billing');
    }

    public function test_moving_up_by_hand_pays_the_difference_and_starts_one_month_now(): void
    {
        $user = User::factory()->create();

        // Basic paid by InstaPay and approved.
        $this->travelTo(Carbon::parse('2026-11-06 19:15:56'));
        $this->confirm($user, $this->basic)->assertRedirect();
        $this->travelTo(Carbon::parse('2026-11-06 19:16:25'));
        app(ManualPaymentService::class)->approve($this->latestRequest($user), $this->reviewer);
        $this->spend($user, 3000);
        $basicPaid = $this->latestRequest($user)->expected_amount_minor;

        // 24 minutes later, Starter by Vodafone Cash: its price less what the 15,000 credits
        // not used on Basic (of 18,000) are worth, in whole pounds, and the payment page says so.
        $this->travelTo(Carbon::parse('2026-11-06 19:40:08'));
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class])
            ->actingAs($user)->withHeaders(['CF-IPCountry' => 'EG'])
            ->get($this->localizedRoute('billing.manual.instructions').'?plan=starter&billing_cycle=monthly&payment_method=vodafone_cash')
            ->assertOk()
            ->assertSee('manual-upgrade-note', false)
            ->assertSee('for the unused credits on your Basic plan')
            ->assertSee(__('ui.billing.manual_upgrade_starts_full'))
            ->assertDontSee('days left');
        $this->confirm($user, $this->starter, 'vodafone_cash')->assertRedirect();
        $request = $this->latestRequest($user);
        $starterPrice = $this->egpPrice($this->starter);
        $back = intdiv($basicPaid * 15000, 18000);
        $back -= $back % 100;
        $this->assertGreaterThan(0, $back);
        $this->assertSame($starterPrice - $back, $request->expected_amount_minor);
        $this->assertSame($back, $request->price_snapshot['upgrade']['credit_minor']);
        $this->assertSame('credits', $request->price_snapshot['upgrade']['by']);
        $this->assertSame(3000, $request->price_snapshot['upgrade']['used']);
        $this->assertSame($starterPrice, $request->price_snapshot['period_value_minor']);

        // Used while the team looks at the transfer: these were given back in the price, so
        // they count on the new plan.
        $this->spend($user, 500);

        // Approved: Starter now, for one month from the approval, not after the Basic month.
        $this->travelTo(Carbon::parse('2026-11-06 19:43:33'));
        app(ManualPaymentService::class)->approve($request, $this->reviewer);
        $plan = Subscription::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame($this->starter->id, (int) $plan->plan_id);
        $this->assertSame('2026-11-06 19:43:33', $plan->current_period_start->toDateTimeString());
        $this->assertSame('2026-12-06 19:43:33', $plan->current_period_end->toDateTimeString());

        // Starter starts with its full credits: nothing of what was used on Basic before
        // the price was worked out, only the 500 used since.
        $counter = CreditPeriod::counter($user->id);
        $this->assertSame('2026-11-06 19:43', $counter->period_ym);
        $this->assertSame(500, (int) $counter->total_chars);
        $this->assertSame(0, (int) $counter->total_requests);
    }

    public function test_the_deduction_follows_the_credits_not_used_whatever_the_day(): void
    {
        $user = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-11-01 10:00:00'));
        $this->confirm($user, $this->basic)->assertRedirect();
        app(ManualPaymentService::class)->approve($this->latestRequest($user), $this->reviewer);
        $paid = $this->latestRequest($user)->expected_amount_minor;
        $wholePounds = fn (int $minor): int => $minor - $minor % 100;
        $upgrade = app(ManualUpgrade::class);

        // Halfway through the month with nothing used: all of what was paid, not half of it.
        $midMonth = Carbon::parse('2026-11-16 10:00:00');
        $this->travelTo($midMonth);
        $credit = $upgrade->credit($user, $this->starter, 'monthly', 'EGP', $midMonth);
        $this->assertSame('credits', $credit['by']);
        $this->assertSame($wholePounds($paid), $credit['credit_minor']);

        // Half the credits used: half of what was paid.
        $this->spend($user, 9000);
        $this->assertSame($wholePounds(intdiv($paid, 2)), $upgrade->credit($user, $this->starter, 'monthly', 'EGP', $midMonth)['credit_minor']);

        // All used, on any day: nothing back, and it is still a move up that starts now.
        $this->spend($user, 9000);
        $credit = $upgrade->credit($user, $this->starter, 'monthly', 'EGP', $midMonth);
        $this->assertSame(0, $credit['credit_minor']);
        $this->assertSame(18000, $credit['used']);
    }

    public function test_a_member_out_of_credits_pays_the_full_price_and_starts_full_at_once(): void
    {
        $user = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-11-03 16:54:00'));
        $this->confirm($user, $this->basic)->assertRedirect();
        app(ManualPaymentService::class)->approve($this->latestRequest($user), $this->reviewer);
        $this->spend($user, 18000);

        // 17 days in with every credit used: nothing comes back, so the full price.
        $this->travelTo(Carbon::parse('2026-11-20 12:40:00'));
        $this->confirm($user, $this->starter)->assertRedirect();
        $request = $this->latestRequest($user);
        $this->assertSame($this->egpPrice($this->starter), $request->expected_amount_minor);
        $this->assertSame(0, $request->price_snapshot['upgrade']['credit_minor']);

        // No line about a deduction of nothing; the page still says the plan starts full.
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class])
            ->actingAs($user)->withHeaders(['CF-IPCountry' => 'EG'])
            ->get($this->localizedRoute('billing.manual.instructions').'?plan=starter&billing_cycle=monthly&payment_method=instapay')
            ->assertOk()
            ->assertDontSee('for the unused credits on your')
            ->assertSee(__('ui.billing.manual_upgrade_starts_full'));

        // Starter starts the day it is approved, not after the Basic month, with all 90,000.
        $this->travelTo(Carbon::parse('2026-11-20 12:42:00'));
        app(ManualPaymentService::class)->approve($request, $this->reviewer);
        $plan = Subscription::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame('2026-11-20 12:42:00', $plan->current_period_start->toDateTimeString());
        $counter = CreditPeriod::counter($user->id);
        $this->assertSame(0, (int) $counter->total_chars);
        $snapshot = \App\Support\UsageSnapshot::for($user->id, app(PlanAccessService::class)->resolveQuotaPlanForUserId($user->id), $counter);
        $this->assertSame(90000, $snapshot['remaining_chars']);
    }

    public function test_a_plan_given_by_the_team_or_the_same_plan_earns_no_deduction(): void
    {
        $upgrade = app(ManualUpgrade::class);

        // Given by the team: nothing was paid for it.
        $given = User::factory()->create();
        app(PlanAccessService::class)->assignPlan($given, $this->basic, 'active', now()->addMonth());
        $this->assertNull($upgrade->credit($given, $this->starter, 'monthly', 'EGP'));
        $this->confirm($given, $this->starter)->assertRedirect();
        $this->assertSame($this->egpPrice($this->starter), $this->latestRequest($given)->expected_amount_minor);
        $this->assertArrayNotHasKey('upgrade', $this->latestRequest($given)->price_snapshot);

        // Paying for the same plan again is a renewal: it follows on, at full price.
        $paid = User::factory()->create();
        $this->confirm($paid, $this->basic)->assertRedirect();
        app(ManualPaymentService::class)->approve($this->latestRequest($paid), $this->reviewer);
        $this->assertNull($upgrade->credit($paid, $this->basic, 'monthly', 'EGP'));
        $this->assertNull($upgrade->credit($paid, $this->basic, 'yearly', 'EGP'));
    }

    public function test_when_what_is_left_covers_the_new_plan_the_team_moves_the_member(): void
    {
        $user = User::factory()->create();
        $this->confirm($user, $this->basic, 'instapay', 'yearly')->assertRedirect();
        app(ManualPaymentService::class)->approve($this->latestRequest($user), $this->reviewer);
        $requests = ManualPaymentRequest::query()->where('user_id', $user->id)->count();

        $this->confirm($user, $this->starter)->assertRedirect()->assertSessionHasErrors('manual_payment');
        $this->assertSame($requests, ManualPaymentRequest::query()->where('user_id', $user->id)->count());
    }

    private function confirm(User $user, Plan $plan, string $method = 'instapay', string $cycle = 'monthly')
    {
        return $this->withoutMiddleware([
            LocaleSessionRedirect::class,
            LaravelLocalizationRedirectFilter::class,
            LaravelLocalizationViewPath::class,
            ValidateCsrfToken::class,
        ])->actingAs($user)
            ->withHeaders(['CF-IPCountry' => 'EG'])
            ->post($this->localizedRoute('billing.manual.confirm'), [
                'plan' => $plan->slug,
                'billing_cycle' => $cycle,
                'payment_method' => $method,
            ]);
    }

    private function latestRequest(User $user): ManualPaymentRequest
    {
        return ManualPaymentRequest::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    private function egpPrice(Plan $plan): int
    {
        return (int) app(LocalizedPricingService::class)->egpPriceQuote($plan, 'monthly')['amount_minor'];
    }

    private function spend(User $user, int $chars): void
    {
        $counter = CreditPeriod::counter($user->id);
        $counter->increment('total_requests');
        $counter->increment('total_chars', $chars);
    }
}
