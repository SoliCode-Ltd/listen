<?php

namespace App\Support;

use App\Models\UsageCounter;
use App\Services\PlanAccessService;

/**
 * The credits a member has left right now (this month's allowance, or their
 * pack), for the balance shown in the site header.
 */
final class CreditBalance
{
    public static function remainingFor(int $userId): int
    {
        $plans = app(PlanAccessService::class);
        $access = $plans->resolveAccessPlanForUserId($userId);
        $quota = $plans->resolveQuotaPlanForUserId($userId, $access);
        $counter = UsageCounter::query()
            ->where('user_id', $userId)
            ->where('period_ym', now()->format('Y-m'))
            ->first() ?? new UsageCounter(['total_chars' => 0, 'total_requests' => 0, 'total_seconds' => 0, 'bonus_chars' => 0]);

        return (int) UsageSnapshot::for($userId, $quota, $counter)['remaining_chars'];
    }
}
