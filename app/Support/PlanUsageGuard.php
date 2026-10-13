<?php

namespace App\Support;

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\UsageCounter;
use Illuminate\Validation\ValidationException;

class PlanUsageGuard
{
    public static function planChars(?Plan $plan): int
    {
        if ($plan?->getAttribute('no_free_monthly_chars')) {
            return 0;
        }

        return max(1, (int) ($plan?->monthly_chars ?? 30000));
    }

    /**
     * This month's character allowance: the plan's plus any characters
     * Listen gifted for this month (usage_counters.bonus_chars).
     */
    public static function charsLimit(?Plan $plan, ?UsageCounter $counter): int
    {
        return self::planChars($plan) + self::giftChars($counter);
    }

    public static function giftChars(?UsageCounter $counter): int
    {
        return max(0, (int) ($counter?->bonus_chars ?? 0));
    }

    public static function planRequests(?Plan $plan): int
    {
        return max(0, (int) ($plan?->monthly_requests ?? 60));
    }

    public static function hasUnlimitedRequests(?Plan $plan): bool
    {
        return self::planRequests($plan) <= 0;
    }

    /** Most generations a day on this plan; 0 means no daily limit. */
    public static function dailyRequests(?Plan $plan): int
    {
        return max(0, (int) ($plan?->daily_requests ?? 0));
    }

    /**
     * Generations the member started today (failed ones do not count: they are given back).
     */
    public static function requestsToday(int $userId): int
    {
        return AudioGeneration::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->startOfDay())
            ->where('status', '!=', 'failed')
            ->count();
    }

    public static function assertWithinDailyLimit(?Plan $plan, int $userId): void
    {
        $limit = self::dailyRequests($plan);
        if ($limit > 0 && self::requestsToday($userId) >= $limit) {
            throw ValidationException::withMessages([
                'content' => __('ui.backend.daily_requests_exceeded', ['count' => number_format($limit)]),
            ]);
        }
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
            $charsLimit = self::charsLimit($plan, $counter);
            if (((int) $counter->total_chars + (int) $estimate['chars']) > $charsLimit) {
                throw ValidationException::withMessages([
                    'content' => $plan?->getAttribute('no_free_monthly_chars') && $charsLimit <= (int) $counter->total_chars
                        ? __('ui.backend.no_chars_yet')
                        : __('ui.backend.monthly_chars_exceeded'),
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
                    'content' => __('ui.backend.monthly_requests_exceeded'),
                ]);
            }
        }
    }
}
