<?php

namespace App\Support\Api;

use App\Models\Plan;
use App\Services\PlanAccessService;

/**
 * What a plan allows on the developer API: whether it is included at all,
 * and how fast and how many at once its keys may call it. Everything is read
 * from the plan on each request, so editing a plan in the admin changes the
 * API at once.
 */
final class ApiPlanAccess
{
    public const OK = 'ok';

    public const NOT_IN_PLAN = 'not_in_plan';

    /** On an unpaid trial: the API starts once the first payment has gone through. */
    public const TRIAL = 'trial';

    /**
     * The API is for paying clients only: a paid plan (or pack) that allows
     * commercial use and has the API switched on. A free plan never gets it,
     * whatever its switch says.
     */
    public static function enabled(?Plan $plan): bool
    {
        if (! $plan || ! (bool) ($plan->api_access_enabled ?? false) || ! (bool) ($plan->commercial_use_allowed ?? false)) {
            return false;
        }

        return (int) $plan->price_cents > 0 || $plan->isPack();
    }

    /**
     * Whether this member may use the API right now, and if not, why.
     */
    public static function statusFor(int $userId, ?Plan $plan): string
    {
        if (! self::enabled($plan)) {
            return self::NOT_IN_PLAN;
        }

        return app(PlanAccessService::class)->userHasUnpaidTrialWindow($userId) ? self::TRIAL : self::OK;
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
