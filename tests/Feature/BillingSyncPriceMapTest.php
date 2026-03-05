<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Services\BillingSyncService;
use App\Services\PlanAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class BillingSyncPriceMapTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that knownPriceToSlugMap reads from Plan model stripe price columns.
     */
    public function test_price_to_slug_map_reads_from_plan_model(): void
    {
        // Create a plan with synced stripe prices
        $plan = Plan::factory()->create([
            'slug' => 'test-plan',
            'name' => 'Test Plan',
            'stripe_price_monthly' => 'price_monthly_xyz123',
            'stripe_price_yearly' => 'price_yearly_xyz456',
        ]);

        $service = app(BillingSyncService::class);
        
        // Use reflection to access private method
        $method = new ReflectionMethod(BillingSyncService::class, 'knownPriceToSlugMap');
        $method->setAccessible(true);
        $map = $method->invoke($service);

        // Verify the prices are correctly mapped to the plan slug
        $this->assertArrayHasKey('price_monthly_xyz123', $map);
        $this->assertArrayHasKey('price_yearly_xyz456', $map);
        $this->assertEquals('test-plan', $map['price_monthly_xyz123']);
        $this->assertEquals('test-plan', $map['price_yearly_xyz456']);
    }

    /**
     * Test that multiple plans' prices are all added to the map.
     */
    public function test_price_map_includes_all_plans(): void
    {
        Plan::factory()->create([
            'slug' => 'starter',
            'name' => 'Starter',
            'stripe_price_monthly' => 'price_starter_monthly',
            'stripe_price_yearly' => 'price_starter_yearly',
        ]);

        Plan::factory()->create([
            'slug' => 'pro',
            'name' => 'Pro',
            'stripe_price_monthly' => 'price_pro_monthly',
            'stripe_price_yearly' => 'price_pro_yearly',
        ]);

        $service = app(BillingSyncService::class);
        $method = new ReflectionMethod(BillingSyncService::class, 'knownPriceToSlugMap');
        $method->setAccessible(true);
        $map = $method->invoke($service);

        $this->assertEquals('starter', $map['price_starter_monthly']);
        $this->assertEquals('starter', $map['price_starter_yearly']);
        $this->assertEquals('pro', $map['price_pro_monthly']);
        $this->assertEquals('pro', $map['price_pro_yearly']);
    }

    /**
     * Test that null prices are not added to the map.
     */
    public function test_price_map_ignores_null_prices(): void
    {
        Plan::factory()->create([
            'slug' => 'free',
            'name' => 'Free',
            'stripe_price_monthly' => null,
            'stripe_price_yearly' => null,
        ]);

        $service = app(BillingSyncService::class);
        $method = new ReflectionMethod(BillingSyncService::class, 'knownPriceToSlugMap');
        $method->setAccessible(true);
        $map = $method->invoke($service);

        // The free plan's null prices should not be in the map
        $this->assertNotContains('free', array_values($map));
    }

    /**
     * Test that Plan model prices take priority over legacy config prices.
     */
    public function test_plan_model_prices_override_config(): void
    {
        // Create plan with model-based prices
        Plan::factory()->create([
            'slug' => 'creator',
            'name' => 'Creator',
            'stripe_price_monthly' => 'price_new_monthly',
            'stripe_price_yearly' => 'price_new_yearly',
        ]);

        // Even if config has different prices, model prices should win
        config(['services.stripe.prices.creator' => [
            'monthly' => 'price_old_monthly',
            'yearly' => 'price_old_yearly',
        ]]);

        $service = app(BillingSyncService::class);
        $method = new ReflectionMethod(BillingSyncService::class, 'knownPriceToSlugMap');
        $method->setAccessible(true);
        $map = $method->invoke($service);

        // Model prices should be mapped
        $this->assertEquals('creator', $map['price_new_monthly']);
        $this->assertEquals('creator', $map['price_new_yearly']);
        
        // Config prices should also be mapped (for backward compatibility)
        // but model prices come first in the loop so they take priority
        $this->assertArrayHasKey('price_old_monthly', $map);
        $this->assertArrayHasKey('price_old_yearly', $map);
    }
}
