<?php

namespace Tests\Feature;

use App\Http\Middleware\SyncSubscriptionStatus;
use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Pages read a member's billing from Stripe every few minutes. That routine
 * check runs after the page is sent (Stripe can take seconds), once per
 * member however many requests a page makes; when a paid period has just
 * ended it still runs before the page, since the plan shown depends on it.
 */
class BillingSyncAfterReplyTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashier.key' => 'pk_test_fake', 'cashier.secret' => 'sk_test_fake', 'services.stripe.secret' => 'sk_test_fake']);
        Cache::flush();
        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);
        $this->stripe->answer('get', '/v1/customers/cus_after', ['id' => 'cus_after', 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => null]]);
        $this->stripe->answer('get', '/v1/subscriptions', ['object' => 'list', 'data' => [], 'has_more' => false]);

        Plan::create([
            'slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 10000,
            'monthly_minutes' => 10, 'monthly_requests' => 60, 'max_chars_per_request' => 1200, 'is_active' => true,
        ]);
        $starter = Plan::create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_chars' => 500000,
            'monthly_minutes' => 120, 'monthly_requests' => 1000, 'max_chars_per_request' => 5000, 'is_active' => true,
        ]);
        $this->member = User::factory()->create(['stripe_id' => 'cus_after', 'subscription_status_checked_at' => now()->subHour()]);
        app(PlanAccessService::class)->assignPlan($this->member, $starter, 'active', now()->addDays(20), now()->subDays(10));
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    public function test_the_routine_check_runs_after_the_page_and_only_once(): void
    {
        $this->handle();
        $this->handle();
        $this->assertSame([], $this->stripe->requests, 'the page does not wait for Stripe');

        app(DeferredCallbackCollection::class)->invoke();

        $this->assertTrue($this->stripe->sent('get', '/v1/customers/cus_after'), 'Stripe is read after the page');
        $this->assertCount(1, array_filter($this->stripe->requests, fn ($r) => $r['path'] === '/v1/customers/cus_after'), 'once for both requests');
        $this->assertTrue($this->member->fresh()->subscription_status_checked_at->gt(now()->subMinute()));
    }

    public function test_a_paid_period_that_just_ended_is_checked_before_the_page(): void
    {
        $this->member->forceFill(['subscription_status_checked_at' => now()->subDays(3)])->save();
        \App\Models\Subscription::query()->where('user_id', $this->member->id)->update(['current_period_end' => now()->subHour()]);
        BillingSubscription::query()->create([
            'user_id' => $this->member->id, 'type' => 'default', 'stripe_id' => 'sub_after',
            'stripe_status' => 'active', 'stripe_price' => 'price_x', 'quantity' => 1,
        ]);

        $this->handle();

        $this->assertTrue($this->stripe->sent('get', '/v1/customers/cus_after'), 'read before the page is sent');
    }

    public function test_a_fresh_check_reads_nothing(): void
    {
        $this->member->forceFill(['subscription_status_checked_at' => now()])->save();

        $this->handle();
        app(DeferredCallbackCollection::class)->invoke();

        $this->assertSame([], $this->stripe->requests);
    }

    private function handle(): void
    {
        $request = Request::create('/en/dashboard');
        $request->setUserResolver(fn () => $this->member->fresh());

        app(SyncSubscriptionStatus::class)->handle($request, fn () => response('page'));
    }
}
