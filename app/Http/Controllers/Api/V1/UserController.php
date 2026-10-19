<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Subscription;
use App\Services\Api\ApiSpeechService;
use App\Services\PlanAccessService;
use App\Support\Api\ApiFeatureGate;
use App\Support\Api\ApiModels;
use App\Support\Api\ApiPlanAccess;
use App\Support\CreditPeriod;
use App\Support\Credits;
use App\Support\PlanUsageGuard;
use App\Support\UsageSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /v1/user, GET /v1/user/subscription
 */
class UserController extends Controller
{
    public function __construct(private readonly PlanAccessService $planAccessService) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user_id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => $user->email !== null ? (string) $user->email : null,
            'created_at' => $user->created_at?->toIso8601String(),
            'subscription' => $this->subscriptionBody($request),
        ]);
    }

    public function subscription(Request $request): JsonResponse
    {
        return response()->json($this->subscriptionBody($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionBody(Request $request): array
    {
        $user = $request->user();
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');
        $plan = $request->attributes->get('api_plan');
        $quotaPlan = $request->attributes->get('api_quota_plan');
        $userId = (int) $user->id;

        // Credits follow the member's own plan dates (App\Support\CreditPeriod).
        $period = CreditPeriod::current($userId);
        $counter = CreditPeriod::counter($userId);
        $usage = UsageSnapshot::for($userId, $quotaPlan, $counter);

        $subscription = Subscription::query()
            ->where('user_id', $userId)
            ->where('plan_id', $plan?->id)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->latest('id')
            ->first();

        $isPack = $usage['mode'] === 'pack';
        // A renewal payment that failed: the plan stays on until its grace days run
        // out (then it moves to the free plan unless paid), and its credits stay in
        // the period that was paid for until then.
        $graceEndsAt = $period['grace_ends_at'] ?? null;
        $resetsAt = $graceEndsAt ?? $period['ends_at'];

        return [
            'plan' => [
                'slug' => (string) ($plan?->slug ?? 'free'),
                // The name members see (Admin → Plans → English name), as in the docs and the dashboard.
                'name' => $plan ? ($plan->getLocalizedName('en') ?: (string) $plan->name) : 'Free',
                'models' => [ApiModels::PREMIUM],
                // The studio options only some plans include; the rest are refused with feature_not_in_plan.
                'features' => ApiFeatureGate::availableTo($user, $plan),
                'commercial_use_allowed' => (bool) ($plan?->commercial_use_allowed ?? false),
                'processing_priority' => (string) ($plan?->processing_priority ?: 'standard'),
            ],
            'status' => $graceEndsAt !== null ? 'past_due' : ($subscription?->status ?? 'active'),
            'trial' => $this->planAccessService->userHasUnpaidTrialWindow($userId),
            'current_period_end' => ($graceEndsAt ?? $subscription?->current_period_end)?->toIso8601String(),
            'billing_mode' => $isPack ? 'pack' : 'monthly',
            'credits' => [
                'used' => (int) $usage['total_chars'],
                'limit' => (int) $usage['plan_chars'],
                'remaining' => (int) $usage['remaining_chars'],
                'gift' => (int) $usage['gift_chars'],
                'credits_per_second' => Credits::PER_SECOND,
                // Usage stops at the limit: requests that would go over it are refused (402), never billed as overage.
                'overage_allowed' => false,
                // The credit period: from the plan's own start day (calendar months on the free plan).
                'period' => $isPack ? null : $period['starts_at']->format('Y-m-d'),
                'period_starts_at' => $isPack ? null : $period['starts_at']->toIso8601String(),
                'resets_at' => $isPack ? null : $resetsAt->toIso8601String(),
                'resets_at_unix' => $isPack ? null : $resetsAt->getTimestamp(),
                'expires_at' => $usage['pack_expires_at'],
            ],
            'voice_seconds' => [
                'used' => (int) $usage['seconds_used'],
                'limit' => Credits::secondsFor((int) $usage['plan_chars']),
            ],
            'requests' => [
                'used_this_month' => (int) $usage['total_requests'],
                'monthly_limit' => $usage['plan_requests'],
                'daily_limit' => $usage['daily_requests_limit'] > 0 ? (int) $usage['daily_requests_limit'] : null,
                'used_today' => $usage['daily_requests_used'],
                // When the daily count starts again (the member's day, as in the dashboard).
                'daily_resets_at' => PlanUsageGuard::startOfMemberDay()->addDay()->toIso8601String(),
            ],
            'limits' => [
                // Waiting for the file, and in the background (/async).
                'max_characters_per_request' => ApiFeatureGate::maxChars($quotaPlan),
                'max_characters_per_async_request' => ApiFeatureGate::maxChars($quotaPlan, true),
                'max_characters_per_dialogue' => ApiSpeechService::maxCharsFor($quotaPlan, true, true),
                // For the whole account, all keys together.
                'requests_per_minute' => ApiPlanAccess::requestsPerMinute($plan),
                'other_requests_per_minute' => ApiPlanAccess::readsPerMinute($plan),
                'concurrent_generations' => ApiPlanAccess::concurrency($plan),
                'background_generations' => ApiSpeechService::queueLimit($plan),
                'output_formats' => ApiSpeechService::formatsFor($plan),
            ],
            'api_key' => [
                'api_key_id' => (int) $apiKey->id,
                'name' => (string) $apiKey->name,
                'monthly_credit_limit' => $apiKey->monthly_credit_limit,
                'credits_used_this_month' => $apiKey->creditsUsed($period['key']),
                'requests_this_month' => $apiKey->requestsMade($period['key']),
            ],
        ];
    }
}
