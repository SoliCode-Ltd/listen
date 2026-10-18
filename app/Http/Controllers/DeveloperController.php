<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\ApiKeyUsage;
use App\Models\Plan;
use App\Models\UsageCounter;
use App\Services\Api\ApiSpeechService;
use App\Services\PlanAccessService;
use App\Support\Api\ApiModels;
use App\Support\Api\ApiPlanAccess;
use App\Support\Api\ApiScopes;
use App\Support\Api\ApiVoiceCatalog;
use App\Support\PlanUsageGuard;
use App\Support\UsageSnapshot;
use App\Support\VoiceTier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The developer pages: the public API documentation (/developers) and the
 * member's API keys (/dashboard/api-keys).
 */
class DeveloperController extends Controller
{
    public function __construct(private readonly PlanAccessService $planAccessService) {}

    public function docs(): View
    {
        try {
            $plans = Plan::query()
                ->subscriptionPlans()
                ->where('is_active', true)
                ->where('api_access_enabled', true)
                ->orderBy('price_cents')
                ->get();
        } catch (\Throwable) {
            // Code deployed before its migration: the page still opens, without the table.
            $plans = collect();
        }

        $locale = app()->getLocale();
        $planRows = $plans->map(fn (Plan $plan): array => [
            'name' => $plan->getLocalizedName($locale) ?: (string) $plan->name,
            'credits' => PlanUsageGuard::planChars($plan),
            'rpm' => ApiPlanAccess::requestsPerMinute($plan),
            'concurrency' => ApiPlanAccess::concurrency($plan),
            'max_chars' => min(PlanUsageGuard::maxCharsPerRequest($plan), ApiSpeechService::MAX_TEXT),
            'models' => VoiceTier::normalize((string) $plan->voice_tier) === VoiceTier::HD
                ? [ApiModels::STANDARD, ApiModels::PREMIUM]
                : [ApiModels::STANDARD],
        ])->all();

        $sampleVoice = collect(ApiVoiceCatalog::premade(VoiceTier::HD))
            ->first(fn (array $voice): bool => $voice['name'] === 'Sumaya' && $voice['language'] === 'ar')
            ?? collect(ApiVoiceCatalog::premade(VoiceTier::HD))->first(fn (array $voice): bool => $voice['language'] === 'ar');

        return view('developers.docs', [
            'planRows' => $planRows,
            'baseUrl' => url('/v1'),
            'sampleVoiceId' => $sampleVoice['voice_id'] ?? 'voice_12',
            'scopes' => ApiScopes::all(),
        ]);
    }

    public function keys(Request $request): View
    {
        $user = $request->user();
        $userId = (int) $user->id;
        $plan = $this->planAccessService->resolveAccessPlanForUserId($userId);
        $quotaPlan = $this->planAccessService->resolveQuotaPlanForUserId($userId, $plan);
        $period = now()->format('Y-m');

        $counter = UsageCounter::firstOrCreate(
            ['user_id' => $userId, 'period_ym' => $period],
            ['total_minutes' => 0, 'total_chars' => 0, 'total_requests' => 0]
        );
        $usage = UsageSnapshot::for($userId, $quotaPlan, $counter);

        $keys = ApiKey::query()->where('user_id', $userId)->orderByDesc('id')->get();
        $monthUsage = ApiKeyUsage::query()
            ->whereIn('api_key_id', $keys->pluck('id'))
            ->where('period_ym', $period)
            ->get()
            ->keyBy('api_key_id');

        return view('developers.keys', [
            'plan' => $plan,
            'planName' => $plan ? ($plan->getLocalizedName(app()->getLocale()) ?: (string) $plan->name) : __('ui.dashboard.free_plan'),
            'apiEnabled' => ApiPlanAccess::enabled($plan),
            'usage' => $usage,
            'resetsAt' => now()->startOfMonth()->addMonth(),
            'activeKeys' => $keys->whereNull('revoked_at')->values(),
            'revokedKeys' => $keys->whereNotNull('revoked_at')->take(10)->values(),
            'monthUsage' => $monthUsage,
            'apiRequests' => (int) $monthUsage->sum('requests'),
            'apiCredits' => (int) $monthUsage->sum('credits'),
            'limits' => [
                'rpm' => ApiPlanAccess::requestsPerMinute($plan),
                'concurrency' => ApiPlanAccess::concurrency($plan),
                'max_chars' => min(PlanUsageGuard::maxCharsPerRequest($quotaPlan), ApiSpeechService::MAX_TEXT),
            ],
            'scopes' => ApiScopes::all(),
            'newKey' => $request->session()->get('new_api_key_plain'),
            'maxKeys' => ApiKey::MAX_ACTIVE_PER_USER,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $plan = $this->planAccessService->resolveAccessPlanForUserId((int) $user->id);
        if (! ApiPlanAccess::enabled($plan)) {
            return redirect()->route('developers.keys')->with('error', __('developers.keys.not_in_plan_title'));
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(ApiScopes::all())],
            'monthly_credit_limit' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
        ], [
            'name.required' => __('developers.keys.name_required'),
            'scopes.required' => __('developers.keys.scopes_required'),
            'scopes.min' => __('developers.keys.scopes_required'),
            'monthly_credit_limit.*' => __('developers.keys.limit_invalid'),
        ]);

        $active = ApiKey::query()->where('user_id', $user->id)->active()->count();
        if ($active >= ApiKey::MAX_ACTIVE_PER_USER) {
            return redirect()->route('developers.keys')->with('error', __('developers.keys.max_keys', ['count' => ApiKey::MAX_ACTIVE_PER_USER]));
        }

        $scopes = ApiScopes::normalize((array) ($validated['scopes'] ?? []));
        $issued = ApiKey::issue(
            $user,
            (string) $validated['name'],
            // Every box ticked means every permission, including ones added later.
            count($scopes) === count(ApiScopes::all()) ? null : $scopes,
            isset($validated['monthly_credit_limit']) ? (int) $validated['monthly_credit_limit'] : null,
        );

        return redirect()->route('developers.keys')
            ->with('ok', __('developers.keys.flash_created'))
            // Flashed for the next page only: shown once, never stored in plain text.
            ->with('new_api_key_plain', $issued['plain']);
    }

    public function update(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $this->authorizeKey($request, $apiKey);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'monthly_credit_limit' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
        ], [
            'name.required' => __('developers.keys.name_required'),
            'monthly_credit_limit.*' => __('developers.keys.limit_invalid'),
        ]);

        $apiKey->update([
            'name' => trim((string) $validated['name']),
            'monthly_credit_limit' => isset($validated['monthly_credit_limit']) ? (int) $validated['monthly_credit_limit'] : null,
        ]);

        return redirect()->route('developers.keys')->with('ok', __('developers.keys.flash_updated'));
    }

    public function revoke(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $this->authorizeKey($request, $apiKey);
        $apiKey->revoke();

        return redirect()->route('developers.keys')->with('ok', __('developers.keys.flash_revoked'));
    }

    private function authorizeKey(Request $request, ApiKey $apiKey): void
    {
        if ((int) $apiKey->user_id !== (int) $request->user()->id || $apiKey->isRevoked()) {
            abort(404);
        }
    }
}
