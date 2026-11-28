<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Models\PlanChange;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\SubscriptionCancellation;
use App\Models\User;
use App\Services\Billing\PlanChanges;
use App\Services\BillingSyncService;
use App\Services\ManualPaymentService;
use App\Services\PlanAccessService;
use App\Services\Referral\ReferralQualificationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * Upgrades now, downgrades at renewal (App\Services\Billing\PlanChanges): a lower plan
 * costs nothing today, the member keeps the plan they paid for until its period ends,
 * and the lower plan starts then, on a card, on a trial, or after a plan paid by hand.
 */
class ScheduledDowngradeTest extends TestCase
{
    use RefreshDatabase;

    private Plan $starter;

    private Plan $creator;

    private Plan $pro;

    private DowngradeStripeFake $stripe;

    private int $periodStart;

    private int $periodEnd;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00', 'UTC'));

        config([
            'services.stripe.key' => 'pk_test_fake',
            'services.stripe.secret' => 'sk_test_fake',
            'cashier.key' => 'pk_test_fake',
            'cashier.secret' => 'sk_test_fake',
        ]);
        SiteSetting::set('pricing_forced_country_code', 'US', 'billing');

        Plan::create([
            'slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 10000,
            'monthly_minutes' => 10, 'monthly_requests' => 60, 'max_chars_per_request' => 1200,
            'allow_overage' => false, 'is_active' => true,
        ]);
        $this->starter = Plan::create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_chars' => 500000,
            'monthly_minutes' => 120, 'monthly_requests' => 1000, 'max_chars_per_request' => 5000,
            'allow_overage' => false, 'is_active' => true,
            'stripe_price_monthly' => 'price_starter_m', 'stripe_price_yearly' => 'price_starter_y',
        ]);
        $this->creator = Plan::create([
            'slug' => 'creator', 'name' => 'Creator', 'price_cents' => 2200, 'monthly_chars' => 1500000,
            'monthly_minutes' => 300, 'monthly_requests' => 3000, 'max_chars_per_request' => 10000,
            'allow_overage' => true, 'is_active' => true,
            'stripe_price_monthly' => 'price_creator_m', 'stripe_price_yearly' => 'price_creator_y',
        ]);
        $this->pro = Plan::create([
            'slug' => 'pro', 'name' => 'Pro', 'price_cents' => 4900, 'monthly_chars' => 4000000,
            'monthly_minutes' => 800, 'monthly_requests' => 8000, 'max_chars_per_request' => 20000,
            'allow_overage' => true, 'is_active' => true,
            'stripe_price_monthly' => 'price_pro_m', 'stripe_price_yearly' => 'price_pro_y',
        ]);

        $referrals = Mockery::mock(ReferralQualificationService::class);
        $referrals->shouldReceive('evaluate')->andReturnNull();
        $referrals->shouldReceive('isFirstPaidPlanTransition')->andReturnFalse();
        $this->app->instance(ReferralQualificationService::class, $referrals);

        $this->periodStart = Carbon::parse('2026-10-20 08:00:00', 'UTC')->getTimestamp();
        $this->periodEnd = Carbon::parse('2026-11-20 08:00:00', 'UTC')->getTimestamp();
        $this->stripe = new DowngradeStripeFake($this->periodStart, $this->periodEnd);
        ApiRequestor::setHttpClient($this->stripe);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** A member paying by card for a plan, mid-period. */
    private function cardMember(string $price = 'price_creator_m', string $status = 'active', ?int $trialEnds = null, bool $cancelAtPeriodEnd = false): User
    {
        $user = User::factory()->create();
        $user->forceFill(['stripe_id' => 'cus_dn'])->save();
        $plan = str_starts_with($price, 'price_pro') ? $this->pro : (str_starts_with($price, 'price_starter') ? $this->starter : $this->creator);
        app(PlanAccessService::class)->assignPlan($user, $plan, 'active',
            Carbon::createFromTimestamp($trialEnds ?? $this->periodEnd), Carbon::createFromTimestamp($this->periodStart));

        $this->stripe->subscription = [
            'price' => $price, 'status' => $status, 'trial_end' => $trialEnds,
            'cancel_at_period_end' => $cancelAtPeriodEnd, 'schedule' => null, 'discounts' => [],
        ];

        $subscription = BillingSubscription::query()->create([
            'user_id' => $user->id, 'type' => 'default', 'stripe_id' => 'sub_dn', 'stripe_status' => $status,
            'stripe_price' => $price, 'quantity' => 1,
            'trial_ends_at' => $trialEnds ? Carbon::createFromTimestamp($trialEnds) : null,
            'ends_at' => $cancelAtPeriodEnd ? Carbon::createFromTimestamp($trialEnds ?? $this->periodEnd) : null,
        ]);
        $subscription->items()->create(['stripe_id' => 'si_dn', 'stripe_product' => 'prod_x', 'stripe_price' => $price, 'quantity' => 1]);

        return $user;
    }

    /** A member whose plan was paid by InstaPay (or given by the team), running until a date. */
    private function handPaidMember(Plan $plan, Carbon $until, bool $paid = true): User
    {
        $user = User::factory()->create();
        $row = app(PlanAccessService::class)->assignPlan($user, $plan, 'active', $until, $until->copy()->subMonth());
        $row->forceFill(['billing_payment_state' => 'manual_override'])->save();
        if ($paid) {
            ManualPaymentRequest::query()->create([
                'user_id' => $user->id, 'plan_id' => $plan->id, 'billing_cycle' => 'monthly',
                'payment_method' => 'instapay', 'expected_amount_minor' => 100000, 'currency' => 'EGP',
                'country_code' => 'EG', 'status' => ManualPaymentRequest::STATUS_APPROVED,
                'approved_subscription_id' => $row->id, 'submitted_at' => now()->subMonth(), 'reviewed_at' => now()->subMonth(),
            ]);
        }

        return $user;
    }

    private function without(): array
    {
        return [
            ValidateCsrfToken::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ];
    }

    private function subscribe(User $user, string $plan, string $cycle = 'monthly', array $extra = [])
    {
        return $this->actingAs($user)->withoutMiddleware($this->without())
            ->postJson($this->localizedRoute('billing.subscribe'), ['plan' => $plan, 'billing_cycle' => $cycle] + $extra);
    }

    private function preview(User $user, string $plan, string $cycle = 'monthly')
    {
        return $this->actingAs($user)->withoutMiddleware($this->without())
            ->postJson($this->localizedRoute('billing.plan-change.preview'), ['plan' => $plan, 'billing_cycle' => $cycle]);
    }

    private function keep(User $user)
    {
        return $this->actingAs($user)->withoutMiddleware($this->without())
            ->postJson($this->localizedRoute('billing.plan-change.keep'));
    }

    private function planOf(User $user): string
    {
        return (string) app(PlanAccessService::class)->resolveActivePlanForUserId((int) $user->id)?->slug;
    }

    // ── Which way a move goes ────────────────────────────────────────────────

    public function test_plans_rank_by_their_price_and_yearly_ranks_above_monthly(): void
    {
        $this->assertSame(PlanChanges::DOWN, PlanChanges::direction($this->creator, 'monthly', $this->starter, 'monthly'));
        $this->assertSame(PlanChanges::DOWN, PlanChanges::direction($this->creator, 'monthly', $this->starter, 'yearly'), 'a lower plan, even yearly');
        $this->assertSame(PlanChanges::UP, PlanChanges::direction($this->creator, 'yearly', $this->pro, 'monthly'), 'a higher plan, even monthly');
        $this->assertSame(PlanChanges::DOWN, PlanChanges::direction($this->creator, 'yearly', $this->creator, 'monthly'));
        $this->assertSame(PlanChanges::UP, PlanChanges::direction($this->creator, 'monthly', $this->creator, 'yearly'));
        $this->assertSame(PlanChanges::SAME, PlanChanges::direction($this->creator, 'monthly', $this->creator, 'monthly'));
        $this->assertSame(PlanChanges::UP, PlanChanges::direction(null, null, $this->starter, 'monthly'), 'from nothing');
    }

    // ── Card: a lower plan waits for the renewal ─────────────────────────────

    public function test_a_lower_plan_costs_nothing_today_and_starts_at_the_renewal(): void
    {
        $user = $this->cardMember();

        $this->preview($user, 'starter')->assertOk()
            ->assertJsonPath('change', true)
            ->assertJsonPath('scheduled', true)
            ->assertJsonPath('kind', 'renewal')
            ->assertJsonPath('total', '$0.00')
            ->assertJsonPath('total_cents', 0)
            ->assertJsonPath('starts_on', '20 Nov 2026')
            ->assertJsonPath('button', 'Switch on 20 Nov 2026')
            ->assertJsonPath('note', fn (string $note) => str_contains($note, 'You keep Creator until 20 Nov 2026')
                && str_contains($note, 'Starter') && str_contains($note, '$9.00'));
        $this->assertNull($this->stripe->last('post', '/v1/invoices/create_preview'), 'Nothing to preview: nothing is charged today.');

        $this->subscribe($user, 'starter')->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('scheduled', true)
            ->assertJsonPath('charged', false)
            ->assertJsonPath('message', 'Done. You keep Creator until 20 Nov 2026, then Starter starts. Nothing was charged today.');

        // The subscription itself is not changed: a schedule switches it at the renewal.
        $this->assertNull($this->stripe->lastWith('post', '/v1/subscriptions/sub_dn', 'items'), 'The subscription was changed now.');
        $this->assertSame(['from_subscription' => 'sub_dn'], $this->stripe->last('post', '/v1/subscription_schedules'));
        $update = $this->stripe->last('post', '/v1/subscription_schedules/sub_sched_1');
        $this->assertSame('release', $update['end_behavior']);
        $this->assertCount(2, $update['phases']);
        $this->assertSame('price_creator_m', $update['phases'][0]['items'][0]['price']);
        $this->assertSame($this->periodStart, (int) $update['phases'][0]['start_date']);
        $this->assertSame($this->periodEnd, (int) $update['phases'][0]['end_date']);
        $this->assertSame('price_starter_m', $update['phases'][1]['items'][0]['price']);
        $this->assertSame(['interval' => 'month', 'interval_count' => '1'], array_map('strval', $update['phases'][1]['duration']));
        $this->assertSame('none', $update['phases'][1]['proration_behavior']);
        $this->assertSame('starter', $update['phases'][1]['metadata']['listen_plan_slug']);
        $this->assertSame('pm_card', $update['phases'][1]['default_payment_method'], 'The renewal is charged to the same card.');
        $this->assertArrayNotHasKey('discounts', $update['phases'][1], 'No offer runs on Starter: none is added.');

        $this->assertSame('creator', $this->planOf($user), 'The member keeps the plan they paid for.');
        $change = PlanChange::query()->sole();
        $this->assertSame(PlanChange::STATUS_SCHEDULED, $change->status);
        $this->assertSame(PlanChange::SOURCE_CARD, $change->source);
        $this->assertSame($this->periodEnd, $change->starts_at->getTimestamp());
        $this->assertSame('sub_sched_1', $change->stripe_schedule_id);
        $this->assertSame(900, $change->amount_cents);
    }

    public function test_yearly_to_monthly_on_the_same_plan_waits_for_the_end_of_the_year(): void
    {
        $user = $this->cardMember('price_creator_y');

        $this->preview($user, 'creator', 'monthly')->assertOk()->assertJsonPath('scheduled', true);
        $this->subscribe($user, 'creator', 'monthly')->assertOk()->assertJsonPath('scheduled', true)->assertJsonPath('charged', false);

        $update = $this->stripe->last('post', '/v1/subscription_schedules/sub_sched_1');
        $this->assertSame('price_creator_y', $update['phases'][0]['items'][0]['price']);
        $this->assertSame('price_creator_m', $update['phases'][1]['items'][0]['price']);
        $this->assertSame('monthly', PlanChange::query()->sole()->billing_cycle);
    }

    public function test_a_move_down_pays_the_full_price_even_while_the_lower_plan_has_an_offer(): void
    {
        $this->starter->forceFill(['discount_percentage' => 50, 'discount_ends_at' => now()->addDay(), 'stripe_coupon_id' => 'listen_starter_discount_50'])->save();
        $user = $this->cardMember();

        $this->preview($user, 'starter')->assertOk()
            ->assertJsonPath('note', fn (string $note) => str_contains($note, '$9.00') && ! str_contains($note, '50%'));
        $this->subscribe($user, 'starter')->assertOk();

        // An offer is for joining or moving up: the lower plan's phase carries none.
        $this->assertNull($this->stripe->last('post', '/v1/coupons'), 'No coupon is made for a move down.');
        $this->assertArrayNotHasKey('discounts', $this->stripe->last('post', '/v1/subscription_schedules/sub_sched_1')['phases'][1]);
        $change = PlanChange::query()->sole();
        $this->assertSame(900, $change->amount_cents);
        $line = app(PlanChanges::class)->describe($change->fresh(['toPlan', 'fromPlan']), app(\App\Services\LocalizedPricingService::class)->resolveCountryContext(request()), 'en');
        $this->assertSame('$9.00 a month', str_replace(["\u{2066}", "\u{2069}"], '', $line['terms']));
        $this->assertSame('20 Nov 2026', $line['date']);
        $this->assertTrue($line['can_keep']);
    }

    public function test_the_members_own_offer_runs_to_the_renewal_and_does_not_follow_them_down(): void
    {
        $user = $this->cardMember();
        $this->stripe->subscription['discounts'] = [['coupon' => 'listen_creator_discount_20', 'discount' => 'di_creator']];

        $this->subscribe($user, 'starter')->assertOk();

        $update = $this->stripe->last('post', '/v1/subscription_schedules/sub_sched_1');
        $this->assertSame([['discount' => 'di_creator']], $update['phases'][0]['discounts'], 'The month paid for keeps its offer.');
        $this->assertArrayNotHasKey('discounts', $update['phases'][1], 'Starter starts at its full price.');
    }

    public function test_a_failed_schedule_update_leaves_the_subscription_as_it_was(): void
    {
        $user = $this->cardMember();
        $this->stripe->failScheduleUpdate = true;

        $this->subscribe($user, 'starter')->assertStatus(422)->assertJsonPath('ok', false);

        $this->assertNotNull($this->stripe->last('post', '/v1/subscription_schedules/sub_sched_1/release'), 'The new schedule was not let go.');
        $this->assertSame(0, PlanChange::query()->count());
        $this->assertSame('creator', $this->planOf($user));
    }

    public function test_choosing_the_booked_plan_again_changes_nothing(): void
    {
        $user = $this->cardMember();
        $this->subscribe($user, 'starter')->assertOk();
        $calls = count($this->stripe->requests);

        $this->subscribe($user, 'starter')->assertOk()
            ->assertJsonPath('scheduled', true)
            ->assertJsonPath('message', 'Starter is already set to start on 20 Nov 2026.');

        $this->assertNull($this->stripe->lastSince($calls, 'post', '/v1/subscription_schedules/sub_sched_1'));
        $this->assertSame(1, PlanChange::query()->scheduled()->count());
    }

    public function test_choosing_another_lower_plan_replaces_the_booking(): void
    {
        $user = $this->cardMember('price_pro_m');
        $this->subscribe($user, 'creator')->assertOk();
        $this->subscribe($user, 'starter')->assertOk();

        // The same schedule, updated in place: one renewal, one plan.
        $this->assertCount(1, $this->stripe->all('post', '/v1/subscription_schedules'));
        $this->assertSame('price_starter_m', $this->stripe->last('post', '/v1/subscription_schedules/sub_sched_1')['phases'][1]['items'][0]['price']);
        $this->assertSame(['cancelled', 'scheduled'], PlanChange::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame('replaced', PlanChange::query()->orderBy('id')->first()->cancel_reason);
        $this->assertSame($this->starter->id, (int) PlanChange::query()->scheduled()->sole()->to_plan_id);
    }

    public function test_an_upgrade_lets_the_schedule_go_first_and_charges_the_difference(): void
    {
        $user = $this->cardMember();
        $this->subscribe($user, 'starter')->assertOk();
        $released = count($this->stripe->requests);

        $this->subscribe($user, 'pro')->assertOk()->assertJsonPath('ok', true);

        $release = $this->stripe->indexOf('post', '/v1/subscription_schedules/sub_sched_1/release', $released);
        $change = $this->stripe->indexOfWith('post', '/v1/subscriptions/sub_dn', 'items', $released);
        $this->assertNotNull($release, 'The schedule was not let go.');
        $this->assertNotNull($change, 'The upgrade was not made.');
        $this->assertLessThan($change, $release, 'The schedule must go before the subscription changes.');
        $this->assertSame('price_pro_m', $this->stripe->subscription['price']);
        $this->assertSame('upgraded', PlanChange::query()->sole()->cancel_reason);
    }

    public function test_keep_my_plan_calls_off_the_switch(): void
    {
        $user = $this->cardMember();
        $this->subscribe($user, 'starter')->assertOk();

        $this->keep($user)->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'You stay on Creator. Nothing changes on 20 Nov 2026.');

        $this->assertNotNull($this->stripe->last('post', '/v1/subscription_schedules/sub_sched_1/release'));
        $this->assertNull($this->stripe->subscription['schedule']);
        $this->assertSame('kept', PlanChange::query()->sole()->cancel_reason);
        $this->keep($user)->assertStatus(422)->assertJsonPath('message', 'There is no plan change to call off.');
    }

    public function test_choosing_the_running_plan_again_keeps_it(): void
    {
        $user = $this->cardMember('price_creator_y');
        $this->subscribe($user, 'creator', 'monthly')->assertOk();

        $this->subscribe($user, 'creator', 'yearly')->assertOk()
            ->assertJsonPath('charged', false)
            ->assertJsonPath('message', 'You stay on Creator. Nothing changes on 20 Nov 2026.');
        $this->assertSame('kept', PlanChange::query()->sole()->cancel_reason);
    }

    public function test_cancelling_lets_the_schedule_go_before_the_renewal_is_turned_off(): void
    {
        $user = $this->cardMember();
        $this->subscribe($user, 'starter')->assertOk();
        $before = count($this->stripe->requests);

        $this->actingAs($user)->withoutMiddleware($this->without())
            ->postJson($this->localizedRoute('billing.cancel'), ['reason' => 'price'])->assertOk()->assertJsonPath('ok', true);

        $release = $this->stripe->indexOf('post', '/v1/subscription_schedules/sub_sched_1/release', $before);
        $cancel = $this->stripe->indexOfWith('post', '/v1/subscriptions/sub_dn', 'cancel_at_period_end', $before);
        $this->assertNotNull($release);
        $this->assertNotNull($cancel);
        $this->assertLessThan($cancel, $release, 'At the renewal the schedule would have started the lower plan anyway.');
        $this->assertTrue($this->stripe->subscription['cancel_at_period_end']);
        $this->assertSame('cancelled', PlanChange::query()->sole()->cancel_reason);
    }

    public function test_a_lower_plan_undoes_a_cancellation_and_waits_for_the_renewal(): void
    {
        $user = $this->cardMember('price_creator_m', 'active', null, true);

        $this->subscribe($user, 'starter')->assertOk()->assertJsonPath('scheduled', true);

        $resume = $this->stripe->indexOfWith('post', '/v1/subscriptions/sub_dn', 'cancel_at_period_end');
        $schedule = $this->stripe->indexOf('post', '/v1/subscription_schedules');
        $this->assertNotNull($resume);
        $this->assertLessThan($schedule, $resume, 'The cancellation is undone before the schedule is made.');
        $this->assertFalse($this->stripe->subscription['cancel_at_period_end']);
        $this->assertSame(PlanChange::STATUS_SCHEDULED, PlanChange::query()->sole()->status);
    }

    public function test_at_the_renewal_the_lower_plan_takes_over_with_fresh_credits(): void
    {
        $user = $this->cardMember();
        $this->subscribe($user, 'starter')->assertOk();

        // Stripe enters the schedule's second phase at the renewal.
        Carbon::setTestNow(Carbon::createFromTimestamp($this->periodEnd)->addMinutes(3));
        $this->stripe->renew();
        app(BillingSyncService::class)->syncIfStale($user->fresh(), true);

        $this->assertSame('starter', $this->planOf($user));
        $row = Subscription::query()->where('user_id', $user->id)->latest('id')->first();
        $this->assertSame($this->periodEnd, $row->current_period_start->getTimestamp(), 'A new period: fresh credits.');
        $change = PlanChange::query()->sole();
        $this->assertSame(PlanChange::STATUS_APPLIED, $change->status);
        $this->assertNotNull($change->applied_at);
        $this->assertNotNull($this->stripe->last('post', '/v1/subscription_schedules/sub_sched_1/release'), 'Its work done, the schedule lets the subscription go.');
    }

    public function test_a_schedule_let_go_in_stripe_before_the_renewal_calls_the_change_off(): void
    {
        $user = $this->cardMember();
        $this->subscribe($user, 'starter')->assertOk();

        $this->stripe->subscription['schedule'] = null;
        app(BillingSyncService::class)->syncIfStale($user->fresh(), true);

        $this->assertSame('released_in_stripe', PlanChange::query()->sole()->cancel_reason);
        $this->assertSame('creator', $this->planOf($user));
    }

    public function test_the_next_payment_on_manage_subscription_is_the_schedules(): void
    {
        $user = $this->cardMember();
        $this->subscribe($user, 'starter')->assertOk();

        $page = $this->actingAs($user)->getLocalizedRoute('manage-subscription.page')->assertOk();

        $this->assertSame('sub_sched_1', $this->stripe->last('post', '/v1/invoices/create_preview')['schedule'] ?? null);
        $page->assertSee('id="keepPlanBtn"', false)
            ->assertSee('Keep Creator')
            ->assertSee('Next plan')
            ->assertSee('Starter from 20 Nov 2026')
            ->assertSee('You keep Creator and its credits until 20 Nov 2026. Then Starter starts:', false);
    }

    public function test_the_billing_page_says_what_is_booked_and_offers_the_other_cycle_of_the_plan(): void
    {
        $user = $this->cardMember();
        $this->subscribe($user, 'starter')->assertOk();

        $page = $this->actingAs($user)->getLocalizedRoute('billing.page')->assertOk();
        $page->assertSee('id="planChangeNotice"', false)
            ->assertSee('Starter starts on 20 Nov 2026. Until then you keep Creator.')
            ->assertSee('Change plan')
            ->assertSee('Creator (your plan)')
            ->assertViewHas('currentCycle', 'monthly');
    }

    // ── Trials ───────────────────────────────────────────────────────────────

    public function test_a_lower_plan_on_a_trial_moves_the_trial_without_charging_or_ending_it(): void
    {
        $trialEnds = Carbon::parse('2026-11-09 08:00:00', 'UTC')->getTimestamp();
        $user = $this->cardMember('price_creator_m', 'trialing', $trialEnds);
        $this->creator->forceFill(['discount_percentage' => 20, 'stripe_coupon_id' => 'listen_creator_discount_20'])->save();
        $this->starter->forceFill(['discount_percentage' => 50, 'stripe_coupon_id' => 'listen_starter_discount_50'])->save();
        BillingTrial::query()->create([
            'user_id' => $user->id, 'plan_id' => $this->creator->id, 'quota_plan_id' => $this->starter->id,
            'billing_cycle' => 'monthly', 'status' => BillingTrial::STATUS_TRIALING, 'stripe_customer_id' => 'cus_dn',
            'stripe_subscription_id' => 'sub_dn', 'amount_cents' => 2200, 'started_at' => now()->subDay(),
            'trial_ends_at' => Carbon::createFromTimestamp($trialEnds),
        ]);

        $this->preview($user, 'starter')->assertOk()
            ->assertJsonPath('kind', 'trial')
            ->assertJsonPath('button', 'Move my trial to Starter')
            ->assertJsonPath('total', '$0.00')
            ->assertJsonPath('note', fn (string $note) => str_contains($note, '$9.00') && ! str_contains($note, '50%'));

        $this->subscribe($user, 'starter')->assertOk()
            ->assertJsonPath('trial_moved', true)
            ->assertJsonPath('charged', false);

        $update = $this->stripe->lastWith('post', '/v1/subscriptions/sub_dn', 'items');
        $this->assertSame('price_starter_m', $update['items'][0]['price']);
        $this->assertSame('none', $update['proration_behavior']);
        $this->assertSame('', $update['discounts'], 'A move down has no offer: neither the higher plan\'s nor the lower one\'s.');
        $this->assertArrayNotHasKey('trial_end', $update, 'The trial ends on the same day.');
        $this->assertSame('starter', $this->stripe->planOfPrice($this->stripe->subscription['price']));
        $this->assertSame($this->starter->id, (int) BillingTrial::query()->sole()->plan_id);
        $this->assertSame(0, PlanChange::query()->count(), 'Nothing waits: the trial moved at once.');
    }

    public function test_a_higher_plan_on_a_trial_still_asks_before_ending_it(): void
    {
        $user = $this->cardMember('price_starter_m', 'trialing', Carbon::parse('2026-11-09', 'UTC')->getTimestamp());

        $this->preview($user, 'creator')->assertOk()->assertJsonPath('change', false);
        $this->subscribe($user, 'creator')->assertStatus(409)->assertJsonPath('requires_trial_end_confirmation', true);
    }

    // ── Plans paid by hand or given by the team ──────────────────────────────

    public function test_a_lower_plan_paid_by_hand_starts_the_day_the_current_one_ends(): void
    {
        $until = Carbon::parse('2026-11-12 08:00:00', 'UTC');
        $user = $this->handPaidMember($this->creator, $until);
        $service = app(ManualPaymentService::class);
        $request = $service->createRequest($user, $this->starter, 'monthly', 'instapay',
            ['amount_minor' => 45000, 'currency' => 'EGP', 'country_code' => 'EG'], ['country_code' => 'EG', 'lines' => ['0123']]);

        $service->approve($request, User::factory()->create(['is_admin' => true]), 'ok');

        $this->assertSame('creator', $this->planOf($user), 'The paid days of Creator are not turned into Starter days.');
        $change = PlanChange::query()->sole();
        $this->assertSame(PlanChange::SOURCE_MANUAL, $change->source);
        $this->assertSame($until->getTimestamp(), $change->starts_at->getTimestamp());
        $this->assertSame($until->copy()->addMonth()->getTimestamp(), $change->period_ends_at->getTimestamp());
        $this->assertSame(ManualPaymentRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertFalse($change->canBeKept(), 'Paid for: there is nothing to keep.');

        // The day it ends, the lower plan takes over for the month paid for, with fresh credits.
        Carbon::setTestNow($until->copy()->addMinute());
        $this->artisan('listen:plan-changes')->assertSuccessful();

        $row = Subscription::query()->where('user_id', $user->id)->latest('id')->first();
        $this->assertSame('starter', $this->planOf($user));
        $this->assertSame($until->getTimestamp(), $row->current_period_start->getTimestamp());
        $this->assertSame($until->copy()->addMonth()->getTimestamp(), $row->current_period_end->getTimestamp());
        $this->assertSame('manual_override', $row->billing_payment_state);
        $this->assertSame($row->id, (int) $request->fresh()->approved_subscription_id);
        $this->assertSame(PlanChange::STATUS_APPLIED, $change->fresh()->status);
    }

    public function test_a_lower_plan_after_a_plan_given_by_the_team_starts_when_the_gift_ends(): void
    {
        $until = Carbon::parse('2026-11-25 08:00:00', 'UTC');
        $user = $this->handPaidMember($this->pro, $until, paid: false);
        $service = app(ManualPaymentService::class);
        $request = $service->createRequest($user, $this->starter, 'monthly', 'vodafone_cash',
            ['amount_minor' => 45000, 'currency' => 'EGP', 'country_code' => 'EG'], ['country_code' => 'EG', 'lines' => ['0123']]);

        $service->approve($request, User::factory()->create(['is_admin' => true]));

        $this->assertSame('pro', $this->planOf($user));
        $this->assertSame($until->getTimestamp(), PlanChange::query()->sole()->starts_at->getTimestamp());
    }

    public function test_a_second_payment_by_hand_never_cancels_a_lower_plan_already_paid_for(): void
    {
        $until = Carbon::parse('2026-11-12 08:00:00', 'UTC');
        $user = $this->handPaidMember($this->creator, $until);
        $service = app(ManualPaymentService::class);
        $admin = User::factory()->create(['is_admin' => true]);
        $lower = $service->createRequest($user, $this->starter, 'monthly', 'instapay', ['amount_minor' => 45000, 'currency' => 'EGP', 'country_code' => 'EG'], ['country_code' => 'EG']);
        $service->approve($lower, $admin);
        $this->assertNull($lower->fresh()->approved_subscription_id, 'A queued payment has no plan of its own until it starts.');

        $higher = $service->createRequest($user, $this->pro, 'monthly', 'instapay', ['amount_minor' => 245000, 'currency' => 'EGP', 'country_code' => 'EG'], ['country_code' => 'EG']);
        try {
            $service->approve($higher, $admin);
            $this->fail('Approving it would cancel a plan already paid for.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('already paid for Starter', $exception->getMessage());
        }
        $this->assertSame('creator', $this->planOf($user));
        $this->assertSame(PlanChange::STATUS_SCHEDULED, PlanChange::query()->sole()->status, 'The paid lower plan still waits.');

        // The team deletes the first payment: the second one is then approved and starts now.
        $service->deleteRequest($lower);
        $this->assertSame('creator', $this->planOf($user), 'Deleting a queued payment leaves the running plan alone.');
        $service->approve($higher->fresh(), $admin);
        $this->assertSame('pro', $this->planOf($user));

        Carbon::setTestNow($until->copy()->addMinute());
        $this->artisan('listen:plan-changes')->assertSuccessful();
        $this->assertSame('pro', $this->planOf($user), 'The called-off lower plan never comes back.');
    }

    public function test_deleting_the_approved_payment_calls_off_the_waiting_plan(): void
    {
        $user = $this->handPaidMember($this->creator, Carbon::parse('2026-11-12 08:00:00', 'UTC'));
        $service = app(ManualPaymentService::class);
        $request = $service->createRequest($user, $this->starter, 'monthly', 'instapay', ['amount_minor' => 45000, 'currency' => 'EGP', 'country_code' => 'EG'], ['country_code' => 'EG']);
        $service->approve($request, User::factory()->create(['is_admin' => true]));

        $result = $service->deleteRequest($request);

        $this->assertTrue($result['rolled_back']);
        $this->assertSame('request_deleted', PlanChange::query()->sole()->cancel_reason);
        $this->assertSame('creator', $this->planOf($user), 'The plan never changed, so nothing is rolled back.');
    }

    public function test_a_waiting_plan_steps_aside_when_the_plan_before_it_was_extended(): void
    {
        $until = Carbon::parse('2026-11-12 08:00:00', 'UTC');
        $user = $this->handPaidMember($this->creator, $until);
        $service = app(ManualPaymentService::class);
        $service->approve($service->createRequest($user, $this->starter, 'monthly', 'instapay', ['amount_minor' => 45000, 'currency' => 'EGP', 'country_code' => 'EG'], ['country_code' => 'EG']),
            User::factory()->create(['is_admin' => true]));

        // The team gives Creator for longer meanwhile.
        Subscription::query()->where('user_id', $user->id)->update(['current_period_end' => $until->copy()->addMonth()]);
        Carbon::setTestNow($until->copy()->addMinute());
        $this->artisan('listen:plan-changes')->assertSuccessful();

        $this->assertSame('creator', $this->planOf($user));
        $this->assertSame('superseded', PlanChange::query()->sole()->cancel_reason);
    }

    public function test_a_lower_card_plan_after_a_plan_paid_by_hand_starts_and_charges_that_day(): void
    {
        $until = Carbon::parse('2026-11-12 08:00:00', 'UTC');
        $user = $this->handPaidMember($this->creator, $until);
        $user->forceFill(['stripe_id' => 'cus_dn'])->save();

        $this->preview($user, 'starter')->assertOk()
            ->assertJsonPath('kind', 'plan_end')
            ->assertJsonPath('starts_on', '12 Nov 2026')
            ->assertJsonPath('button', 'Switch on 12 Nov 2026');

        $this->subscribe($user, 'starter', 'monthly', ['payment_method' => 'pm_card'])->assertOk()
            ->assertJsonPath('scheduled', true)
            ->assertJsonPath('charged', false)
            ->assertJsonPath('message', 'Done. Starter starts on 12 Nov 2026, when your Creator plan ends. Your card is charged then.');

        $this->assertNull($this->stripe->last('post', '/v1/subscriptions'), 'Nothing is bought today.');
        $create = $this->stripe->last('post', '/v1/subscription_schedules');
        $this->assertSame('cus_dn', $create['customer']);
        $this->assertSame($until->getTimestamp(), (int) $create['start_date']);
        $this->assertSame('classic', $create['billing_mode']['type']);
        $this->assertSame('pm_card', $create['default_settings']['default_payment_method']);
        $this->assertSame('price_starter_m', $create['phases'][0]['items'][0]['price']);
        $this->assertSame('creator', $this->planOf($user));
        $this->assertSame('manual_override', Subscription::query()->where('user_id', $user->id)->latest('id')->value('billing_payment_state'));

        // That day Stripe starts the subscription: the plan set by hand steps aside for it.
        Carbon::setTestNow($until->copy()->addMinutes(2));
        $this->stripe->startCardPlan('price_starter_m', $until->getTimestamp());
        $this->artisan('listen:plan-changes')->assertSuccessful();

        $this->assertSame(PlanChange::STATUS_APPLIED, PlanChange::query()->sole()->status);
        $this->assertSame('sub_dn', PlanChange::query()->sole()->stripe_subscription_id);
        $this->assertSame('starter', $this->planOf($user));
    }

    public function test_manage_subscription_tells_one_story_when_a_card_plan_follows_a_plan_paid_by_hand(): void
    {
        $until = Carbon::parse('2026-11-12 08:00:00', 'UTC');
        $user = $this->handPaidMember($this->creator, $until);
        $user->forceFill(['stripe_id' => 'cus_dn'])->save();
        $this->subscribe($user, 'starter', 'monthly', ['payment_method' => 'pm_card'])->assertOk();

        $page = $this->actingAs($user)->getLocalizedRoute('manage-subscription.page')->assertOk();
        $page->assertSee('Starter starts on 12 Nov 2026, when Creator ends, and your card is charged then:', false)
            ->assertSee('<bdi>$9.00</bdi>', false)
            ->assertSeeText('on 12 Nov 2026')
            ->assertSee('Starter, charged to your card')
            ->assertSee('Keep Creator')
            // Nothing that says the opposite: no renewal by hand offered beside it, no "nothing is charged".
            ->assertDontSee(__('ui.manage_subscription.keep_plan'))
            ->assertDontSee(__('ui.manage_subscription.renew_payment'))
            ->assertDontSee(__('ui.manage_subscription.nothing_to_cancel', ['date' => '12 Nov 2026']));
    }

    public function test_a_plan_bought_now_replaces_a_card_plan_booked_to_start_later(): void
    {
        $until = Carbon::parse('2026-11-12 08:00:00', 'UTC');
        $user = $this->handPaidMember($this->creator, $until);
        $user->forceFill(['stripe_id' => 'cus_dn'])->save();
        $this->subscribe($user, 'starter', 'monthly', ['payment_method' => 'pm_card'])->assertOk();

        $this->subscribe($user, 'pro', 'monthly', ['payment_method' => 'pm_card']);

        $this->assertNotNull($this->stripe->last('post', '/v1/subscription_schedules/sub_sched_1/cancel'), 'The booked card plan was not called off.');
        $this->assertSame('replaced', PlanChange::query()->sole()->cancel_reason);
    }

    // ── Pricing page ─────────────────────────────────────────────────────────

    public function test_the_pricing_page_offers_lower_plans_and_says_when_a_booked_one_starts(): void
    {
        $user = $this->cardMember();
        $page = $this->actingAs($user)->getLocalizedRoute('pricing')->assertOk();
        $page->assertSee('Switch to Starter')
            ->assertSee('Switch to Free')
            ->assertSee('Switch to yearly')
            ->assertSee('data-monthly-off="1"', false)
            ->assertDontSee('>Downgrade<', false);

        $this->subscribe($user, 'starter')->assertOk();
        $this->actingAs($user)->getLocalizedRoute('pricing')->assertOk()
            ->assertSee('Starts 20 Nov 2026')
            ->assertDontSee('Switch to Starter');
    }

    public function test_the_arabic_pricing_page_says_it_in_arabic(): void
    {
        $user = $this->cardMember();
        app()->setLocale('ar');

        $this->actingAs($user)->getLocalizedRoute('pricing')->assertOk()
            ->assertSee('انتقل إلى خطة')
            ->assertSee('انتقل إلى الفوترة السنوية');
    }

    // ── What changes, and the emails ─────────────────────────────────────────

    public function test_what_changes_shows_on_manage_subscription_not_on_the_billing_page(): void
    {
        $user = $this->cardMember();

        $preview = $this->preview($user, 'starter')->assertOk();
        $this->assertNull($preview->json('changes'), 'The billing page lists no changes.');
        $this->actingAs($user)->withoutMiddleware($this->without())->get($this->localizedRoute('billing.page').'?plan=starter')
            ->assertOk()->assertDontSee('class="plan-diff"', false);

        $this->subscribe($user, 'starter')->assertOk();
        $rows = collect(app(PlanChanges::class)->describe(PlanChange::query()->sole()->load(['toPlan', 'fromPlan']), [], 'en')['changes'])->keyBy('label');
        $this->assertNotEmpty($rows);
        $this->assertTrue($rows->every(fn ($row) => $row['from'] !== $row['to']), 'Only rows that differ.');
        $this->assertSame('5,000 characters', $rows['Max text per generation']['to'] ?? null);
        $this->assertSame('10,000 characters', $rows['Max text per generation']['from'] ?? null);
        $this->actingAs($user)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertSee('class="plan-diff"', false)
            ->assertSeeText('What changes on 20 Nov 2026')
            ->assertSeeText('5,000 characters');
    }

    public function test_the_billing_and_pricing_pages_show_lower_plans_without_their_offer(): void
    {
        $this->starter->forceFill(['discount_percentage' => 50, 'stripe_coupon_id' => 'listen_starter_discount_50'])->save();
        $this->pro->forceFill(['discount_percentage' => 30, 'stripe_coupon_id' => 'listen_pro_discount_30'])->save();
        $user = $this->cardMember();

        $plans = collect($this->actingAs($user)->getLocalizedRoute('billing.page')->assertOk()->viewData('plans'))->keyBy('slug');
        $this->assertArrayNotHasKey('has_discount', $plans['starter'], 'A move down shows no offer.');
        $this->assertSame(900, $plans['starter']['monthly']['amount_cents']);
        $this->assertTrue($plans['pro']['has_discount'], 'A move up keeps its offer.');

        $cards = collect($this->actingAs($user)->getLocalizedRoute('pricing')->assertOk()->viewData('pricingPlans'))->keyBy('slug');
        $this->assertFalse($cards['starter']['has_discount']);
        $this->assertSame(900, $cards['starter']['price_monthly_cents']);
        $this->assertTrue($cards['pro']['has_discount']);

        // A visitor, and a member on a lower plan, still see the offer.
        \Illuminate\Support\Facades\Auth::logout();
        $guest = collect($this->getLocalizedRoute('pricing')->assertOk()->viewData('pricingPlans'))->keyBy('slug');
        $this->assertTrue($guest['starter']['has_discount']);
    }

    public function test_a_yearly_member_sees_their_own_plan_monthly_without_its_offer(): void
    {
        $changes = app(PlanChanges::class);

        $user = $this->cardMember('price_creator_m');
        $this->assertEqualsCanonicalizing(['basic', 'starter'], $changes->plansWithoutOffer($user), 'Monthly: only the plans below.');

        BillingSubscription::query()->where('user_id', $user->id)->update(['stripe_price' => 'price_creator_y']);
        $this->assertEqualsCanonicalizing(['basic', 'starter', 'creator'], $changes->plansWithoutOffer($user),
            'Yearly: their own plan monthly is a move down too.');
        $this->assertSame([], $changes->plansWithoutOffer(User::factory()->create()), 'A free member gets every offer.');
    }

    public function test_instapay_for_a_lower_plan_asks_its_full_price(): void
    {
        SiteSetting::set('pricing_forced_country_code', 'EG', 'billing');
        SiteSetting::set('manual_instapay_enabled', '1', 'billing');
        SiteSetting::set('manual_instapay_number', '0123456789', 'billing');
        $this->starter->forceFill(['discount_percentage' => 50, 'stripe_coupon_id' => 'listen_starter_discount_50'])->save();
        $member = $this->handPaidMember($this->creator, Carbon::parse('2026-11-12 08:00:00', 'UTC'));
        $newcomer = User::factory()->create();

        $quote = fn (User $user) => $this->actingAs($user)->withoutMiddleware($this->without())
            ->get($this->localizedRoute('billing.manual.instructions').'?plan=starter&billing_cycle=monthly&payment_method=instapay')
            ->assertOk()->viewData('quote');

        $pricing = app(\App\Services\LocalizedPricingService::class);
        $this->assertSame($pricing->convertUsdMinorToCurrency(900, 'EGP'), (int) $quote($member)['amount_minor'], 'A move down pays the full price.');
        $this->assertSame($pricing->convertUsdMinorToCurrency(450, 'EGP'), (int) $quote($newcomer)['amount_minor'], 'A new member gets the offer.');
    }

    public function test_booking_a_lower_plan_sends_one_email_in_both_languages_with_what_changes(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $user = $this->cardMember();

        $this->subscribe($user, 'starter')->assertOk();

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\PlanChangeMail::class, 1);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\PlanChangeMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email)
                && str_contains($mail->subjectLine, 'Your plan changes to Starter on 20 Nov 2026')
                && str_contains($mail->subjectLine, 'تتغير خطتك إلى')
                && str_contains($mail->htmlBody, 'You keep Creator and its credits until 20 Nov 2026')
                && str_contains($mail->htmlBody, 'Nothing was charged today.')
                && str_contains($mail->htmlBody, 'Max text per generation: 10,000 characters → 5,000 characters')
                && str_contains($mail->htmlBody, '/en/manage-subscription')
                && str_contains($mail->htmlBody, '/ar/manage-subscription');
        });
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\PlanChangeMail::class, function ($mail) {
            // The list's heading is the last paragraph, right above the list; no hidden marks.
            $html = $mail->htmlBody;
            $heading = strpos($html, 'What changes on 20 Nov 2026:');

            return $heading !== false
                && strpos($html, 'Changed your mind?') < $heading
                && strpos($html, 'Max text per generation:') > $heading
                && preg_match('/[\x{2066}-\x{2069}]/u', $html.$mail->textBody) === 0;
        });
        $this->assertSame('$9.00 a month', str_replace(["\u{2066}", "\u{2069}"], '', (string) (PlanChange::query()->sole()->terms['en']['terms'] ?? '')), 'The price as the page said it.');

        // The same booking chosen again sends nothing more.
        $this->subscribe($user, 'starter')->assertOk();
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\PlanChangeMail::class, 1);
    }

    public function test_an_approved_payment_by_hand_for_a_lower_plan_says_when_it_starts(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $user = $this->handPaidMember($this->creator, Carbon::parse('2026-11-12 08:00:00', 'UTC'));
        $service = app(ManualPaymentService::class);
        $service->approve($service->createRequest($user, $this->starter, 'monthly', 'instapay',
            ['amount_minor' => 45000, 'currency' => 'EGP', 'country_code' => 'EG'], ['country_code' => 'EG']), User::factory()->create(['is_admin' => true]));
        // The email goes once the admin's page has answered.
        \Illuminate\Support\Facades\Mail::assertNotSent(\App\Mail\PlanChangeMail::class);
        $this->app->terminate();

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\PlanChangeMail::class, function ($mail) {
            return str_contains($mail->htmlBody, 'Your payment for Starter is approved.')
                && str_contains($mail->htmlBody, 'Creator runs until 12 Nov 2026, then Starter starts. It is already paid.')
                && ! str_contains($mail->htmlBody, 'Changed your mind?');
        });
    }

    public function test_a_plan_paid_by_hand_with_a_switch_booked_gets_the_switch_reminder_not_the_end_one(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $until = Carbon::parse('2026-11-09 08:00:00', 'UTC');
        $user = $this->handPaidMember($this->creator, $until);
        $user->forceFill(['stripe_id' => 'cus_dn'])->save();
        $this->subscribe($user, 'starter', 'monthly', ['payment_method' => 'pm_card'])->assertOk();
        $this->partialMock(\App\Services\Billing\BillingLifecycleMail::class, fn ($mock) => $mock->shouldNotReceive('send'));
        \App\Models\SiteSetting::set('plan_reminder_first_days', '7', 'plan_reminder');

        // Seven days before the end.
        app(\App\Services\BillingLifecycleService::class)->sendPreRenewalReminders();

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\PlanChangeMail::class, function ($mail) {
            return str_contains($mail->subjectLine, 'Your plan changes to Starter in 7 days')
                && str_contains($mail->htmlBody, 'Then Starter starts, and your card is charged then:');
        });
        $this->assertNotNull(Subscription::query()->where('user_id', $user->id)->latest('id')->value('billing_reminder_7d_sent_at'));
    }

    public function test_a_card_member_gets_no_reminder_from_the_site_stripe_sends_its_own(): void
    {
        $user = $this->cardMember('price_creator_m');
        $this->subscribe($user, 'starter')->assertOk();
        \Illuminate\Support\Facades\Mail::fake();
        Carbon::setTestNow(Carbon::createFromTimestamp($this->periodEnd)->subDays(7)->addHour());
        \App\Models\SiteSetting::set('plan_reminder_first_days', '7', 'plan_reminder');

        app(\App\Services\BillingLifecycleService::class)->sendPreRenewalReminders();

        \Illuminate\Support\Facades\Mail::assertNotSent(\App\Mail\PlanChangeMail::class);
    }

    public function test_the_renewal_that_starts_the_lower_plan_sends_no_subscription_updated_email(): void
    {
        $user = $this->cardMember();
        $this->subscribe($user, 'starter')->assertOk();
        Carbon::setTestNow(Carbon::createFromTimestamp($this->periodEnd)->addMinutes(3));
        $this->stripe->renew();
        app(BillingSyncService::class)->syncIfStale($user->fresh(), true);
        \Illuminate\Support\Facades\Mail::shouldReceive('html')->never();

        $event = \Stripe\Event::constructFrom([
            'id' => 'evt_switch', 'type' => 'customer.subscription.updated',
            'data' => [
                'object' => ['id' => 'sub_dn', 'object' => 'subscription', 'customer' => 'cus_dn', 'status' => 'active',
                    'cancel_at_period_end' => false, 'items' => ['object' => 'list', 'data' => [['id' => 'si_dn', 'price' => ['id' => 'price_starter_m']]]]],
                'previous_attributes' => ['items' => ['object' => 'list', 'data' => [['id' => 'si_dn', 'price' => ['id' => 'price_creator_m']]]]],
            ],
        ]);
        app(\App\Services\StripeWebhookEmailService::class)->sendForEvent($event);
        $this->addToAssertionCount(1);
    }

    public function test_a_failed_payment_at_the_switch_gives_the_grace_days_on_the_lower_plan(): void
    {
        $user = $this->cardMember();
        $this->subscribe($user, 'starter')->assertOk();

        Carbon::setTestNow(Carbon::createFromTimestamp($this->periodEnd)->addMinutes(3));
        $this->stripe->renew();
        $this->stripe->subscription['status'] = 'past_due';
        $this->stripe->failedRenewal = true;
        app(BillingSyncService::class)->syncIfStale($user->fresh(), true);

        $row = Subscription::query()->where('user_id', $user->id)->latest('id')->first();
        $this->assertSame('starter', $this->planOf($user), 'The grace days are on the plan being renewed.');
        $this->assertSame($this->periodEnd, $row->current_period_end->getTimestamp(), 'With the dates of the period paid for: no fresh allowance.');
        $this->assertSame(PlanChange::STATUS_APPLIED, PlanChange::query()->sole()->status);
    }

    public function test_the_billing_page_asks_stripe_for_its_prices_once_not_on_every_visit(): void
    {
        $user = $this->cardMember();
        $this->actingAs($user)->getLocalizedRoute('billing.page')->assertOk();
        $first = count(array_filter($this->stripe->requests, fn ($r) => str_starts_with($r['path'], '/v1/prices/')));
        $this->assertGreaterThan(0, $first);

        $this->actingAs($user)->getLocalizedRoute('billing.page')->assertOk();
        $second = count(array_filter($this->stripe->requests, fn ($r) => str_starts_with($r['path'], '/v1/prices/')));
        $this->assertSame($first, $second, 'The second visit asked Stripe for the prices again.');
    }

    public function test_the_wallet_keeps_its_place_where_it_showed_before(): void
    {
        $html = view('components.wallet-button', ['id' => 'wallet'])->render();

        $this->assertStringContainsString('class="wallet-skeleton"', $html);
        $this->assertStringContainsString("localStorage.getItem(memoryKey)", $html);
        $this->assertStringContainsString("button.on('loaderror'", $html);
    }

    // ── The welcome email ────────────────────────────────────────────────────

    // ── Checked before release ───────────────────────────────────────────────

    /** The card update on Manage Subscription, with a card the bank approved. */
    private function bindCardUpdate(string $paymentMethod): void
    {
        $this->app->instance(\App\Services\Billing\StripeCardUpdateGateway::class, new class($paymentMethod) implements \App\Services\Billing\StripeCardUpdateGateway
        {
            public function __construct(private string $paymentMethod) {}

            public function ensureCustomer(User $user): string
            {
                return 'cus_dn';
            }

            public function createSetupIntent(string $customerId, int $userId): array
            {
                return ['id' => 'seti_1', 'client_secret' => 'seti_1_secret'];
            }

            public function retrieveSetupIntent(string $setupIntentId): array
            {
                return ['id' => 'seti_1', 'status' => 'succeeded', 'customer' => 'cus_dn', 'payment_method' => $this->paymentMethod, 'purpose' => 'card_update'];
            }

            public function useForRenewals(string $customerId, ?string $subscriptionId, string $paymentMethodId): void {}

            public function payOpenInvoices(string $customerId, string $subscriptionId, string $paymentMethodId): array
            {
                return ['attempted' => 0, 'paid' => 0];
            }
        });
    }

    private function updateCard(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->withoutMiddleware($this->without())
            ->postJson($this->localizedRoute('billing.payment-method'), ['setup_intent' => 'seti_1']);
    }

    public function test_a_new_card_is_the_one_the_booked_switch_charges(): void
    {
        $user = $this->cardMember();
        $this->subscribe($user, 'starter')->assertOk();
        $this->bindCardUpdate('pm_new');

        $this->updateCard($user)->assertOk()->assertJsonPath('ok', true);

        $update = $this->stripe->last('post', '/v1/subscription_schedules/sub_sched_1');
        $this->assertCount(2, $update['phases'], 'The switch stays booked.');
        $this->assertSame('pm_new', $update['phases'][0]['default_payment_method']);
        $this->assertSame('pm_new', $update['phases'][1]['default_payment_method'], 'The renewal is charged to the new card.');
        $this->assertSame('price_starter_m', $update['phases'][1]['items'][0]['price']);
        $this->assertSame($this->periodEnd, (int) $update['phases'][1]['start_date']);
        $this->assertSame(PlanChange::STATUS_SCHEDULED, PlanChange::query()->sole()->status);
    }

    public function test_a_new_card_is_the_one_a_card_plan_starting_later_charges(): void
    {
        $user = $this->handPaidMember($this->creator, Carbon::parse('2026-11-12 08:00:00', 'UTC'));
        $user->forceFill(['stripe_id' => 'cus_dn'])->save();
        $this->subscribe($user, 'starter', 'monthly', ['payment_method' => 'pm_card'])->assertOk();
        $this->bindCardUpdate('pm_new');

        $this->updateCard($user)->assertOk();

        $schedule = (string) PlanChange::query()->sole()->stripe_schedule_id;
        $this->assertSame(['default_payment_method' => 'pm_new'], $this->stripe->last('post', '/v1/subscription_schedules/'.$schedule)['default_settings']);
        $this->assertNotEmpty($this->stripe->schedules[$schedule]['phases'], 'The plan it starts is kept.');
    }

    public function test_a_lower_plan_paid_by_hand_holds_back_any_card_plan_until_the_team_sees_it(): void
    {
        $user = $this->handPaidMember($this->creator, Carbon::parse('2026-11-12 08:00:00', 'UTC'));
        $user->forceFill(['stripe_id' => 'cus_dn'])->save();
        $service = app(ManualPaymentService::class);
        $service->approve($service->createRequest($user, $this->starter, 'monthly', 'instapay',
            ['amount_minor' => 45000, 'currency' => 'EGP', 'country_code' => 'EG'], ['country_code' => 'EG']), User::factory()->create(['is_admin' => true]));
        $calls = count($this->stripe->requests);

        // A higher plan too: bought today, it would call the paid one off on its day.
        $this->subscribe($user, 'pro', 'monthly', ['payment_method' => 'pm_card'])->assertStatus(409)
            ->assertJsonPath('message', 'Starter is already paid for and starts on 12 Nov 2026. To change it, please contact us.');

        $this->assertNull($this->stripe->lastSince($calls, 'post', '/v1/subscriptions'), 'Nothing was bought.');
        $this->assertSame(PlanChange::STATUS_SCHEDULED, PlanChange::query()->sole()->status);
    }

    public function test_instapay_waits_for_the_team_while_a_lower_plan_paid_by_hand_waits(): void
    {
        SiteSetting::set('pricing_forced_country_code', 'EG', 'billing');
        SiteSetting::set('manual_instapay_enabled', '1', 'billing');
        SiteSetting::set('manual_instapay_number', '0123456789', 'billing');
        $user = $this->handPaidMember($this->creator, Carbon::parse('2026-11-12 08:00:00', 'UTC'));
        $service = app(ManualPaymentService::class);
        $service->approve($service->createRequest($user, $this->starter, 'monthly', 'instapay',
            ['amount_minor' => 45000, 'currency' => 'EGP', 'country_code' => 'EG'], ['country_code' => 'EG']), User::factory()->create(['is_admin' => true]));

        $this->actingAs($user)->withoutMiddleware($this->without())
            ->get($this->localizedRoute('billing.manual.instructions').'?plan=pro&billing_cycle=monthly&payment_method=instapay')
            ->assertRedirect()->assertSessionHasErrors('manual_payment');
        $this->actingAs($user)->withoutMiddleware($this->without())
            ->postJson($this->localizedRoute('billing.manual.confirm'), ['plan' => 'pro', 'billing_cycle' => 'monthly', 'payment_method' => 'instapay'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Starter is already paid for and starts on 12 Nov 2026. To change it, please contact us.');

        $this->assertSame(0, ManualPaymentRequest::query()->where('user_id', $user->id)->where('plan_id', $this->pro->id)->count());
    }

    public function test_a_queued_payment_for_monthly_billing_leaves_the_yearly_period_as_it_is(): void
    {
        $user = $this->handPaidMember($this->creator, Carbon::parse('2027-10-20 08:00:00', 'UTC'));
        ManualPaymentRequest::query()->where('user_id', $user->id)->update(['billing_cycle' => 'yearly']);
        $changes = app(PlanChanges::class);
        $this->assertSame('yearly', $changes->currentCycle($user));

        $service = app(ManualPaymentService::class);
        $service->approve($service->createRequest($user, $this->creator, 'monthly', 'instapay',
            ['amount_minor' => 123200, 'currency' => 'EGP', 'country_code' => 'EG'], ['country_code' => 'EG']), User::factory()->create(['is_admin' => true]));

        $this->assertSame(PlanChange::SOURCE_MANUAL, PlanChange::query()->sole()->source, 'Monthly waits for the year to end.');
        $this->assertSame('yearly', $changes->currentCycle($user), 'The year paid for is still the plan running.');
    }

    public function test_a_sync_that_reads_no_subscription_asks_stripe_before_calling_the_switch_off(): void
    {
        $user = $this->cardMember();
        $this->subscribe($user, 'starter')->assertOk();

        app(PlanChanges::class)->reconcile($user, null);
        $this->assertSame(PlanChange::STATUS_SCHEDULED, PlanChange::query()->sole()->status, 'The subscription it is booked on still runs.');

        $this->stripe->subscription['status'] = 'canceled';
        app(PlanChanges::class)->reconcile($user, null);
        $this->assertSame('subscription_ended', PlanChange::query()->sole()->cancel_reason);
    }

    public function test_the_reason_chosen_in_the_cancel_dialog_is_kept_with_the_cancellation(): void
    {
        $user = $this->cardMember();

        $this->actingAs($user)->withoutMiddleware($this->without())
            ->postJson($this->localizedRoute('billing.cancel'), ['reason' => 'price', 'note' => '  Too expensive for me right now.  '])
            ->assertOk()->assertJsonPath('ok', true);

        $record = SubscriptionCancellation::query()->sole();
        $this->assertSame($user->id, $record->user_id);
        $this->assertSame('price', $record->reason);
        $this->assertSame('Too expensive for me right now.', $record->note);
        $this->assertNotNull($record->cancelled_at);
        $this->assertNotNull($record->ends_at);
        $this->assertFalse($record->ended_now);
    }

    public function test_a_reason_is_required_to_cancel(): void
    {
        $user = $this->cardMember();

        $this->actingAs($user)->withoutMiddleware($this->without())
            ->postJson($this->localizedRoute('billing.cancel'), ['note' => 'Just stop it.'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->assertSame(0, SubscriptionCancellation::query()->count());
        $this->assertFalse($this->stripe->subscription['cancel_at_period_end'], 'Nothing was cancelled.');
    }

    public function test_the_note_is_optional(): void
    {
        $user = $this->cardMember();

        $this->actingAs($user)->withoutMiddleware($this->without())
            ->postJson($this->localizedRoute('billing.cancel'), ['reason' => 'not_needed'])->assertOk()->assertJsonPath('ok', true);

        $record = SubscriptionCancellation::query()->sole();
        $this->assertSame('not_needed', $record->reason);
        $this->assertNull($record->note);
        $this->assertNotNull($record->cancelled_at);
    }

    public function test_pressing_cancel_twice_cancels_once_and_keeps_one_reason(): void
    {
        $user = $this->cardMember();
        $send = fn () => $this->actingAs($user)->withoutMiddleware($this->without())
            ->postJson($this->localizedRoute('billing.cancel'), ['reason' => 'price'])->assertOk()->assertJsonPath('ok', true);

        $send();
        $before = count($this->stripe->requests);
        $send();

        $this->assertSame(1, SubscriptionCancellation::query()->count());
        $this->assertSame($before, count($this->stripe->requests), 'The second press asks Stripe nothing.');
    }

    public function test_an_unknown_reason_is_refused_and_nothing_is_cancelled(): void
    {
        $user = $this->cardMember();

        $this->actingAs($user)->withoutMiddleware($this->without())
            ->postJson($this->localizedRoute('billing.cancel'), ['reason' => 'because'])->assertStatus(422);

        $this->assertSame(0, SubscriptionCancellation::query()->count());
        $this->assertFalse($this->stripe->subscription['cancel_at_period_end']);
    }

    public function test_a_cancellation_stripe_refuses_leaves_no_reason_behind(): void
    {
        $user = $this->cardMember();
        $this->stripe->failCancel = true;

        $this->actingAs($user)->withoutMiddleware($this->without())
            ->postJson($this->localizedRoute('billing.cancel'), ['reason' => 'technical'])->assertStatus(422);

        $this->assertSame(0, SubscriptionCancellation::query()->count());
    }

    public function test_a_cancellation_that_fails_books_the_lower_plan_again(): void
    {
        $user = $this->cardMember();
        $this->subscribe($user, 'starter')->assertOk();
        $this->stripe->failCancel = true;

        $this->actingAs($user)->withoutMiddleware($this->without())->postJson($this->localizedRoute('billing.cancel'), ['reason' => 'price'])
            ->assertStatus(422)->assertJsonPath('ok', false);

        $this->assertSame(['cancelled', 'scheduled'], PlanChange::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame($this->starter->id, (int) PlanChange::query()->scheduled()->sole()->to_plan_id);
        $this->assertSame('price_starter_m', $this->stripe->last('post', '/v1/subscription_schedules/sub_sched_2')['phases'][1]['items'][0]['price']);
        $this->assertFalse($this->stripe->subscription['cancel_at_period_end']);
    }

    public function test_a_trial_move_that_fails_keeps_the_trial_cancelled_as_the_member_asked(): void
    {
        $user = $this->cardMember('price_creator_m', 'trialing', Carbon::parse('2026-11-09 08:00:00', 'UTC')->getTimestamp(), true);
        $this->stripe->subscription['cancel_at_period_end'] = true;
        $this->stripe->failItemsUpdate = true;

        $this->subscribe($user, 'starter')->assertStatus(422);

        $this->assertTrue($this->stripe->subscription['cancel_at_period_end'], 'The cancellation the member asked for stays.');
        $this->assertNotNull(BillingSubscription::query()->where('user_id', $user->id)->sole()->ends_at);
    }

    public function test_an_upgrade_after_the_switch_is_still_told(): void
    {
        $user = $this->cardMember();
        $this->subscribe($user, 'starter')->assertOk();
        Carbon::setTestNow(Carbon::createFromTimestamp($this->periodEnd)->addMinutes(3));
        $this->stripe->renew();
        app(BillingSyncService::class)->syncIfStale($user->fresh(), true);
        \Illuminate\Support\Facades\Mail::shouldReceive('html')->once();

        $event = \Stripe\Event::constructFrom([
            'id' => 'evt_upgrade', 'type' => 'customer.subscription.updated',
            'data' => [
                'object' => ['id' => 'sub_dn', 'object' => 'subscription', 'customer' => 'cus_dn', 'status' => 'active',
                    'cancel_at_period_end' => false, 'items' => ['object' => 'list', 'data' => [['id' => 'si_dn', 'price' => ['id' => 'price_pro_m']]]]],
                'previous_attributes' => ['items' => ['object' => 'list', 'data' => [['id' => 'si_dn', 'price' => ['id' => 'price_starter_m']]]]],
            ],
        ]);
        app(\App\Services\StripeWebhookEmailService::class)->sendForEvent($event);
        $this->addToAssertionCount(1);
    }

    public function test_the_preview_and_the_charge_read_an_unknown_price_alike(): void
    {
        $user = $this->cardMember('price_old_unknown');

        $preview = $this->preview($user, 'starter')->assertOk();
        $this->assertNotTrue($preview->json('scheduled'), 'A price no plan knows is charged as a change today, so the preview says so too.');
    }

    public function test_a_move_down_sends_no_welcome_but_a_member_coming_back_gets_one(): void
    {
        $welcome = Mockery::mock(\App\Services\Newsletter\PaidWelcomeEmail::class);
        $welcome->shouldReceive('queueWelcome')->once();
        $this->app->instance(\App\Services\Newsletter\PaidWelcomeEmail::class, $welcome);

        $user = User::factory()->create();
        $row = new Subscription(['user_id' => $user->id, 'plan_id' => $this->creator->id, 'status' => 'active',
            'current_period_start' => now()->subMonth(), 'current_period_end' => now()->subMinute()]);
        $row->saveQuietly();
        // A downgrade at the renewal: no welcome.
        $row = $row->fresh();
        $row->forceFill(['plan_id' => $this->starter->id, 'current_period_start' => now(), 'current_period_end' => now()->addMonth()])->save();

        // A plan that ran out weeks ago, and a cheaper one bought now: welcome back (the one expected call).
        $row->forceFill(['plan_id' => $this->pro->id, 'current_period_end' => now()->subWeeks(3)])->saveQuietly();
        $row = $row->fresh();
        $row->forceFill(['plan_id' => $this->starter->id, 'current_period_start' => now(), 'current_period_end' => now()->addMonth()])->save();

        $this->assertSame($this->starter->id, (int) $row->fresh()->plan_id);
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }
}

/**
 * Stripe as far as plan changes need it, with state: one subscription, its
 * schedules and coupons, and the requests made.
 */
class DowngradeStripeFake implements ClientInterface
{
    /** @var list<array{method:string,path:string,params:array}> */
    public array $requests = [];

    public array $subscription = [];

    /** @var array<string, array> */
    public array $schedules = [];

    public bool $failScheduleUpdate = false;

    /** Turning the renewal off fails (cancel_at_period_end). */
    public bool $failCancel = false;

    /** Changing the subscription's price fails. */
    public bool $failItemsUpdate = false;

    /** The renewal's invoice could not be paid. */
    public bool $failedRenewal = false;

    private int $coupons = 0;

    public function __construct(private int $periodStart, private int $periodEnd) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $method = strtolower($method);
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $params = (array) $params;
        $this->requests[] = ['method' => $method, 'path' => $path, 'params' => $params];

        if ($path === '/v1/subscriptions/sub_dn') {
            if ($method === 'post') {
                if (($this->failCancel && in_array($params['cancel_at_period_end'] ?? null, [true, 'true', 1, '1'], true))
                    || ($this->failItemsUpdate && isset($params['items']))) {
                    return [json_encode(['error' => ['type' => 'api_error', 'message' => 'Try again later.']]), 500, []];
                }
                $this->updateSubscription($params);
            }

            return $this->json($this->subscriptionObject());
        }
        if ($path === '/v1/subscriptions' && $method === 'get') {
            return $this->json(['object' => 'list', 'data' => $this->subscription !== [] && ($this->subscription['created'] ?? true) ? [$this->subscriptionObject()] : [], 'has_more' => false, 'url' => '/v1/subscriptions']);
        }
        if ($path === '/v1/subscriptions' && $method === 'post') {
            return [json_encode(['error' => ['type' => 'card_error', 'message' => 'Not in this test.']]), 402, []];
        }
        if ($path === '/v1/subscription_items/si_dn') {
            return $this->json(['id' => 'si_dn', 'object' => 'subscription_item', 'quantity' => 1,
                'current_period_start' => $this->periodStart, 'current_period_end' => $this->periodEnd,
                'price' => $this->priceObject((string) ($this->subscription['price'] ?? ''))]);
        }
        if ($path === '/v1/subscription_schedules' && $method === 'post') {
            return $this->json($this->createSchedule($params));
        }
        if (preg_match('#^/v1/subscription_schedules/(sub_sched_\d+)(/release|/cancel)?$#', $path, $m)) {
            return $this->scheduleAction($method, $m[1], $m[2] ?? '', $params);
        }
        if ($path === '/v1/coupons' && $method === 'post') {
            $this->coupons++;

            return $this->json(['id' => 'coupon_kept_'.$this->coupons, 'object' => 'coupon'] + $params);
        }
        if (str_starts_with($path, '/v1/coupons/') && $method === 'delete') {
            return $this->json(['id' => substr($path, 12), 'object' => 'coupon', 'deleted' => true]);
        }
        if ($path === '/v1/invoices/create_preview') {
            return $this->json(['object' => 'invoice', 'currency' => 'usd', 'total' => 900, 'amount_due' => 900, 'period_end' => $this->periodEnd,
                'total_discount_amounts' => [], 'lines' => ['object' => 'list', 'data' => []]]);
        }
        if ($path === '/v1/invoices' && $method === 'get') {
            return $this->json(['object' => 'list', 'data' => [], 'has_more' => false, 'url' => '/v1/invoices']);
        }
        if ($path === '/v1/customers/cus_dn') {
            return $this->json(['id' => 'cus_dn', 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => 'pm_card']]);
        }
        if (str_starts_with($path, '/v1/payment_methods/pm_card')) {
            return $this->json(['id' => 'pm_card', 'object' => 'payment_method', 'type' => 'card', 'customer' => 'cus_dn', 'card' => ['brand' => 'visa', 'last4' => '4242']]);
        }
        if ($path === '/v1/customers/cus_dn/payment_methods') {
            return $this->json(['object' => 'list', 'data' => [], 'has_more' => false, 'url' => $path]);
        }
        if (str_starts_with($path, '/v1/prices/')) {
            return $this->json($this->priceObject(substr($path, 11)));
        }

        return $this->json(['id' => 'obj_fake', 'object' => 'unknown']);
    }

    /** The renewal: Stripe enters the schedule's next phase. */
    public function renew(): void
    {
        $schedule = $this->schedules[$this->subscription['schedule']] ?? null;
        $next = $schedule['phases'][1] ?? null;
        if ($next === null) {
            return;
        }
        $this->subscription['price'] = (string) $next['items'][0]['price'];
        $this->periodStart = $this->periodEnd;
        $this->periodEnd = (int) Carbon::createFromTimestamp($this->periodEnd)->addMonth()->getTimestamp();
    }

    /** A card plan booked to start later: Stripe creates its subscription that day. */
    public function startCardPlan(string $price, int $start): void
    {
        $id = array_key_last($this->schedules);
        $this->schedules[$id]['status'] = 'active';
        $this->schedules[$id]['subscription'] = 'sub_dn';
        $this->periodStart = $start;
        $this->periodEnd = (int) Carbon::createFromTimestamp($start)->addMonth()->getTimestamp();
        $this->subscription = ['price' => $price, 'status' => 'active', 'trial_end' => null, 'cancel_at_period_end' => false, 'schedule' => $id, 'discounts' => []];
    }

    public function planOfPrice(string $price): string
    {
        return explode('_', $price)[1] ?? '';
    }

    public function last(string $method, string $path): ?array
    {
        $all = $this->all($method, $path);

        return $all === [] ? null : end($all);
    }

    public function lastWith(string $method, string $path, string $key): ?array
    {
        $all = array_values(array_filter($this->all($method, $path), fn ($params) => array_key_exists($key, $params)));

        return $all === [] ? null : end($all);
    }

    public function lastSince(int $from, string $method, string $path): ?array
    {
        $found = null;
        foreach (array_slice($this->requests, $from) as $request) {
            if ($request['method'] === $method && $request['path'] === $path) {
                $found = $request['params'];
            }
        }

        return $found;
    }

    /** @return list<array> */
    public function all(string $method, string $path): array
    {
        return array_values(array_map(fn ($r) => $r['params'], array_filter($this->requests, fn ($r) => $r['method'] === $method && $r['path'] === $path)));
    }

    public function indexOf(string $method, string $path, int $from = 0): ?int
    {
        foreach ($this->requests as $i => $request) {
            if ($i >= $from && $request['method'] === $method && $request['path'] === $path) {
                return $i;
            }
        }

        return null;
    }

    public function indexOfWith(string $method, string $path, string $key, int $from = 0): ?int
    {
        foreach ($this->requests as $i => $request) {
            if ($i >= $from && $request['method'] === $method && $request['path'] === $path && array_key_exists($key, $request['params'])) {
                return $i;
            }
        }

        return null;
    }

    private function updateSubscription(array $params): void
    {
        if (isset($params['items'][0]['price'])) {
            $this->subscription['price'] = (string) $params['items'][0]['price'];
        }
        if (array_key_exists('cancel_at_period_end', $params)) {
            $this->subscription['cancel_at_period_end'] = in_array($params['cancel_at_period_end'], [true, 'true', 1, '1'], true);
        }
        if (array_key_exists('discounts', $params)) {
            $this->subscription['discounts'] = $params['discounts'] === '' ? [] : (array) $params['discounts'];
        }
    }

    private function createSchedule(array $params): array
    {
        $id = 'sub_sched_'.(count($this->schedules) + 1);
        if (isset($params['from_subscription'])) {
            $this->schedules[$id] = [
                'id' => $id, 'object' => 'subscription_schedule', 'status' => 'active', 'customer' => 'cus_dn',
                'subscription' => 'sub_dn', 'end_behavior' => 'release',
                'current_phase' => ['start_date' => $this->periodStart, 'end_date' => $this->periodEnd],
                'phases' => [[
                    'start_date' => $this->periodStart, 'end_date' => $this->periodEnd,
                    'items' => [['price' => (string) $this->subscription['price'], 'quantity' => 1]],
                    'discounts' => $this->subscription['discounts'] ?? [], 'metadata' => [], 'proration_behavior' => 'create_prorations',
                    'billing_cycle_anchor' => null, 'collection_method' => 'charge_automatically', 'currency' => 'usd',
                    'default_payment_method' => null, 'trial_end' => null,
                ]],
            ];
            $this->subscription['schedule'] = $id;
        } else {
            $this->schedules[$id] = [
                'id' => $id, 'object' => 'subscription_schedule', 'status' => 'not_started', 'customer' => (string) ($params['customer'] ?? ''),
                'subscription' => null, 'end_behavior' => (string) ($params['end_behavior'] ?? 'release'), 'current_phase' => null,
                'phases' => (array) ($params['phases'] ?? []),
            ];
        }

        return $this->schedules[$id];
    }

    private function scheduleAction(string $method, string $id, string $action, array $params): array
    {
        if (! isset($this->schedules[$id])) {
            return [json_encode(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such subscription schedule']]), 404, []];
        }
        if ($action === '/release') {
            $this->schedules[$id]['status'] = 'released';
            $this->schedules[$id]['released_subscription'] = $this->schedules[$id]['subscription'];
            $this->schedules[$id]['subscription'] = null;
            if (($this->subscription['schedule'] ?? null) === $id) {
                $this->subscription['schedule'] = null;
            }
        } elseif ($action === '/cancel') {
            $this->schedules[$id]['status'] = 'canceled';
        } elseif ($method === 'post') {
            if ($this->failScheduleUpdate) {
                return [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'Something about the phases.']]), 400, []];
            }
            if (array_key_exists('phases', $params)) {
                // Stripe gives every phase its dates back, a phase sent with a duration too.
                $phases = array_values((array) $params['phases']);
                $end = null;
                foreach ($phases as $i => $phase) {
                    $start = isset($phase['start_date']) ? (int) $phase['start_date'] : ($end ?? $this->periodStart);
                    if (! isset($phase['end_date'])) {
                        $interval = (string) ($phase['duration']['interval'] ?? 'month');
                        $count = (int) ($phase['duration']['interval_count'] ?? 1);
                        $phase['end_date'] = Carbon::createFromTimestamp($start)->add($interval, $count)->getTimestamp();
                    }
                    $phase['start_date'] = $start;
                    $end = (int) $phase['end_date'];
                    $phases[$i] = $phase;
                }
                $this->schedules[$id]['phases'] = $phases;
            }
            if (array_key_exists('default_settings', $params)) {
                $this->schedules[$id]['default_settings'] = (array) $params['default_settings'];
            }
            $this->schedules[$id]['end_behavior'] = (string) ($params['end_behavior'] ?? $this->schedules[$id]['end_behavior'] ?? 'release');
        }

        return $this->json($this->schedules[$id]);
    }

    private function subscriptionObject(): array
    {
        $price = (string) ($this->subscription['price'] ?? '');
        $status = (string) ($this->subscription['status'] ?? 'active');

        return [
            'id' => 'sub_dn', 'object' => 'subscription', 'customer' => 'cus_dn', 'status' => $status,
            'cancel_at_period_end' => (bool) ($this->subscription['cancel_at_period_end'] ?? false),
            // As in Stripe: a subscription set to end says when.
            'cancel_at' => ($this->subscription['cancel_at_period_end'] ?? false) ? ($this->subscription['trial_end'] ?? $this->periodEnd) : null, 'ended_at' => null,
            'trial_end' => $this->subscription['trial_end'] ?? null, 'trial_start' => null,
            'schedule' => $this->subscription['schedule'] ?? null, 'default_payment_method' => 'pm_card',
            'created' => $this->periodStart - 86400, 'metadata' => [], 'discounts' => [], 'pending_update' => null,
            'current_period_start' => $this->periodStart, 'current_period_end' => $this->periodEnd,
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_dn', 'object' => 'subscription_item', 'quantity' => 1,
                'current_period_start' => $this->periodStart, 'current_period_end' => $this->periodEnd,
                'price' => $this->priceObject($price),
            ]]],
            'latest_invoice' => $this->failedRenewal
                ? ['id' => 'in_renewal', 'object' => 'invoice', 'status' => 'open', 'billing_reason' => 'subscription_cycle', 'amount_paid' => 0, 'amount_due' => 900,
                    'attempt_count' => 1, 'next_payment_attempt' => $this->periodStart + 86400, 'created' => $this->periodStart, 'payments' => ['object' => 'list', 'data' => []]]
                : ['id' => 'in_dn', 'object' => 'invoice', 'amount_paid' => 2700, 'payments' => ['object' => 'list', 'data' => []]],
        ];
    }

    private function priceObject(string $price): array
    {
        return ['id' => $price, 'object' => 'price', 'product' => 'prod_x', 'currency' => 'usd', 'active' => true,
            'unit_amount' => 900, 'recurring' => ['interval' => str_ends_with($price, '_y') ? 'year' : 'month', 'interval_count' => 1, 'usage_type' => 'licensed']];
    }

    private function json(array $body): array
    {
        return [json_encode($body), 200, []];
    }
}
