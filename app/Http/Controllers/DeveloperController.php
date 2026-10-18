<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\Plan;
use App\Models\VoiceSample;
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
use App\Support\TtsStudioConfig;
use App\Support\VoiceTier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
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
     * The voices the examples use: Sumaya in Lebanese on the Arabic page, an
     * English premium voice on the English page, so each page reads in its
     * own language; and a second voice of the other gender for the dialogue.
     * The top of the page shows a real recording of the site with the request
     * that makes it, so the reader hears what the request returns.
     *
     * @return array{0: array<string, mixed>, 1: array{voice_id:string, name:string}, 2: array<string, mixed>|null}
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
        $hero = $this->heroSample($language, $premium);
        $voice = ($hero !== null ? $premium->get($hero['voice_id']) : null)
            ?? ($language === 'ar' ? $premium->firstWhere('name', 'Sumaya') : null)
            ?? $premium->firstWhere('featured', true)
            ?? $premium->first();
        $partner = $premium->first(fn (array $other): bool => $other['voice_id'] !== ($voice['voice_id'] ?? null)
            && ($other['gender'] ?? null) !== ($voice['gender'] ?? null))
            ?? $premium->first(fn (array $other): bool => $other['voice_id'] !== ($voice['voice_id'] ?? null));

        return [
            [
                'voice_id' => (string) ($voice['voice_id'] ?? 'voice_12'),
                'name' => (string) ($voice['name'] ?? ($language === 'ar' ? 'Sumaya' : 'Olivia')),
                'name_ar' => (string) ($voice['name_ar'] ?? ''),
                'language' => $language,
                'gender' => $voice['gender'] ?? null,
                'preview_url' => $voice['preview_url'] ?? null,
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
     * A recording the site already has (a voice sample, never a new
     * generation) of a premium voice in the page's language, with its exact
     * text, dialect and emotion: Sumaya in Lebanese first, then other
     * Lebanese samples, featured ones, and short texts.
     *
     * @param  Collection<string, array<string, mixed>>  $premium  voice id => voice
     * @return array{voice_id:string, text:string, dialect:?string, emotion:?string, audio_url:string}|null
     */
    private function heroSample(string $language, Collection $premium): ?array
    {
        $byName = $premium->keyBy('_internal_name');
        try {
            $samples = VoiceSample::query()
                ->active()
                ->whereIn('voice_name', $byName->keys()->all())
                ->get(['voice_name', 'audio_path', 'sample_text', 'language', 'dialect', 'emotion', 'is_featured', 'metadata']);
        } catch (\Throwable) {
            return null;
        }

        $rank = fn (VoiceSample $sample): array => [
            $language === 'ar' && ($byName[$sample->voice_name]['name'] ?? '') === 'Sumaya' ? 0 : 1,
            $sample->dialect === 'ar-LB' ? 0 : 1,
            $sample->is_featured ? 0 : 1,
            mb_strlen((string) $sample->sample_text),
        ];
        $sample = $samples
            ->filter(function (VoiceSample $sample) use ($language): bool {
                $text = trim((string) $sample->sample_text);
                $path = ltrim(trim((string) $sample->audio_path), '/');

                // Short texts first (see $rank); a long one still beats no recording.
                return $text !== '' && mb_strlen($text) <= 600
                    // Dialogue samples need speakers the request would have to list.
                    && empty(($sample->metadata ?? [])['turns'])
                    && in_array(strtolower(substr((string) ($sample->language ?: $language), 0, 2)), [$language], true)
                    && $path !== ''
                    && (Storage::disk('public')->exists($path) || is_file(public_path($path)));
            })
            ->sort(fn (VoiceSample $a, VoiceSample $b): int => $rank($a) <=> $rank($b))
            ->first();
        if (! $sample instanceof VoiceSample) {
            return null;
        }

        $dialect = trim((string) $sample->dialect);
        $emotion = trim((string) $sample->emotion);

        return [
            'voice_id' => (string) $byName[$sample->voice_name]['voice_id'],
            'text' => trim((string) $sample->sample_text),
            'dialect' => in_array($dialect, TtsStudioConfig::dialectValues($language), true) ? $dialect : null,
            'emotion' => in_array($emotion, TtsStudioConfig::emotionValues(), true) ? $emotion : null,
            'audio_url' => url((string) AudioUrl::fromPath((string) $sample->audio_path)),
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
