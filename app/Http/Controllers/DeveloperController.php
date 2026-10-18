<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\Plan;
use App\Services\Api\ApiAccountSummary;
use App\Services\Api\ApiSpeechService;
use App\Services\PlanAccessService;
use App\Support\Api\ApiDocs;
use App\Support\Api\ApiModels;
use App\Support\Api\ApiPlanAccess;
use App\Support\Api\ApiScopes;
use App\Support\Api\ApiVoiceCatalog;
use App\Support\PlanUsageGuard;
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
                ->orderBy('price_cents')
                ->get()
                // The same rule the API applies on every request.
                ->filter(fn (Plan $plan): bool => ApiPlanAccess::enabled($plan))
                ->values();
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

        return view('developers.docs', [
            'docs' => ApiDocs::build($locale, url('/v1'), $this->sampleVoice($locale)),
            'planRows' => $planRows,
            'baseUrl' => url('/v1'),
        ]);
    }

    /**
     * The voice the examples use: Sumaya in Lebanese on the Arabic page, an
     * English premium voice on the English page, so each page reads in its
     * own language.
     *
     * @return array{voice_id:string, name:string, language:string, gender:?string}
     */
    private function sampleVoice(string $locale): array
    {
        try {
            $voices = collect(ApiVoiceCatalog::premade(VoiceTier::HD, false));
        } catch (\Throwable) {
            $voices = collect();
        }
        $language = $locale === 'ar' ? 'ar' : 'en';
        $premium = $voices->filter(fn (array $voice): bool => $voice['language'] === $language && $voice['_tier'] === VoiceTier::HD);
        $voice = ($language === 'ar' ? $premium->firstWhere('name', 'Sumaya') : null)
            ?? $premium->firstWhere('featured', true)
            ?? $premium->first();

        return [
            'voice_id' => (string) ($voice['voice_id'] ?? 'voice_12'),
            'name' => (string) ($voice['name'] ?? ($language === 'ar' ? 'Sumaya' : 'Olivia')),
            'language' => $language,
            'gender' => $voice['gender'] ?? null,
        ];
    }

    public function keys(Request $request, ApiAccountSummary $summary): View
    {
        return view('developers.keys', $summary->for($request->user()) + [
            'scopes' => ApiScopes::all(),
            'newKey' => $request->session()->get('new_api_key_plain'),
            'maxKeys' => ApiKey::MAX_ACTIVE_PER_USER,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $plan = $this->planAccessService->resolveAccessPlanForUserId((int) $user->id);
        $status = ApiPlanAccess::statusFor((int) $user->id, $plan);
        if ($status !== ApiPlanAccess::OK) {
            return redirect()->route('developers.keys')->with('error', $status === ApiPlanAccess::TRIAL
                ? __('developers.keys.trial_title')
                : __('developers.keys.not_in_plan_title'));
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
