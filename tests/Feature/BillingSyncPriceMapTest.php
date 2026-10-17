<?php

namespace Tests\Feature;

use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\StripePricePlans;
use App\Services\BillingSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class BillingSyncPriceMapTest extends TestCase
{
    use RefreshDatabase;

    private function planFor(object|string $price): ?array
    {
        return app(StripePricePlans::class)->planFor($price);
    }

    public function test_a_plans_current_prices_mean_that_plan_and_cycle(): void
    {
        Plan::factory()->create([
            'slug' => 'test-plan',
            'stripe_price_monthly' => 'price_monthly_xyz123',
            'stripe_price_yearly' => 'price_yearly_xyz456',
        ]);

        $this->assertSame('test-plan', $this->planFor('price_monthly_xyz123')['plan']->slug);
        $this->assertSame('monthly', $this->planFor('price_monthly_xyz123')['cycle']);
        $this->assertSame('test-plan', $this->planFor('price_yearly_xyz456')['plan']->slug);
        $this->assertSame('yearly', $this->planFor('price_yearly_xyz456')['cycle']);
    }

    public function test_every_plans_prices_are_known(): void
    {
        Plan::factory()->create(['slug' => 'starter', 'stripe_price_monthly' => 'price_starter_monthly', 'stripe_price_yearly' => 'price_starter_yearly']);
        Plan::factory()->create(['slug' => 'pro', 'stripe_price_monthly' => 'price_pro_monthly', 'stripe_price_yearly' => 'price_pro_yearly']);

        $this->assertSame('starter', $this->planFor('price_starter_yearly')['plan']->slug);
        $this->assertSame('pro', $this->planFor('price_pro_monthly')['plan']->slug);
    }

    public function test_an_unknown_price_id_means_no_plan(): void
    {
        Plan::factory()->create(['slug' => 'free', 'stripe_price_monthly' => null, 'stripe_price_yearly' => null]);

        $this->assertNull($this->planFor('price_nobody_knows'));
        $this->assertNull($this->planFor(''));
    }

    public function test_legacy_config_prices_still_count(): void
    {
        Plan::factory()->create(['slug' => 'creator', 'stripe_price_monthly' => 'price_new_monthly', 'stripe_price_yearly' => 'price_new_yearly']);
        config(['services.stripe.prices.creator' => ['monthly' => 'price_old_monthly', 'yearly' => 'price_old_yearly']]);

        $this->assertSame('creator', $this->planFor('price_new_monthly')['plan']->slug);
        $this->assertSame('creator', $this->planFor('price_old_yearly')['plan']->slug);
        $this->assertSame('yearly', $this->planFor('price_old_yearly')['cycle']);
    }

    public function test_shared_price_keeps_the_first_plan_instead_of_upgrading(): void
    {
        Plan::factory()->create(['slug' => 'starter', 'stripe_price_monthly' => 'price_shared']);
        Plan::factory()->create(['slug' => 'creator', 'stripe_price_monthly' => 'price_shared']);

        $this->assertSame('starter', $this->planFor('price_shared')['plan']->slug);
    }

    public function test_a_price_the_admin_replaces_keeps_meaning_its_plan(): void
    {
        $lite = Plan::factory()->create(['slug' => 'lite', 'stripe_price_monthly' => 'price_lite_3usd', 'stripe_price_yearly' => 'price_lite_y_old']);

        $lite->update(['price_cents' => 400, 'stripe_price_monthly' => 'price_lite_4usd', 'stripe_price_yearly' => 'price_lite_y_new']);

        $this->assertSame('lite', $this->planFor('price_lite_3usd')['plan']->slug);
        $this->assertSame('monthly', $this->planFor('price_lite_3usd')['cycle']);
        $this->assertSame('yearly', $this->planFor('price_lite_y_old')['cycle']);
        $this->assertSame('lite', $this->planFor('price_lite_4usd')['plan']->slug);
    }

    public function test_a_price_seen_once_keeps_its_plan_after_a_quiet_change(): void
    {
        // The Stripe product sync saves quietly (no model events). A price that was
        // ever looked up as current is still remembered.
        $lite = Plan::factory()->create(['slug' => 'lite', 'stripe_price_monthly' => 'price_lite_3usd']);
        $this->assertNotNull($this->planFor('price_lite_3usd'));

        $lite->stripe_price_monthly = 'price_lite_4usd';
        $lite->saveQuietly();

        $this->assertSame('lite', $this->planFor('price_lite_3usd')['plan']->slug);
    }

    public function test_a_price_nobody_remembered_is_placed_by_its_plan_tag_or_product(): void
    {
        $lite = Plan::factory()->create(['slug' => 'lite', 'stripe_product_id' => 'prod_lite', 'stripe_price_monthly' => 'price_lite_4usd']);
        Plan::factory()->create(['slug' => 'pro', 'stripe_product_id' => 'prod_pro']);

        $tagged = json_decode(json_encode([
            'id' => 'price_tagged_old', 'product' => 'prod_other',
            'metadata' => ['listen_plan_slug' => 'lite', 'listen_plan_id' => (string) $lite->id, 'listen_interval' => 'year'],
            'recurring' => ['interval' => 'year'],
        ]));
        $untaggedOnProduct = json_decode(json_encode([
            'id' => 'price_made_by_hand', 'product' => 'prod_pro', 'metadata' => [], 'recurring' => ['interval' => 'month'],
        ]));

        $this->assertSame('lite', $this->planFor($tagged)['plan']->slug);
        $this->assertSame('yearly', $this->planFor($tagged)['cycle']);
        $this->assertSame('pro', $this->planFor($untaggedOnProduct)['plan']->slug);

        // Once placed, the id alone is enough.
        $this->assertSame('pro', $this->planFor('price_made_by_hand')['plan']->slug);
    }

    public function test_trial_subscription_follows_the_price_stripe_will_charge(): void
    {
        $starter = Plan::factory()->create(['slug' => 'starter', 'stripe_price_monthly' => 'price_starter_m']);
        Plan::factory()->create(['slug' => 'creator', 'stripe_price_monthly' => 'price_creator_m']);
        $user = User::factory()->create();
        BillingTrial::query()->create([
            'user_id' => $user->id,
            'plan_id' => $starter->id,
            'quota_plan_id' => $starter->id,
            'status' => BillingTrial::STATUS_TRIALING,
            'stripe_customer_id' => 'cus_trial',
            'stripe_subscription_id' => 'sub_trial',
            'amount_cents' => 900,
            'started_at' => now(),
            'trial_ends_at' => now()->addDays(7),
        ]);

        $swapped = json_decode(json_encode([
            'id' => 'sub_trial',
            'status' => 'trialing',
            'items' => ['data' => [['price' => ['id' => 'price_creator_m']]]],
        ]));

        $method = new ReflectionMethod(BillingSyncService::class, 'mappedPlanSlugForSubscription');
        $method->setAccessible(true);

        $this->assertSame('creator', $method->invoke(app(BillingSyncService::class), $swapped));
    }
}
