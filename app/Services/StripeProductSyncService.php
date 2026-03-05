<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;

/**
 * Stripe Product Sync Service.
 *
 * Automatically creates and updates Stripe products and prices
 * when plans are created or modified in the admin panel.
 * 
 * This eliminates the need for manual Price ID management.
 */
class StripeProductSyncService
{
    private ?StripeClient $stripe = null;

    /**
     * Check if Stripe is properly configured.
     */
    public function isConfigured(): bool
    {
        $secret = trim((string) config('services.stripe.secret', ''));
        return $secret !== '' && !str_starts_with($secret, 'sk_test_xxx');
    }

    /**
     * Get Stripe client instance.
     */
    private function getClient(): StripeClient
    {
        if ($this->stripe === null) {
            $this->stripe = new StripeClient(config('services.stripe.secret'));
        }
        return $this->stripe;
    }

    /**
     * Sync a plan with Stripe (create product + prices).
     *
     * @param Plan $plan
     * @return array{success: bool, message: string, product_id?: string, price_monthly?: string, price_yearly?: string}
     */
    public function syncPlan(Plan $plan): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Stripe is not configured. Set STRIPE_SECRET in .env.',
            ];
        }

        // Skip free plans
        if ($plan->price_cents <= 0) {
            return [
                'success' => true,
                'message' => 'Free plans do not need Stripe products.',
            ];
        }

        try {
            $stripe = $this->getClient();

            // Create or update product
            $productId = $this->syncProduct($stripe, $plan);
            $plan->stripe_product_id = $productId;

            // Create monthly price (if not exists or price changed)
            $monthlyPriceId = $this->syncPrice($stripe, $plan, 'month', $productId);
            $plan->stripe_price_monthly = $monthlyPriceId;

            // Create yearly price (20% discount)
            $yearlyPriceId = $this->syncPrice($stripe, $plan, 'year', $productId);
            $plan->stripe_price_yearly = $yearlyPriceId;

            $plan->saveQuietly(); // Avoid triggering observer loops

            Log::info('Plan synced with Stripe', [
                'plan_slug' => $plan->slug,
                'product_id' => $productId,
                'price_monthly' => $monthlyPriceId,
                'price_yearly' => $yearlyPriceId,
            ]);

            return [
                'success' => true,
                'message' => 'Plan synced with Stripe successfully.',
                'product_id' => $productId,
                'price_monthly' => $monthlyPriceId,
                'price_yearly' => $yearlyPriceId,
            ];
        } catch (ApiErrorException $e) {
            Log::error('Stripe sync failed', [
                'plan_slug' => $plan->slug,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Stripe API error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Sync product in Stripe.
     */
    private function syncProduct(StripeClient $stripe, Plan $plan): string
    {
        $metadata = [
            'listen_plan_slug' => $plan->slug,
            'listen_plan_id' => (string) $plan->id,
        ];

        // If product exists, update it
        if (!empty($plan->stripe_product_id)) {
            try {
                $stripe->products->update($plan->stripe_product_id, [
                    'name' => $plan->name . ' Plan',
                    'description' => $this->buildProductDescription($plan),
                    'metadata' => $metadata,
                    'active' => $plan->is_active,
                ]);
                return $plan->stripe_product_id;
            } catch (ApiErrorException $e) {
                // Product might not exist, create new
                Log::warning('Existing Stripe product not found, creating new', [
                    'plan_slug' => $plan->slug,
                    'old_product_id' => $plan->stripe_product_id,
                ]);
            }
        }

        // Search for existing product by slug
        $existingProducts = $stripe->products->search([
            'query' => "metadata['listen_plan_slug']:'{$plan->slug}'",
            'limit' => 1,
        ]);

        if (count($existingProducts->data) > 0) {
            $productId = $existingProducts->data[0]->id;
            $stripe->products->update($productId, [
                'name' => $plan->name . ' Plan',
                'description' => $this->buildProductDescription($plan),
                'metadata' => $metadata,
                'active' => $plan->is_active,
            ]);
            return $productId;
        }

        // Create new product
        $product = $stripe->products->create([
            'name' => $plan->name . ' Plan',
            'description' => $this->buildProductDescription($plan),
            'metadata' => $metadata,
        ]);

        return $product->id;
    }

    /**
     * Sync price in Stripe.
     */
    private function syncPrice(StripeClient $stripe, Plan $plan, string $interval, string $productId): string
    {
        $isYearly = $interval === 'year';
        $existingPriceId = $isYearly ? $plan->stripe_price_yearly : $plan->stripe_price_monthly;

        // Calculate price
        $monthlyPriceCents = $plan->price_cents;
        if ($isYearly) {
            // 20% discount for yearly
            $priceCents = (int) round($monthlyPriceCents * 12 * 0.8);
        } else {
            $priceCents = $monthlyPriceCents;
        }

        $lookupKey = "listen_{$plan->slug}_{$interval}ly";
        $metadata = [
            'listen_plan_slug' => $plan->slug,
            'listen_plan_id' => (string) $plan->id,
            'listen_interval' => $interval,
        ];

        // Check if existing price matches
        if (!empty($existingPriceId)) {
            try {
                $existingPrice = $stripe->prices->retrieve($existingPriceId);
                if ($existingPrice->unit_amount === $priceCents && $existingPrice->active) {
                    return $existingPriceId; // Price hasn't changed
                }
                // Price changed - archive old one and create new
                $stripe->prices->update($existingPriceId, ['active' => false]);
            } catch (ApiErrorException $e) {
                // Price doesn't exist, will create new
            }
        }

        // Search for existing active price
        $existingPrices = $stripe->prices->search([
            'query' => "product:'{$productId}' AND metadata['listen_plan_slug']:'{$plan->slug}' AND metadata['listen_interval']:'{$interval}' AND active:'true'",
            'limit' => 1,
        ]);

        if (count($existingPrices->data) > 0) {
            $existing = $existingPrices->data[0];
            if ($existing->unit_amount === $priceCents) {
                return $existing->id;
            }
            // Archive the old price
            $stripe->prices->update($existing->id, ['active' => false]);
        }

        // Create new price (transfer_lookup_key moves key from any existing price)
        $price = $stripe->prices->create([
            'product' => $productId,
            'unit_amount' => $priceCents,
            'currency' => 'usd',
            'recurring' => [
                'interval' => $interval === 'year' ? 'year' : 'month',
            ],
            'lookup_key' => $lookupKey,
            'transfer_lookup_key' => true, // Transfer from existing price if any
            'metadata' => $metadata,
        ]);

        return $price->id;
    }

    /**
     * Build product description from plan features.
     */
    private function buildProductDescription(Plan $plan): string
    {
        $features = [];
        
        if ($plan->monthly_chars > 0) {
            $features[] = number_format($plan->monthly_chars) . ' characters/month';
        }
        
        if ($plan->voice_tier === 'hd') {
            $features[] = 'HD Voice Quality';
        }
        
        if ($plan->commercial_use_allowed) {
            $features[] = 'Commercial Use Allowed';
        }

        return implode(' • ', $features) ?: 'Listen.studio subscription plan';
    }

    /**
     * Sync all active paid plans with Stripe.
     *
     * @return array{synced: int, failed: int, errors: array}
     */
    public function syncAllPlans(): array
    {
        $plans = Plan::where('is_active', true)
            ->where('price_cents', '>', 0)
            ->get();

        $synced = 0;
        $failed = 0;
        $errors = [];

        foreach ($plans as $plan) {
            $result = $this->syncPlan($plan);
            if ($result['success']) {
                $synced++;
            } else {
                $failed++;
                $errors[$plan->slug] = $result['message'];
            }
        }

        return [
            'synced' => $synced,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Get the price ID for a plan and billing cycle.
     *
     * @param Plan $plan
     * @param string $cycle 'monthly' or 'yearly'
     * @return string|null
     */
    public function getPriceId(Plan $plan, string $cycle): ?string
    {
        if ($cycle === 'yearly') {
            return $plan->stripe_price_yearly;
        }
        return $plan->stripe_price_monthly;
    }
}
