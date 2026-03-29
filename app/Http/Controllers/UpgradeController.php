<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\SiteSetting;
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
        $locale = app()->getLocale();

        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('price_cents')
            ->get()
            ->map(function (Plan $plan) use ($locale): array {
                $monthly = round((int) $plan->price_cents / 100, 2);
                $charTopupCents = $plan->topup_char_price_cents !== null
                    ? (int) $plan->topup_char_price_cents
                    : (
                        $plan->allow_overage && $plan->overage_price_per_million_cents !== null
                            ? (int) $plan->overage_price_per_million_cents
                            : 0
                    );

                return [
                    'slug' => (string) $plan->slug,
                    'name' => $plan->getLocalizedName($locale),
                    'description' => $plan->getLocalizedDescription($locale),
                    'price_monthly' => $monthly,
                    'price_yearly' => round($monthly * SiteSetting::yearlyDiscountMultiplier(), 2),
                    'monthly_chars' => (int) $plan->monthly_chars,
                    'monthly_requests' => (int) $plan->monthly_requests,
                    'requests_unlimited' => (int) $plan->monthly_requests <= 0,
                    'commercial_use_allowed' => (bool) $plan->commercial_use_allowed,
                    'processing_priority' => (string) ($plan->processing_priority ?: 'standard'),
                    'char_topup_label' => $charTopupCents > 0
                        ? '$'.number_format($charTopupCents / 100, 0).__('ui.pricing.topup_per_million')
                        : __('ui.pricing.not_allowed'),
                    'voice_tier' => VoiceTier::label((string) ($plan->voice_tier ?: 'standard')),
                    'is_featured' => (bool) ($plan->is_featured ?? false),
                ];
            })
            ->values();

        $featuredSlug = (string) ($plans->firstWhere('is_featured', true)['slug'] ?? '');
        $popularSlug = $featuredSlug !== ''
            ? $featuredSlug
            : (
                $plans->contains(fn (array $plan) => $plan['slug'] === 'creator')
                    ? 'creator'
                    : (string) ($plans->firstWhere('price_monthly', '>', 0)['slug'] ?? '')
            );

        $plans = $plans
            ->map(function (array $plan) use ($popularSlug): array {
                $plan['is_popular'] = $popularSlug !== '' && $plan['slug'] === $popularSlug;

                return $plan;
            })
            ->all();

        return view('upgrade', [
            'activePlan' => $activePlan,
            'currentPlanSlug' => $currentPlanSlug,
            'plans' => $plans,
        ]);
    }
}
