<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Services\StripeProductSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripePlanSyncClient;
use Tests\TestCase;

/**
 * Plans are synced to Stripe (products, prices and coupons) from the live site
 * only: staging runs the live Stripe keys on a copy of live plans, so a sync
 * there could archive the price live checkouts use.
 */
class StripePlanSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripePlanSyncClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.secret' => 'sk_test_fake', 'cashier.secret' => 'sk_test_fake']);
        $this->stripe = new FakeStripePlanSyncClient;
        ApiRequestor::setHttpClient($this->stripe);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    public function test_the_live_site_syncs_a_plans_product_and_prices(): void
    {
        $plan = $this->plan();

        $result = app(StripeProductSyncService::class)->syncPlan($plan);

        $this->assertTrue($result['success']);
        $plan->refresh();
        $this->assertSame('prod_synced', $plan->stripe_product_id);
        $this->assertSame('price_month_900', $plan->stripe_price_monthly);
        // Twelve months less the yearly discount (20% unless set otherwise).
        $this->assertSame('price_year_8640', $plan->stripe_price_yearly);
    }

    public function test_off_the_live_site_nothing_is_changed_in_stripe(): void
    {
        config(['listen.live_actions' => false]);
        $plan = $this->plan(['stripe_product_id' => 'prod_live', 'stripe_price_monthly' => 'price_live_m', 'stripe_price_yearly' => 'price_live_y']);
        $plan->update(['price_cents' => 1200]);

        $result = app(StripeProductSyncService::class)->syncPlan($plan);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['skipped']);
        $this->assertStringStartsWith('Skipped: not the live site', $result['message']);
        $this->assertSame([], $this->stripe->requests);
        $this->assertSame('price_live_m', $plan->fresh()->stripe_price_monthly);

        $all = app(StripeProductSyncService::class)->syncAllPlans();
        $this->assertSame(['synced' => 0, 'failed' => 0, 'skipped' => 1], array_intersect_key($all, array_flip(['synced', 'failed', 'skipped'])));
        $this->assertSame([], $this->stripe->requests);
    }

    public function test_an_expired_discount_ends_here_while_stripe_is_left_alone_off_the_live_site(): void
    {
        config(['listen.live_actions' => false]);
        $plan = $this->plan(['discount_percentage' => 30, 'discount_ends_at' => now()->subMinute(), 'stripe_coupon_id' => 'listen_starter_discount_30']);

        $this->artisan('plans:expire-discounts')
            ->expectsOutputToContain('Stripe not changed for starter: Skipped: not the live site')
            ->assertSuccessful();

        $plan->refresh();
        $this->assertNull($plan->discount_percentage);
        $this->assertNull($plan->discount_ends_at);
        $this->assertSame([], $this->stripe->requests);
    }

    public function test_on_the_live_site_an_expired_discount_is_cleaned_up_in_stripe(): void
    {
        $plan = $this->plan(['discount_percentage' => 30, 'discount_ends_at' => now()->subMinute()]);

        $this->artisan('plans:expire-discounts')
            ->expectsOutputToContain('Stripe coupon cleaned up for: starter')
            ->assertSuccessful();

        $this->assertNull($plan->fresh()->discount_percentage);
        $this->assertNotSame([], $this->stripe->writes());
    }

    public function test_the_sync_command_says_it_changed_nothing_off_the_live_site(): void
    {
        config(['listen.live_actions' => false]);
        $this->plan();

        $this->artisan('stripe:sync-plans', ['--all' => true])
            ->expectsOutputToContain(StripeProductSyncService::SKIPPED_MESSAGE)
            ->expectsOutputToContain('Nothing was synced')
            ->assertSuccessful();

        $this->assertSame([], $this->stripe->requests);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function plan(array $overrides = []): Plan
    {
        return Plan::factory()->create(array_merge([
            'slug' => 'starter',
            'name' => 'Starter',
            'price_cents' => 900,
            'monthly_chars' => 225000,
            'is_active' => true,
        ], $overrides));
    }
}
