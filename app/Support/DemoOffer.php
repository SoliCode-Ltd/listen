<?php

namespace App\Support;

use App\Models\Plan;

/**
 * The plan offer the free demo shows under a voice it has just made: "Like the voice?
 * Subscribe now and get 50% off your first month. The offer ends 15 Nov." with a button to
 * the plans. It shows by itself while any paid plan has an offer running (Admin → Billing →
 * Plans → Discount / Promotion) and goes when the last one ends.
 */
final class DemoOffer
{
    /**
     * The biggest offer running now, or null when none is.
     *
     * @return array{offer: string, ends: ?string, url: string}|null
     */
    public static function current(): ?array
    {
        $plans = Plan::query()->subscriptionPlans()
            ->where('is_active', true)
            ->where('price_cents', '>', 0)
            ->where('discount_percentage', '>', 0)
            ->get()
            ->filter(fn (Plan $plan): bool => $plan->hasActiveDiscount())
            // The biggest saving first; among equals, the offer that ends soonest.
            ->sortBy(fn (Plan $plan): array => [-(int) $plan->discount_percentage, $plan->discount_ends_at?->getTimestamp() ?? PHP_INT_MAX])
            ->values();

        $best = $plans->first();
        if (! $best) {
            return null;
        }

        $percent = (int) $best->discount_percentage;
        $offer = DiscountTerms::offer($best, 'monthly', $percent);
        // Plans with smaller offers too: "up to 50%", so the words hold for every plan.
        if ($plans->pluck('discount_percentage')->map(fn ($value): int => (int) $value)->unique()->count() > 1) {
            $offer = str_replace($percent.'%', __('ui.demo.offer_up_to', ['percent' => $percent]), $offer);
        }

        return [
            'offer' => $offer,
            // The last day of the offer ("15 Nov"); one ending at midnight ends the day before.
            'ends' => $best->discount_ends_at?->copy()->subSecond()->locale(app()->getLocale())->translatedFormat('j F'),
            'url' => route('pricing'),
        ];
    }
}
