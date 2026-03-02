<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\PlanAccessService;
use App\Support\VoiceTier;
use Illuminate\Http\Request;

class UpgradeController extends Controller
{
    public function __construct(
        private readonly PlanAccessService $planAccessService,
    ) {
    }

    public function index(Request $request)
    {
        $activePlan = $this->planAccessService->resolveActivePlanForUserId((int) $request->user()->id);
        $currentPlanSlug = (string) ($activePlan?->slug ?? 'free');

        $plans = Plan::query()
            ->whereIn('slug', ['free', 'creator', 'pro'])
            ->where('is_active', true)
            ->orderBy('price_cents')
            ->get()
            ->map(function (Plan $plan): array {
                $monthly = round((int) $plan->price_cents / 100, 2);

                return [
                    'slug' => (string) $plan->slug,
                    'name' => (string) $plan->name,
                    'price_monthly' => $monthly,
                    'price_yearly' => round($monthly * 0.8, 2),
                    'monthly_chars' => (int) $plan->monthly_chars,
                    'monthly_requests' => (int) $plan->monthly_requests,
                    'requests_unlimited' => (int) $plan->monthly_requests <= 0,
                    'commercial_use_allowed' => (bool) $plan->commercial_use_allowed,
                    'processing_priority' => (string) ($plan->processing_priority ?: 'standard'),
                    'overage_label' => $plan->allow_overage
                        ? '$'.number_format(((int) ($plan->overage_price_per_million_cents ?? 0)) / 100, 2).' / 1M chars'
                        : __('ui.upgrade.no_overage'),
                    'voice_tier' => VoiceTier::label((string) ($plan->voice_tier ?: 'standard')),
                    'is_popular' => $plan->slug === 'creator',
                ];
            })
            ->values()
            ->all();

        return view('upgrade', [
            'activePlan' => $activePlan,
            'currentPlanSlug' => $currentPlanSlug,
            'plans' => $plans,
        ]);
    }
}
