<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * A declined "pay now" during a free trial is voided and the trial goes on,
 * with its Starter limits. Only the trial's own charge failing ends it.
 */
class TrialPayNowDeclineTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    private User $member;

    private BillingTrial $trial;

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
        $starter = Plan::create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_chars' => 500000,
            'monthly_minutes' => 120, 'monthly_requests' => 0, 'max_chars_per_request' => 5000, 'is_active' => true,
        ]);
        $creator = Plan::create([
            'slug' => 'creator', 'name' => 'Creator', 'price_cents' => 2200, 'monthly_chars' => 1500000,
            'monthly_minutes' => 360, 'monthly_requests' => 0, 'max_chars_per_request' => 20000, 'is_active' => true,
            'stripe_price_monthly' => 'price_creator_m',
        ]);

        $this->member = User::factory()->create(['stripe_id' => 'cus_trial', 'subscription_status_checked_at' => now()]);
        $this->trial = BillingTrial::query()->create([
            'user_id' => $this->member->id, 'plan_id' => $creator->id, 'quota_plan_id' => $starter->id,
            'billing_cycle' => 'monthly', 'status' => BillingTrial::STATUS_TRIALING,
            'stripe_customer_id' => 'cus_trial', 'stripe_subscription_id' => 'sub_trial',
            'amount_cents' => 2200, 'started_at' => now()->subDays(2), 'trial_ends_at' => now()->addDays(5),
        ]);
        app(PlanAccessService::class)->assignPlan($this->member, $creator, 'active', now()->addDays(5));
        BillingSubscription::query()->create([
            'user_id' => $this->member->id, 'type' => 'default', 'stripe_id' => 'sub_trial',
            'stripe_status' => 'trialing', 'stripe_price' => 'price_creator_m', 'quantity' => 1,
            'trial_ends_at' => now()->addDays(5),
        ]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function invoiceFailed(string $eventId, string $billingReason)
    {
        return $this->postJson('/api/stripe/webhook', [
            'id' => $eventId,
            'type' => 'invoice.payment_failed',
            'data' => ['object' => [
                'id' => 'in_'.$eventId, 'object' => 'invoice', 'customer' => 'cus_trial', 'currency' => 'usd',
                'billing_reason' => $billingReason, 'amount_due' => 2200,
                'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_trial']],
            ]],
        ]);
    }

    private function monthlyCredits(): int
    {
        return (int) app(PlanAccessService::class)->resolveQuotaPlanForUserId((int) $this->member->id)?->monthly_chars;
    }

    public function test_a_declined_pay_now_keeps_the_trial_and_its_starter_limits(): void
    {
        $this->assertSame(500000, $this->monthlyCredits());

        $this->invoiceFailed('evt_pay_now_declined', 'subscription_update')->assertOk();

        $this->assertSame(BillingTrial::STATUS_TRIALING, $this->trial->fresh()->status);
        $this->assertNull($this->trial->fresh()->charge_failed_at);
        $this->assertSame(500000, $this->monthlyCredits());
    }

    public function test_the_trials_own_charge_failing_still_ends_it(): void
    {
        $this->stripe->subscription('sub_trial', 'past_due', 'cus_trial');

        $this->invoiceFailed('evt_trial_charge_failed', 'subscription_cycle')->assertOk();

        $this->assertSame(BillingTrial::STATUS_CHARGE_FAILED, $this->trial->fresh()->status);
        $this->assertNotNull($this->trial->fresh()->charge_failed_at);
    }
}
