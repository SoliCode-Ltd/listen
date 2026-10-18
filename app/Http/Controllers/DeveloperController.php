<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\Plan;
use App\Services\Api\ApiAccountSummary;
use App\Services\Api\ApiSpeechService;
use App\Services\PlanAccessService;
use App\Support\Api\ApiDocs;
use App\Support\Api\ApiFeatureGate;
use App\Support\Api\ApiPlanAccess;
use App\Support\Api\ApiScopes;
use App\Support\Api\ApiVoiceCatalog;
use App\Support\AudioUrl;
use App\Support\PlanUsageGuard;
use App\Support\VoiceTier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The developer pages: the public API documentation (/developers), and the
 * member's API keys, managed in the dashboard's Developer API section.
 */
class DeveloperController extends Controller
{
    public function __construct(private readonly PlanAccessService $planAccessService) {}

    public function docs(): View
    {
        $locale = app()->getLocale();
        // Read from the plans on every visit, so editing a plan in the admin
        // changes this table at once (the same rules the API applies).
        $planRows = ApiFeatureGate::apiPlans()->map(fn (Plan $plan): array => [
            'name' => $plan->getLocalizedName($locale) ?: (string) $plan->name,
            'credits' => PlanUsageGuard::planChars($plan),
            'is_pack' => $plan->isPack(),
            'daily_requests' => PlanUsageGuard::dailyRequests($plan),
            'rpm' => ApiPlanAccess::requestsPerMinute($plan),
            'concurrency' => ApiPlanAccess::concurrency($plan),
            'max_chars' => ApiFeatureGate::maxChars($plan),
            'formats' => ApiSpeechService::formatsFor($plan),
            'features' => [
                ApiFeatureGate::CUSTOM_DIRECTION => ApiFeatureGate::planOffers($plan, ApiFeatureGate::CUSTOM_DIRECTION),
                ApiFeatureGate::SOUND_ENVIRONMENTS => ApiFeatureGate::planOffers($plan, ApiFeatureGate::SOUND_ENVIRONMENTS),
                ApiFeatureGate::DIALOGUE => ApiFeatureGate::planOffers($plan, ApiFeatureGate::DIALOGUE),
                ApiFeatureGate::CLONED_VOICES => ApiFeatureGate::planOffers($plan, ApiFeatureGate::CLONED_VOICES),
            ],
        ])->all();

        [$voice, $partner, $hero] = $this->sampleVoices($locale);

        return view('developers.docs', [
            'docs' => ApiDocs::build($locale, url('/v1'), $voice, $partner, $hero),
            'voice' => $voice,
            'hero' => $hero,
            'planRows' => $planRows,
            'baseUrl' => url('/v1'),
        ]);
    }

    /**
     * The voices the examples use, and the request at the top of the page
     * with the recording it returned (database/seeders/data/
     * developer_docs_samples.php): Othman in Egyptian on the Arabic page, an
     * American voice on the English page, so each page reads in its own
     * language; and a second voice of the other gender for the dialogue.
     *
     * @return array{0: array<string, mixed>, 1: array{voice_id:string, name:string}, 2: array{voice_id:string, body:array<string, mixed>, audio_url:?string}|null}
     */
    private function sampleVoices(string $locale): array
    {
        try {
            $voices = collect(ApiVoiceCatalog::premade(VoiceTier::HD));
        } catch (\Throwable) {
            $voices = collect();
        }
        $language = $locale === 'ar' ? 'ar' : 'en';
        $premium = $voices->filter(fn (array $voice): bool => $voice['language'] === $language);
        $sample = (require database_path('seeders/data/developer_docs_samples.php'))[$language] ?? null;
        $voice = ($sample !== null ? $premium->firstWhere('_internal_name', $sample['voice']) : null)
            ?? ($language === 'ar' ? $premium->firstWhere('name', 'Sumaya') : null)
            ?? $premium->firstWhere('featured', true)
            ?? $premium->first();
        $partner = $premium->first(fn (array $other): bool => $other['voice_id'] !== ($voice['voice_id'] ?? null)
            && ($other['gender'] ?? null) !== ($voice['gender'] ?? null))
            ?? $premium->first(fn (array $other): bool => $other['voice_id'] !== ($voice['voice_id'] ?? null));

        // The request is shown only for the voice it was made with, and plays only when its recording is here.
        $hero = null;
        if ($sample !== null && $voice !== null && ($voice['_internal_name'] ?? null) === $sample['voice']) {
            $hero = [
                'voice_id' => (string) $voice['voice_id'],
                'body' => $sample['body'],
                'audio_url' => is_file(public_path($sample['audio'])) ? url((string) AudioUrl::fromPath($sample['audio'])) : null,
            ];
        }

        return [
            [
                'voice_id' => (string) ($voice['voice_id'] ?? 'voice_12'),
                'name' => (string) ($voice['name'] ?? ($language === 'ar' ? 'Sumaya' : 'Olivia')),
                'name_ar' => (string) ($voice['name_ar'] ?? ''),
                'language' => $language,
                'gender' => $voice['gender'] ?? null,
                'avatar_url' => $voice['avatar_url'] ?? null,
            ],
            [
                'voice_id' => (string) ($partner['voice_id'] ?? ($language === 'ar' ? 'voice_14' : 'voice_31')),
                'name' => (string) ($partner['name'] ?? ''),
            ],
            $hero,
        ];
    }

