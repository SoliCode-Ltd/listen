<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BillingSyncService;
use App\Services\PlanAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * A billing sync that cannot read Stripe changes nothing: no new dates, no
 * fresh allowance, and no member loses their plan or billing links. Only a
 * customer Stripe really deleted is cleared.
 */
class BillingSyncErrorTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    private User $member;

    private Subscription $plan;

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
            'monthly_minutes' => 120, 'monthly_requests' => 1000, 'max_chars_per_request' => 5000, 'is_active' => true,
            'stripe_price_monthly' => 'price_starter_m',
        ]);

        $this->member = User::factory()->create([
            'stripe_id' => 'cus_sync', 'pm_type' => 'visa', 'pm_last_four' => '4242',
            'subscription_status_checked_at' => now()->subHour(),
        ]);
        $this->plan = app(PlanAccessService::class)->assignPlan($this->member, $starter, 'active', now()->addDays(20)->startOfSecond(), now()->subDays(10)->startOfSecond());
        BillingSubscription::query()->create([
            'user_id' => $this->member->id, 'type' => 'default', 'stripe_id' => 'sub_sync',
            'stripe_status' => 'active', 'stripe_price' => 'price_starter_m', 'quantity' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function sync(): void
    {
        app(BillingSyncService::class)->syncIfStale($this->member, true);
        $this->member->refresh();
    }

    private function assertNothingChanged(): void
    {
        $plan = $this->plan->fresh();
        $this->assertSame('starter', $plan->plan->slug);
        $this->assertEquals($this->plan->current_period_start, $plan->current_period_start);
        $this->assertEquals($this->plan->current_period_end, $plan->current_period_end);
        $this->assertSame('cus_sync', $this->member->stripe_id);
        $this->assertSame('4242', $this->member->pm_last_four);
        $this->assertSame('active', BillingSubscription::query()->where('stripe_id', 'sub_sync')->value('stripe_status'));
        // Checked now, so the next page view does not ask Stripe again straight away.
        $this->assertTrue($this->member->subscription_status_checked_at->gt(now()->subMinute()));
    }

    public function test_a_stripe_error_keeps_the_plan_and_its_dates(): void
    {
        $this->stripe->answer('get', '/v1/customers/cus_sync', ['error' => ['type' => 'api_error', 'message' => 'Something went wrong on our end.']], 500);

        $this->sync();

        $this->assertNothingChanged();
    }

    public function test_stripe_being_unreachable_keeps_the_plan_and_its_dates(): void
    {
        $this->stripe->unreachable = true;

        $this->sync();

        $this->assertNothingChanged();
    }

    public function test_no_such_customer_keeps_the_members_billing_and_plan(): void
    {
        // What every member looks like with a key for the wrong Stripe account or mode.
        $this->stripe->answer('get', '/v1/customers/cus_sync', ['error' => [
            'type' => 'invalid_request_error', 'code' => 'resource_missing', 'param' => 'id',
            'message' => "No such customer: 'cus_sync'",
        ]], 404);
        Log::spy();

        $this->sync();

        $this->assertNothingChanged();
        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_customer_stripe_deleted_is_still_cleared(): void
    {
        $this->stripe->answer('get', '/v1/customers/cus_sync', ['id' => 'cus_sync', 'object' => 'customer', 'deleted' => true]);

        $this->postJson('/api/stripe/webhook', [
            'id' => 'evt_customer_deleted',
            'type' => 'customer.deleted',
            'data' => ['object' => ['id' => 'cus_sync', 'object' => 'customer', 'deleted' => true]],
        ])->assertOk();
        $this->member->refresh();

        $this->assertNull($this->member->stripe_id);
        $this->assertNull($this->member->pm_last_four);
        $this->assertSame('canceled', BillingSubscription::query()->where('stripe_id', 'sub_sync')->value('stripe_status'));
        $this->assertSame('free', app(PlanAccessService::class)->resolveActivePlanForUserId((int) $this->member->id)?->slug);
    }
}
