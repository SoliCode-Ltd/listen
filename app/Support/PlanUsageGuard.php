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
     * "Today" is the members' day (Cairo by default), not the server's UTC day, so the
     * limit does not reset in the middle of their night.
     */
    public static function requestsToday(int $userId): int
    {
        return AudioGeneration::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', self::startOfMemberDay())
            ->where('status', '!=', 'failed')
            ->count();
    }

    /** Midnight of the members' current day, in the app's time zone (as stored). */
    public static function startOfMemberDay(): \Illuminate\Support\Carbon
    {
        $zone = (string) config('listen.billing.daily_limit_timezone', 'Africa/Cairo');
        try {
            return now($zone)->startOfDay()->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return now()->startOfDay();
        }
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

    /**
     * Overage is never charged (no billing exists for it), so every plan stops
     * at its credits and voice minutes, whatever the old switch says.
     */
    public static function allowsOverage(?Plan $plan): bool
    {
        return false;
    }

    public static function maxCharsPerRequest(?Plan $plan): int
    {
        return max(200, (int) ($plan?->max_chars_per_request ?? 600));
    }

    /**
     * Credits are charged for audio (15 a second), so checking the credits
     * also keeps the month within its voice minutes.
     *
     * @param  array{chars:int, credits?:int, seconds?:int}  $estimate
     */
    public static function assertWithinLimits(?Plan $plan, UsageCounter $counter, array $estimate): void
    {
        if (! self::allowsOverage($plan)) {
            $charsLimit = self::charsLimit($plan, $counter);
            $incoming = (int) ($estimate['credits'] ?? $estimate['chars']);
            if (((int) $counter->total_chars + $incoming) > $charsLimit) {
                $remaining = max(0, $charsLimit - (int) $counter->total_chars);
                throw ValidationException::withMessages([
                    'content' => match (true) {
                        $plan?->getAttribute('no_free_monthly_chars') && $remaining <= 0 => __('ui.backend.no_chars_yet'),
                        // Credits are left, only not enough for this text: say how many, as a pack does.
                        $remaining > 0 => __('ui.backend.monthly_chars_not_enough', ['remaining' => number_format($remaining), 'needed' => number_format($incoming)]),
                        default => __('ui.backend.monthly_chars_exceeded'),
                    },
                ]);
            }
        }

        if ((bool) ($plan?->getAttribute('enforce_trial_minute_cap') ?? false)) {
            // Counted in seconds: a short clip uses seconds of the trial, not a whole minute.
            $planSeconds = (int) ($plan->monthly_minutes ?? 0) * 60;
            $incomingSeconds = (int) ($estimate['seconds'] ?? ((int) ($estimate['minutes'] ?? 0) * 60));
            if ($planSeconds > 0 && (((int) $counter->total_seconds + $incomingSeconds) > $planSeconds)) {
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
