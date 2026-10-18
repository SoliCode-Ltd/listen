<?php

namespace App\Support\Api;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Services\PlanAccessService;
use App\Support\VoiceTier;

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

    /** The site setting behind the admin's pause switch ('0' pauses the whole API). */
    public const SITE_SWITCH = 'developer_api_enabled';

    /** The admin's switch for the whole API (on unless paused in Admin → API keys). */
    public static function siteEnabled(): bool
    {
        try {
            return (string) SiteSetting::get(self::SITE_SWITCH, '1') !== '0';
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * The API is for paying clients only: a paid plan (or pack) on the premium
     * voices that allows commercial use and has the API switched on. A free
     * plan never gets it, whatever its switch says.
     */
    public static function enabled(?Plan $plan): bool
    {
        if (! $plan || ! (bool) ($plan->api_access_enabled ?? false) || ! (bool) ($plan->commercial_use_allowed ?? false)) {
            return false;
        }
        // The API's one model is the premium voices.
        if (VoiceTier::normalize((string) ($plan->voice_tier ?: VoiceTier::STANDARD)) !== VoiceTier::HD) {
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

    /** Generation requests a minute, for the member's whole account (all their keys together). */
    public static function requestsPerMinute(?Plan $plan): int
    {
        $own = (int) ($plan?->api_requests_per_minute ?? 0);
        if ($own > 0) {
            return $own;
        }

        return max(1, (int) (config('listen.api.requests_per_minute')[self::priority($plan)] ?? 60));
    }

    /**
     * Every other request a minute (status checks, voices, history, account),
     * for the whole account: enough to check on every background generation
     * the member may have waiting every few seconds.
     */
    public static function readsPerMinute(?Plan $plan): int
    {
        return max(300, self::requestsPerMinute($plan) * 3);
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
