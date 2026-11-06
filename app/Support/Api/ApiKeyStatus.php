<?php

namespace App\Support\Api;

use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\Plan;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use App\Support\PlanUsageGuard;

/**
 * Where one API key stands, for the admin (System → API keys): its credits this period against
 * its own limit (a key may have none: then only the member's plan credits stop it), the member's
 * generations today against their plan's daily limit (one limit for the studio and the API
 * together), and whether a request was refused today for the daily limit, or this period for the
 * key's own limit. Worked out once per key and page.
 */
final class ApiKeyStatus
{
    public const DAILY = 'daily_limit_exceeded';

    public const KEY_LIMIT = 'key_credit_limit_exceeded';

    /**
     * @return array{period: array{key: string, starts_at: \Illuminate\Support\Carbon, ends_at: \Illuminate\Support\Carbon}, credits: int, requests: int, key_limit: ?int,
     *   plan: ?Plan, daily_limit: int, today: int, today_key: int, refused_today: int, key_refused: int, last_refused_at: ?string}
     */
    public static function of(ApiKey $key): array
    {
        return once(function () use ($key): array {
            $userId = (int) $key->user_id;
            $period = CreditPeriod::current($userId);
            $plan = rescue(fn (): ?Plan => app(PlanAccessService::class)->resolveAccessPlanForUserId($userId), null, false);
            $dayStart = PlanUsageGuard::startOfMemberDay();
            $logs = fn () => ApiRequestLog::query()->where('api_key_id', $key->id);

            return [
                'period' => $period,
                'credits' => $key->creditsUsed($period['key']),
                'requests' => $key->requestsMade($period['key']),
                'key_limit' => $key->monthly_credit_limit ? (int) $key->monthly_credit_limit : null,
                'plan' => $plan,
                'daily_limit' => PlanUsageGuard::dailyRequests($plan),
                // The member's generations today (studio and API: the daily limit counts both).
                'today' => PlanUsageGuard::requestsToday($userId),
                'today_key' => $logs()->where('created_at', '>=', $dayStart)->count(),
                'refused_today' => $logs()->where('created_at', '>=', $dayStart)->where('error_code', self::DAILY)->count(),
                'key_refused' => $logs()->where('created_at', '>=', $period['starts_at'])->where('error_code', self::KEY_LIMIT)->count(),
                'last_refused_at' => $logs()->whereIn('error_code', [self::DAILY, self::KEY_LIMIT])->max('created_at'),
            ];
        });
    }

    /** True when the member can make no more generations today (or a request was refused for it). */
    public static function hitDailyToday(ApiKey $key): bool
    {
        $s = self::of($key);

        return $s['refused_today'] > 0 || ($s['daily_limit'] > 0 && $s['today'] >= $s['daily_limit']);
    }

    public static function hitKeyLimit(ApiKey $key): bool
    {
        $s = self::of($key);

        return $s['key_refused'] > 0 || ($s['key_limit'] !== null && $s['credits'] >= $s['key_limit']);
    }

    /** "1,965 of 60,000" or "1,785 (no limit on this key)". */
    public static function creditsLine(ApiKey $key): string
    {
        $s = self::of($key);

        return number_format($s['credits']).($s['key_limit'] !== null ? ' of '.number_format($s['key_limit']) : '');
    }

    public static function creditsNote(ApiKey $key): string
    {
        $s = self::of($key);
        if ($s['key_limit'] === null) {
            return 'No limit set on this key: the member\'s plan credits apply';
        }

        return 'This key\'s own monthly limit ('.max(0, (int) round(100 * $s['credits'] / max(1, $s['key_limit']))).'% used)';
    }

    /** "37 of 50 generations today" or "37 today, no daily limit". */
    public static function todayLine(ApiKey $key): string
    {
        $s = self::of($key);

        return $s['daily_limit'] > 0
            ? number_format($s['today']).' of '.number_format($s['daily_limit']).' today'
            : number_format($s['today']).' today';
    }

    public static function todayNote(ApiKey $key): string
    {
        $s = self::of($key);
        $plan = $s['plan']?->name ?? 'no plan';

        return number_format($s['today_key']).' '.($s['today_key'] === 1 ? 'request' : 'requests').' with this key · '
            .($s['daily_limit'] > 0 ? $plan.' allows '.number_format($s['daily_limit']).' generations a day (studio and API)' : $plan.' has no daily limit');
    }
}
