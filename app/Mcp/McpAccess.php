<?php

namespace App\Mcp;

use App\Models\Plan;
use App\Services\PlanAccessService;
use App\Support\Api\ApiError;
use App\Support\Api\ApiPlanAccess;
use App\Support\VoiceTier;

/**
 * Who may connect an AI app with "Sign in with Listen": members on a paid
 * plan (or pack) with the premium voices, the ones the MCP server speaks
 * with, once their first payment has gone through. Plans with the
 * developer API qualify as they are. Keys work as the API's rules say
 * (App\Support\Api\ApiPlanAccess).
 */
final class McpAccess
{
    public static function enabled(?Plan $plan): bool
    {
        if (ApiPlanAccess::enabled($plan)) {
            return true;
        }
        if (! $plan || VoiceTier::normalize((string) ($plan->voice_tier ?: VoiceTier::STANDARD)) !== VoiceTier::HD) {
            return false;
        }

        return (int) $plan->price_cents > 0 || $plan->isPack();
    }

    public static function notInPlan(): ApiError
    {
        return new ApiError(403, ApiError::TYPE_PERMISSION, 'apps_not_in_plan',
            'Connecting AI apps needs a paid Listen plan with the premium voices. Upgrade the plan, then try again.');
    }

    /** ApiPlanAccess::OK, NOT_IN_PLAN or TRIAL, as for keys. */
    public static function statusFor(int $userId, ?Plan $plan): string
    {
        if (! self::enabled($plan)) {
            return ApiPlanAccess::NOT_IN_PLAN;
        }

        return app(PlanAccessService::class)->userHasUnpaidTrialWindow($userId) ? ApiPlanAccess::TRIAL : ApiPlanAccess::OK;
    }
}
