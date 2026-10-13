<?php

namespace App\Support;

use App\Models\Plan;
use App\Models\UsageCounter;
use App\Services\Billing\CharPackService;

/**
 * What a member has used and has left, for the dashboard and the extension.
 * Pack holders see their pack; everyone else sees this month's allowance.
 */
final class UsageSnapshot
{
    /**
     * @return array{
     *   mode:string, total_chars:int, plan_chars:int, remaining_chars:int, chars_percent:int, gift_chars:int,
     *   total_requests:int, total_minutes:int, plan_requests:?int, requests_unlimited:bool, requests_percent:int,
     *   pack_expires_at:?string, no_free_chars:bool, minutes_limit:?int, seconds_used:int
     * }
     */
    public static function for(int $userId, ?Plan $quotaPlan, UsageCounter $counter): array
    {
        return self::base($userId, $quotaPlan, $counter) + [
            'daily_requests_limit' => PlanUsageGuard::dailyRequests($quotaPlan),
            'daily_requests_used' => PlanUsageGuard::dailyRequests($quotaPlan) > 0 ? PlanUsageGuard::requestsToday($userId) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function base(int $userId, ?Plan $quotaPlan, UsageCounter $counter): array
    {
        if (CharPackService::billsToPack($quotaPlan)) {
            $pack = app(CharPackService::class)->summary($userId);

            return [
                'mode' => 'pack',
                'total_chars' => $pack['used'],
                'plan_chars' => $pack['total'],
                'remaining_chars' => $pack['remaining'],
                'chars_percent' => $pack['total'] > 0 ? min(100, (int) round(($pack['used'] / $pack['total']) * 100)) : 100,
                'gift_chars' => 0,
                'total_requests' => (int) $counter->total_requests,
                'total_minutes' => (int) $counter->total_minutes,
                'plan_requests' => null,
                'requests_unlimited' => true,
                'requests_percent' => 0,
                'pack_expires_at' => $pack['expires_at']?->toIso8601String(),
                'no_free_chars' => false,
                'minutes_limit' => null,
                'seconds_used' => (int) $counter->total_seconds,
            ];
        }

        $planChars = PlanUsageGuard::charsLimit($quotaPlan, $counter);
        $planRequests = PlanUsageGuard::planRequests($quotaPlan);
        $requestsUnlimited = PlanUsageGuard::hasUnlimitedRequests($quotaPlan);
        $used = (int) $counter->total_chars;

        return [
            'mode' => 'monthly',
            'total_chars' => $used,
            'plan_chars' => $planChars,
            'remaining_chars' => max(0, $planChars - $used),
            'chars_percent' => $planChars > 0 ? min(100, (int) round(($used / $planChars) * 100)) : 100,
            'gift_chars' => PlanUsageGuard::giftChars($counter),
            'total_requests' => (int) $counter->total_requests,
            'total_minutes' => (int) $counter->total_minutes,
            'plan_requests' => $requestsUnlimited ? null : $planRequests,
            'requests_unlimited' => $requestsUnlimited,
            'requests_percent' => $requestsUnlimited
                ? 0
                : min(100, (int) round(((int) $counter->total_requests / max(1, $planRequests)) * 100)),
            'pack_expires_at' => null,
            'no_free_chars' => (bool) $quotaPlan?->getAttribute('no_free_monthly_chars'),
            // Voice minutes the month's credits allow, and the real seconds used.
            'minutes_limit' => Credits::minutesFor($planChars),
            'seconds_used' => (int) $counter->total_seconds,
        ];
    }
}
