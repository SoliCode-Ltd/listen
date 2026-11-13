<?php

namespace App\Support;

use App\Models\Plan;

/**
 * The plan offer the free demo shows under a voice it has just made: "Like the voice?
 * Subscribe now and get 50% off your first month. The offer ends 15 November." Its button
 * opens the checkout for the Starter plan, so the words are Starter's own offer: it shows by
 * itself while Starter has one running (Admin → Billing → Plans → Discount / Promotion) and
 * goes when it ends.
 */
final class DemoOffer
{
    /** The plan the button takes the member to. */
    public const PLAN = 'starter';

    /**
     * The checkout a demo button opens: Starter's, monthly. When Starter is not on sale, the
     * plan the page gives instead (its cheapest one).
     */
    public static function checkoutUrl(string $otherwise): string
    {
        $starter = Plan::query()->subscriptionPlans()
            ->where('slug', self::PLAN)
            ->where('is_active', true)
            ->where('price_cents', '>', 0)
            ->exists();

        return route('billing.page', ['plan' => $starter ? self::PLAN : $otherwise, 'billing_cycle' => 'monthly']);
    }

    /**
     * Starter's offer running now, or null when it has none.
     *
     * @return array{offer: string, ends: ?string, url: string}|null
     */
    public static function current(): ?array
    {
        $plan = Plan::query()->subscriptionPlans()
            ->where('slug', self::PLAN)
            ->where('is_active', true)
            ->where('price_cents', '>', 0)
            ->first();
        if (! $plan || ! $plan->hasActiveDiscount()) {
            return null;
        }

        return [
            'offer' => DiscountTerms::offer($plan, 'monthly', (int) $plan->discount_percentage),
            // The last day of the offer ("15 November"); one ending at midnight ends the day before.
            'ends' => $plan->discount_ends_at?->copy()->subSecond()->locale(app()->getLocale())->translatedFormat('j F'),
            // Straight to the checkout for that plan, monthly: the offer is for monthly billing.
            'url' => route('billing.page', ['plan' => (string) $plan->slug, 'billing_cycle' => 'monthly']),
        ];
    }
}
