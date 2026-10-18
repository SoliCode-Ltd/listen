<?php

namespace App\Support\Api;

use App\Models\Plan;

/**
 * What a plan allows on the developer API: whether it is included at all,
 * and how fast and how many at once its keys may call it.
 */
final class ApiPlanAccess
{
    public static function enabled(?Plan $plan): bool
    {
        return (bool) ($plan?->api_access_enabled ?? false);
    }

    /** Requests a minute for each of the member's keys. */
    public static function requestsPerMinute(?Plan $plan): int
    {
        $own = (int) ($plan?->api_requests_per_minute ?? 0);
        if ($own > 0) {
            return $own;
        }

        return max(1, (int) (config('listen.api.requests_per_minute')[self::priority($plan)] ?? 60));
    }

    /** Generations the member may run through the API at the same time. */
    public static function concurrency(?Plan $plan): int
    {
        $own = (int) ($plan?->api_concurrency ?? 0);
        if ($own > 0) {
            return $own;
        }

        return max(1, (int) (config('listen.api.concurrency')[self::priority($plan)] ?? 2));
    }

    private static function priority(?Plan $plan): string
    {
        return strtolower(trim((string) ($plan?->processing_priority ?: 'standard')));
    }
}
