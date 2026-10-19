<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PlanAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * A failed card payment pauses a member's plan only when a payment on that plan
 * is really missing: never for a declined upgrade, a declined first payment, or
 * an invoice that has been paid since.
 */
class DeclinedPaymentTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    private Plan $starter;

    private User $member;

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

        Plan::create([
            'slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 10000,
            'monthly_minutes' => 10, 'monthly_requests' => 60, 'max_chars_per_request' => 1200, 'is_active' => true,
        ]);
        $this->starter = Plan::create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_chars' => 500000,
            'monthly_minutes' => 120, 'monthly_requests' => 1000, 'max_chars_per_request' => 5000, 'is_active' => true,
            'stripe_price_monthly' => 'price_starter_m',
        ]);

        $this->member = User::factory()->create(['stripe_id' => 'cus_member', 'subscription_status_checked_at' => now()]);
        app(PlanAccessService::class)->assignPlan($this->member, $this->starter, 'active', now()->addDays(20), now()->subDays(10));
        BillingSubscription::query()->create([
            'user_id' => $this->member->id, 'type' => 'default', 'stripe_id' => 'sub_live',
            'stripe_status' => 'active', 'stripe_price' => 'price_starter_m', 'quantity' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $invoice */
    private function webhook(string $eventId, string $type, array $invoice)
    {
        return $this->postJson('/api/stripe/webhook', [
            'id' => $eventId,
            'type' => $type,
            'data' => ['object' => $invoice + ['object' => 'invoice', 'customer' => 'cus_member', 'currency' => 'usd']],
        ]);
    }

    private function plan(): Subscription
    {
        return Subscription::query()->where('user_id', $this->member->id)->latest('id')->firstOrFail();
    }

    /** @return list<string> */
    private function sentSubjects(): array
    {
        return app('mail.manager')->mailer()->getSymfonyTransport()->messages()
            ->map(fn ($sent) => (string) $sent->getOriginalMessage()->getSubject())
            ->values()
            ->all();
    }

    private function assertNotPaused(): void
    {
        $plan = $this->plan();
        $this->assertNotSame('past_due', $plan->billing_payment_state);
        $this->assertNull($plan->billing_grace_ends_at);
        $this->assertNotContains('Payment failed - action required', $this->sentSubjects());
        $this->assertSame('starter', app(PlanAccessService::class)->resolveActivePlanForUserId((int) $this->member->id)?->slug);
    }

    public function test_a_declined_upgrade_leaves_the_plan_the_member_still_has(): void
    {
        // The change was pending (pending_if_incomplete), so the subscription is still active.
        $this->stripe->subscription('sub_live', 'active', 'cus_member');

        $this->webhook('evt_upgrade_declined', 'invoice.payment_failed', [
            'id' => 'in_upgrade', 'billing_reason' => 'subscription_update', 'amount_due' => 1750,
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_live']],
        ])->assertOk();

        $this->assertNotPaused();
    }

    public function test_a_declined_first_payment_of_a_new_subscription_changes_nothing(): void
    {
        BillingSubscription::query()->create([
            'user_id' => $this->member->id, 'type' => 'default', 'stripe_id' => 'sub_new',
            'stripe_status' => 'incomplete', 'stripe_price' => 'price_starter_m', 'quantity' => 1,
        ]);
        $this->stripe->subscription('sub_new', 'incomplete', 'cus_member');

        $this->webhook('evt_first_declined', 'invoice.payment_failed', [
            'id' => 'in_first', 'billing_reason' => 'subscription_create', 'subscription' => 'sub_new', 'amount_due' => 900,
        ])->assertOk();

        $this->assertNotPaused();
    }

    public function test_a_failed_renewal_starts_the_grace_period_and_warns_the_member_once(): void
    {
        $this->stripe->subscription('sub_live', 'past_due', 'cus_member');

        $this->webhook('evt_renewal_failed', 'invoice.payment_failed', [
            'id' => 'in_renewal', 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_live', 'amount_due' => 900,
        ])->assertOk();
        // Stripe tries the card again and it fails again.
        $this->webhook('evt_renewal_failed_again', 'invoice.payment_failed', [
            'id' => 'in_renewal', 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_live', 'amount_due' => 900,
        ])->assertOk();

        $plan = $this->plan();
        $this->assertSame('past_due', $plan->billing_payment_state);
        $this->assertTrue($plan->billing_grace_ends_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
        $this->assertNotNull($plan->billing_failed_notice_sent_at);
        $this->assertSame(1, collect($this->sentSubjects())->filter(fn ($subject) => $subject === 'Payment failed - action required')->count());
    }

    public function test_a_late_failure_for_an_invoice_paid_since_does_not_pause_the_member_again(): void
    {
        $this->webhook('evt_paid', 'invoice.paid', [
            'id' => 'in_renewal', 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_live', 'amount_paid' => 900,
        ])->assertOk();
        // Stripe was paid, so the subscription is active again.
        $this->stripe->subscription('sub_live', 'active', 'cus_member');

        // The failure from before the payment arrives late (Stripe retries deliveries for days).
        $this->webhook('evt_failed_earlier', 'invoice.payment_failed', [
            'id' => 'in_renewal', 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_live', 'amount_due' => 900,
        ])->assertOk();

        $this->assertNotPaused();
    }

    public function test_a_failed_invoice_of_a_subscription_that_is_not_the_members_changes_nothing(): void
    {
        $this->stripe->subscription('sub_someone_else', 'past_due', 'cus_member');

        $this->webhook('evt_other', 'invoice.payment_failed', [
            'id' => 'in_other', 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_someone_else', 'amount_due' => 900,
        ])->assertOk();

        $this->assertNotPaused();
        $this->assertFalse($this->stripe->sent('get', '/v1/subscriptions/sub_someone_else'));
    }

    public function test_another_failed_invoice_pauses_the_plan_only_while_stripe_says_it_is_owed(): void
    {
        $this->stripe->subscription('sub_live', 'active', 'cus_member');
        $this->webhook('evt_manual_active', 'invoice.payment_failed', [
            'id' => 'in_manual', 'billing_reason' => 'manual', 'subscription' => 'sub_live', 'amount_due' => 900,
        ])->assertOk();
        $this->assertNotPaused();

        $this->stripe->subscription('sub_live', 'unpaid', 'cus_member');
        $this->webhook('evt_manual_unpaid', 'invoice.payment_failed', [
            'id' => 'in_manual', 'billing_reason' => 'manual', 'subscription' => 'sub_live', 'amount_due' => 900,
        ])->assertOk();
        $this->assertSame('past_due', $this->plan()->billing_payment_state);
    }

    public function test_when_stripe_cannot_be_asked_the_event_is_left_for_stripe_to_send_again(): void
    {
        $this->stripe->unreachable = true;
        $failed = [
            'id' => 'in_renewal', 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_live', 'amount_due' => 900,
        ];

        $this->webhook('evt_unreachable', 'invoice.payment_failed', $failed)->assertStatus(500);
        $this->assertNotPaused();

        $this->stripe->unreachable = false;
        $this->stripe->subscription('sub_live', 'past_due', 'cus_member');
        $this->webhook('evt_unreachable', 'invoice.payment_failed', $failed)->assertOk();

        $this->assertSame('past_due', $this->plan()->billing_payment_state);
    }

    public function test_a_plan_paid_by_hand_is_never_paused_by_a_card(): void
    {
        $this->plan()->forceFill(['billing_payment_state' => 'manual_override'])->save();
        $this->stripe->subscription('sub_live', 'past_due', 'cus_member');

        $this->webhook('evt_manual_member', 'invoice.payment_failed', [
            'id' => 'in_renewal', 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_live', 'amount_due' => 900,
        ])->assertOk();

        $this->assertSame('manual_override', $this->plan()->billing_payment_state);
        $this->assertNull($this->plan()->billing_grace_ends_at);
        $this->assertNotContains('Payment failed - action required', $this->sentSubjects());
    }
}