    /**
     * Keys are managed in the dashboard (Developer API section); the old
     * address takes members there, or to the documentation when their plan
     * has no API section.
     */
    public function keys(Request $request): RedirectResponse
    {
        $plan = $this->planAccessService->resolveAccessPlanForUserId((int) $request->user()->id);

        return ApiAccountSummary::shownFor($plan)
            ? redirect()->to(self::section())
            : redirect()->route('developers');
    }

    /** The dashboard's Developer API section. */
    public static function section(): string
    {
        return route('dashboard').'#developerApiSection';
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $plan = $this->planAccessService->resolveAccessPlanForUserId((int) $user->id);
        $status = ApiPlanAccess::statusFor((int) $user->id, $plan);
        if ($status !== ApiPlanAccess::OK) {
            return redirect()->to(self::section())->with('api_error', $status === ApiPlanAccess::TRIAL
                ? __('developers.keys.trial_title')
                : __('developers.keys.not_in_plan_title'));
        }

        $validator = Validator::make($request->all(), [
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
        if ($validator->fails()) {
            return redirect()->to(self::section())->withErrors($validator, 'apiKeys')->withInput();
        }
        $validated = $validator->validated();

        $active = ApiKey::query()->where('user_id', $user->id)->active()->count();
        if ($active >= ApiKey::MAX_ACTIVE_PER_USER) {
            return redirect()->to(self::section())->with('api_error', __('developers.keys.max_keys', ['count' => ApiKey::MAX_ACTIVE_PER_USER]));
        }

        $scopes = ApiScopes::normalize((array) ($validated['scopes'] ?? []));
        $issued = ApiKey::issue(
            $user,
            (string) $validated['name'],
            // Every box ticked means every permission, including ones added later.
            count($scopes) === count(ApiScopes::all()) ? null : $scopes,
            isset($validated['monthly_credit_limit']) ? (int) $validated['monthly_credit_limit'] : null,
        );

        return redirect()->to(self::section())
            ->with('api_ok', __('developers.keys.flash_created'))
            // Flashed for the next page only: shown once, never stored in plain text.
            ->with('new_api_key_plain', $issued['plain']);
    }

    public function update(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $this->authorizeKey($request, $apiKey);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:80'],
            'monthly_credit_limit' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
        ], [
            'name.required' => __('developers.keys.name_required'),
            'monthly_credit_limit.*' => __('developers.keys.limit_invalid'),
        ]);
        if ($validator->fails()) {
            return redirect()->to(self::section())->withErrors($validator, 'apiKeys');
        }
        $validated = $validator->validated();

        $apiKey->update([
            'name' => trim((string) $validated['name']),
            'monthly_credit_limit' => isset($validated['monthly_credit_limit']) ? (int) $validated['monthly_credit_limit'] : null,
        ]);

        return redirect()->to(self::section())->with('api_ok', __('developers.keys.flash_updated'));
    }

    public function revoke(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $this->authorizeKey($request, $apiKey);
        $apiKey->revoke();

        return redirect()->to(self::section())->with('api_ok', __('developers.keys.flash_revoked'));
    }

    private function authorizeKey(Request $request, ApiKey $apiKey): void
    {
        if ((int) $apiKey->user_id !== (int) $request->user()->id || $apiKey->isRevoked()) {
            abort(404);
        }
    }
}
