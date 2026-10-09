<?php

namespace App\Support;

use App\Models\Plan;
use Throwable;

final class FreePlan
{
    /**
     * Monthly characters on the free plan, or null when there is no free plan.
     */
    public static function monthlyChars(): ?int
    {
        try {
            $plan = Plan::query()->where('is_active', true)->where('price_cents', 0)->orderBy('id')->first();
        } catch (Throwable) {
            return null;
        }

        return $plan ? (int) $plan->monthly_chars : null;
    }
}
