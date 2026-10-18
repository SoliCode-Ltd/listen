<?php

namespace App\Services\Api;

use App\Models\ApiKey;
use App\Models\ApiKeyUsage;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\PlanAccessService;
use App\Support\Api\ApiPlanAccess;
use App\Support\PlanUsageGuard;
use App\Support\UsageSnapshot;

/**
 * One member's developer API at a glance, for the dashboard and the keys
 * page. The figures come from the same rows the meter writes: the month's
 * usage counter (the plan's credits, shared by the dashboard, the extension
 * and the API) and each key's monthly row, so the API's share and the
 * rest always add up to what the dashboard shows.
 */
class ApiAccountSummary
{
    public function __construct(private readonly PlanAccessService $planAccessService) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $userId = (int) $user->id;
        $plan = $this->planAccessService->resolveAccessPlanForUserId($userId);
        $quotaPlan = $this->planAccessService->resolveQuotaPlanForUserId($userId, $plan);
        $period = now()->format('Y-m');

        $counter = UsageCounter::firstOrCreate(
            ['user_id' => $userId, 'period_ym' => $period],
            ['total_minutes' => 0, 'total_chars' => 0, 'total_requests' => 0]
        );
        $usage = UsageSnapshot::for($userId, $quotaPlan, $counter);
        $isPack = ($usage['mode'] ?? 'monthly') === 'pack';

        $keys = ApiKey::query()->where('user_id', $userId)->orderByDesc('id')->get();
        $monthUsage = ApiKeyUsage::query()
            ->whereIn('api_key_id', $keys->pluck('id'))
            ->where('period_ym', $period)
            ->get()
            ->keyBy('api_key_id');

        $apiCredits = (int) $monthUsage->sum('credits');
        $usedThisPeriod = (int) $usage['total_chars'];
        $planCredits = (int) $usage['plan_chars'];

        return [
            'status' => ApiPlanAccess::statusFor($userId, $plan),
            'plan' => $plan,
            'plan_name' => $plan ? ($plan->getLocalizedName(app()->getLocale()) ?: (string) $plan->name) : __('ui.dashboard.free_plan'),
            'usage' => $usage,
            'is_pack' => $isPack,
            'resets_at' => now()->startOfMonth()->addMonth(),
            'active_keys' => $keys->whereNull('revoked_at')->values(),
            'revoked_keys' => $keys->whereNotNull('revoked_at')->take(10)->values(),
            'month_usage' => $monthUsage,
            'api_requests' => (int) $monthUsage->sum('requests'),
            'api_credits' => $apiCredits,
            // The rest of this month's credits went to the dashboard and the extension.
            // A pack spans months, so it is shown whole instead.
            'app_credits' => $isPack ? null : max(0, $usedThisPeriod - $apiCredits),
            'api_share_percent' => $planCredits > 0 ? min(100, (int) round($apiCredits / $planCredits * 100)) : 0,
            'limits' => [
                'rpm' => ApiPlanAccess::requestsPerMinute($plan),
                'concurrency' => ApiPlanAccess::concurrency($plan),
                'max_chars' => min(PlanUsageGuard::maxCharsPerRequest($quotaPlan), ApiSpeechService::MAX_TEXT),
            ],
        ];
    }
}
