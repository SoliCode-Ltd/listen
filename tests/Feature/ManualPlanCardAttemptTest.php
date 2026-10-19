<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\StripeTrialGateway;
use App\Services\Billing\TrialEligibilityService;
use App\Services\BillingSyncService;
use App\Services\ManualPaymentService;
use App\Services\PlanAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;
use Tests\Support\FakeStripeTrialGateway;
use Tests\TestCase;

/**
 * A plan paid by InstaPay / Vodafone Cash is kept until a card plan is really
 * paid: a declined card, a $0 invoice or a free trial never takes it away.
 */
class ManualPlanCardAttemptTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    private Plan $starter;

    private Plan $creator;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.key' => 'pk_test_fake',
            'services.stripe.secret' => 'sk_test_fake',
            'services.stripe.webhook_secret' => '',
            'cashier.key' => 'pk_test_fake',
            'cashier.secret' => 'sk_test_fake',
        ]);
        Cache::flush();

        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);
        $this->app->instance(StripeTrialGateway::class, new FakeStripeTrialGateway);

        Plan::create([
            'slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 10000,
            'monthly_minutes' => 10, 'monthly_requests' => 60, 'max_chars_per_request' => 1200, 'is_active' => true,
        ]);
        $this->starter = Plan::create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_chars' => 500000,
            'monthly_minutes' => 120, 'monthly_requests' => 1000, 'max_chars_per_request' => 5000, 'is_active' => true,
            'stripe_price_monthly' => 'price_starter_m',
        ]);
        $this->creator = Plan::create([
            'slug' => 'creator', 'name' => 'Creator', 'price_cents' => 2200, 'monthly_chars' => 1500000,
            'monthly_minutes' => 300, 'monthly_requests' => 3000, 'max_chars_per_request' => 10000, 'is_active' => true,
            'stripe_price_monthly' => 'price_creator_m',
        ]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    /** A member whose Starter month was paid by InstaPay and approved by the team. */
    private function paidByInstaPay(?string $stripeId = 'cus_manual'): User
    {
        $member = User::factory()->create(['stripe_id' => $stripeId, 'subscription_status_checked_at' => now()]);
        $request = ManualPaymentRequest::query()->create([
            'user_id' => $member->id, 'plan_id' => $this->starter->id, 'billing_cycle' => 'monthly',
            'payment_method' => 'instapay', 'expected_amount_minor' => 45000, 'currency' => 'EGP',
            'status' => ManualPaymentRequest::STATUS_PENDING, 'submitted_at' => now(),
        ]);
        app(ManualPaymentService::class)->approve($request, User::factory()->create(['is_admin' => true]));

        return $member->fresh();
    }

    private function plan(User $member): Subscription
    {
        return Subscription::query()->where('user_id', $member->id)->latest('id')->firstOrFail();
    }

    private function activePlan(User $member): ?string
    {
        return app(PlanAccessService::class)->resolveActivePlanForUserId((int) $member->id)?->slug;
    }

    /** @param  list<array<string, mixed>>  $subscriptions */
    private function stripeHas(string $customer, array $subscriptions): void
    {
        $this->stripe->answer('get', '/v1/customers/'.$customer, ['id' => $customer, 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => null]]);
        $this->stripe->answer('get', '/v1/subscriptions', ['object' => 'list', 'data' => $subscriptions, 'has_more' => false, 'url' => '/v1/subscriptions']);
    }

    /** @return array<string, mixed> */
    private function stripeSubscription(string $id, string $status, string $price, string $customer = 'cus_manual'): array
    {
        return [
            'id' => $id, 'object' => 'subscription', 'customer' => $customer, 'status' => $status,
            'created' => time() - 60, 'cancel_at_period_end' => false, 'cancel_at' => null, 'ended_at' => null, 'trial_end' => null,
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_'.$id, 'object' => 'subscription_item', 'quantity' => 1,
                'current_period_start' => time() - 60, 'current_period_end' => time() + 30 * 86400,
                'price' => ['id' => $price, 'object' => 'price', 'product' => 'prod_x', 'recurring' => ['interval' => 'month']],
            ]]],
        ];
    }

    /** @param  array<string, mixed>  $invoice */
    private function webhook(string $eventId, string $type, array $invoice)
    {
        return $this->postJson('/api/stripe/webhook', [
            'id' => $eventId,
            'type' => $type,
            'data' => ['object' => $invoice + ['object' => 'invoice', 'customer' => 'cus_manual', 'currency' => 'usd']],
        ]);
    }

    public function test_a_declined_card_never_takes_a_plan_paid_by_hand_away(): void
    {
        $member = $this->paidByInstaPay();
        BillingSubscription::query()->create([
            'user_id' => $member->id, 'type' => 'default', 'stripe_id' => 'sub_try',
            'stripe_status' => 'incomplete', 'stripe_price' => 'price_creator_m', 'quantity' => 1,
        ]);
        $this->stripeHas('cus_manual', [$this->stripeSubscription('sub_try', 'incomplete', 'price_creator_m')]);

        $this->webhook('evt_try_failed', 'invoice.payment_failed', [
            'id' => 'in_try', 'billing_reason' => 'subscription_create', 'subscription' => 'sub_try', 'amount_due' => 2200,
        ])->assertOk();
        app(BillingSyncService::class)->syncIfStale($member, true);

        $this->assertSame('manual_override', $this->plan($member)->billing_payment_state);
        $this->assertSame('starter', $this->activePlan($member));
    }

    public function test_a_zero_invoice_leaves_a_plan_paid_by_hand_as_it_is(): void
    {
        $member = $this->paidByInstaPay();

        $this->webhook('evt_zero_paid', 'invoice.paid', [
            'id' => 'in_zero', 'billing_reason' => 'subscription_create', 'subscription' => 'sub_trial', 'amount_paid' => 0,
        ])->assertOk();

        $this->assertSame('manual_override', $this->plan($member)->billing_payment_state);
        $this->assertSame([], $this->stripe->requests);
    }

    public function test_a_card_plan_paid_after_the_bank_check_takes_over_at_once(): void
    {
        $member = $this->paidByInstaPay();
        $this->stripeHas('cus_manual', [$this->stripeSubscription('sub_card', 'active', 'price_creator_m')]);

        // The member paid Creator by card and passed 3-D Secure in the browser.
        $this->webhook('evt_card_paid', 'invoice.paid', [
            'id' => 'in_card', 'billing_reason' => 'subscription_create', 'amount_paid' => 2200,
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_card']],
        ])->assertOk();

        $this->assertSame('ok', $this->plan($member)->billing_payment_state);
        $this->assertSame('creator', $this->activePlan($member));
    }

    public function test_the_sync_keeps_a_plan_paid_by_hand_when_stripe_only_has_a_declined_card(): void
    {
        // Lost its manual override to the old failed-payment webhook.
        $member = $this->paidByInstaPay();
        $this->plan($member)->forceFill(['billing_payment_state' => 'past_due'])->save();
        $end = $this->plan($member)->current_period_end->toDateTimeString();
        $this->stripeHas('cus_manual', [
            $this->stripeSubscription('sub_try_1', 'incomplete_expired', 'price_creator_m'),
            $this->stripeSubscription('sub_try_2', 'incomplete', 'price_creator_m'),
        ]);

        app(BillingSyncService::class)->syncIfStale($member, true);

        $this->assertSame('starter', $this->activePlan($member));
        $this->assertSame($end, $this->plan($member)->current_period_end->toDateTimeString());
    }

    public function test_the_sync_keeps_a_plan_set_by_an_approved_payment_when_stripe_has_nothing_live(): void
    {
        $member = $this->paidByInstaPay();
        $this->plan($member)->forceFill(['billing_payment_state' => 'ok'])->save();
        // An old card plan that ended long ago, and a retry that was cancelled.
        $this->stripeHas('cus_manual', [
            $this->stripeSubscription('sub_old', 'canceled', 'price_starter_m'),
            $this->stripeSubscription('sub_try', 'incomplete_expired', 'price_creator_m'),
        ]);

        app(BillingSyncService::class)->syncIfStale($member, true);

        $this->assertSame('starter', $this->activePlan($member));
    }

    public function test_a_card_plan_whose_subscription_was_cancelled_still_ends(): void
    {
        $member = User::factory()->create(['stripe_id' => 'cus_card', 'subscription_status_checked_at' => now()]);
        app(PlanAccessService::class)->assignPlan($member, $this->starter, 'active', now()->addDays(20));
        $this->stripeHas('cus_card', [$this->stripeSubscription('sub_card', 'canceled', 'price_starter_m', 'cus_card')]);

        app(BillingSyncService::class)->syncIfStale($member, true);

        $this->assertSame('free', $this->activePlan($member));
    }

    public function test_no_free_trial_while_a_plan_paid_by_hand_is_active(): void
    {
        $member = $this->paidByInstaPay(null);

        $offer = app(TrialEligibilityService::class)->offerFor($member);
        $this->assertFalse($offer['available']);
        $this->assertSame(TrialEligibilityService::REASON_MANUAL_PLAN, $offer['reason']);

        $this->actingAs($member)
            ->postJsonLocalizedRoute('billing.trial.setup', ['plan' => 'creator', 'billing_cycle' => 'monthly'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('ui.backend.trial_manual_plan_active'));
        $this->assertSame('starter', $this->activePlan($member));
        $this->assertSame('manual_override', $this->plan($member)->billing_payment_state);

        // Once it has ended, the trial is offered again.
        $this->travel(32)->days();
        $this->assertNull(app(TrialEligibilityService::class)->denialReason($member->fresh(), null));
    }
}
