<?php

namespace App\Services\Api;

use App\Models\ApiKey;
use App\Models\ApiKeyUsage;
use App\Models\Plan;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\PlanAccessService;
use App\Support\Api\ApiFeatureGate;
use App\Support\Api\ApiPlanAccess;
use App\Support\UsageSnapshot;

/**
 * One member's developer API at a glance, for the dashboard's Developer API
 * section. The figures come from the same rows the meter writes: the month's
 * usage counter (the plan's credits, shared by the dashboard, the extension
 * and the API) and each key's monthly row, so the API's share and the
 * rest always add up to what the dashboard shows.
 */
class ApiAccountSummary
{
    public function __construct(private readonly PlanAccessService $planAccessService) {}

    /**
     * Whether the dashboard shows the Developer API section: to members whose
     * plan includes the API, and to other paying members, who see which
     * plans include it.
     */
    public static function shownFor(?Plan $plan): bool
    {
        return $plan !== null && (ApiPlanAccess::enabled($plan) || (int) $plan->price_cents > 0 || $plan->isPack());
    }

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
                'max_chars' => ApiFeatureGate::maxChars($quotaPlan),
                'formats' => ApiSpeechService::formatsFor($plan),
            ],
            // The studio options only some plans include, and whether this member has them;
            // an option no plan includes is left out, unless this member has it anyway.
            'features' => self::featuresShown(ApiFeatureGate::availableTo($user, $plan)),
            // For members whose plan has no API: the plans that include it.
            'api_plan_names' => ApiFeatureGate::apiPlans()->map(fn (Plan $apiPlan): string => $apiPlan->getLocalizedName(app()->getLocale()) ?: (string) $apiPlan->name)->values()->all(),
        ];
    }

    /**
     * @param  array<string, bool>  $available  field => this member has it
     * @return array<string, bool>
     */
    private static function featuresShown(array $available): array
    {
        $offered = ApiFeatureGate::offered();
        $features = array_flip(ApiFeatureGate::FIELDS);

        return array_filter($available, fn (bool $has, string $field): bool => $has || ($offered[$features[$field] ?? ''] ?? false), ARRAY_FILTER_USE_BOTH);
    }
}
