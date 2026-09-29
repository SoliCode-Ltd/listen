<?php

namespace App\Support;

use App\Models\Plan;
use App\Models\UsageCounter;
use Illuminate\Validation\ValidationException;

class PlanUsageGuard
{
    public static function planChars(?Plan $plan): int
    {
        return max(1, (int) ($plan?->monthly_chars ?? 30000));
    }

    public static function planRequests(?Plan $plan): int
    {
        return max(0, (int) ($plan?->monthly_requests ?? 60));
    }

    public static function hasUnlimitedRequests(?Plan $plan): bool
    {
        return self::planRequests($plan) <= 0;
    }

    public static function allowsOverage(?Plan $plan): bool
    {
        return (bool) ($plan?->allow_overage ?? false);
    }

    public static function maxCharsPerRequest(?Plan $plan): int
    {
        return max(200, (int) ($plan?->max_chars_per_request ?? 600));
    }

    /**
     * @param array{chars:int} $estimate
     */
    public static function assertWithinLimits(?Plan $plan, UsageCounter $counter, array $estimate): void
    {
        if (! self::allowsOverage($plan)) {
            $planChars = self::planChars($plan);
            if (((int) $counter->total_chars + (int) $estimate['chars']) > $planChars) {
                throw ValidationException::withMessages([
                    'content' => 'Monthly characters limit exceeded. Please upgrade your plan to continue.',
                ]);
            }
        }

        if ((bool) ($plan?->getAttribute('enforce_trial_minute_cap') ?? false)) {
            $planMinutes = (int) ($plan->monthly_minutes ?? 0);
            $incomingMinutes = (int) ($estimate['minutes'] ?? 0);
            if ($planMinutes > 0 && (((int) $counter->total_minutes + $incomingMinutes) > $planMinutes)) {
                throw ValidationException::withMessages([
                    'content' => __('ui.backend.trial_minute_limit'),
                ]);
            }
        }

        if (! self::hasUnlimitedRequests($plan)) {
            $planRequests = self::planRequests($plan);
            if (((int) $counter->total_requests + 1) > $planRequests) {
                throw ValidationException::withMessages([
                    'content' => 'Monthly requests limit exceeded. Please upgrade your plan to continue.',
                ]);
            }
        }
    }
}
