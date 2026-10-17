<?php

namespace App\Http\Controllers;

use App\Exceptions\TranslationFailedException;
use App\Models\DemoVoiceGeneration;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\VoiceSample;
use App\Rules\DoesNotContainProfanity;
use App\Services\Billing\CharPackService;
use App\Services\Billing\TrialEligibilityService;
use App\Services\DemoFraudService;
use App\Services\GenerationErrorRecorder;
use App\Services\LocalizedPricingService;
use App\Services\PlanAccessService;
use App\Services\ProfanityFilter;
use App\Services\Referral\DemoRewardLedgerService;
use App\Services\Referral\ReferralQualificationService;
use App\Services\TranslationService;
use App\Services\Tts\TtsRoutingService;
use App\Services\TtsService;
use App\Support\AudioEffects;
use App\Support\HeroCast;
use App\Support\AudioUrl;
use App\Support\DemoAllowance;
use App\Support\DialogueSpeakers;
use App\Support\DiscountTerms;
use App\Support\FreeAllowance;
use App\Support\PlanPricingLines;
use App\Support\SeoDialectPages;
use App\Support\SeoEnglishPages;
use App\Support\SeoHelper;
use App\Support\SpeakerAvatar;
use App\Support\SpeechLanguages;
use App\Support\TtsStudioConfig;
use App\Support\UsageEstimator;
use App\Support\VoiceCatalog;
use App\Support\VoiceFlag;
use App\Support\VoiceTier;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HomeController extends Controller
{
    private const DEMO_DEVICE_COOKIE = 'listen_demo_device';

    private const DEMO_QUOTA_LOCK = 'demo:quota:lock';

    // A request that dies mid-generation must not hold its chars forever.
    private const DEMO_PENDING_TTL_SECONDS = 600;

    public function __construct(
        private readonly PlanAccessService $planAccessService,
        private readonly LocalizedPricingService $localizedPricingService,
        private readonly TtsRoutingService $ttsRoutingService,
    ) {}

    public function index()
    {
        $isArabicLocale = $this->sampleLanguageForLocale(app()->getLocale()) === 'ar';

        return view('landing', [
            'countryContext' => $this->localizedPricingService->resolveCountryContext(request()),
            'trialPromo' => app(TrialEligibilityService::class)->promoFor(request()->user()),
            'environmentSamples' => $this->environmentSamples($isArabicLocale ? 'ar' : 'en'),
        ] + $this->homeShowcase($isArabicLocale));
    }

    /**
     * The homepage sound environments: the same voice clean and placed in its
     * environment, in the page language, only for clips that are on disk.
     *
     * @return array<int, array<string, string>>
     */
    private function environmentSamples(string $language): array
    {
        $samples = (require database_path('seeders/data/environment_samples.php'))['samples'];
        $arabic = $language === 'ar';
        $out = [];
        foreach ($samples as $sample) {
            $place = 'audio/environments/'.$sample['slug'].'.mp3';
            $clean = 'audio/environments/clean/'.$sample['slug'].'.mp3';
            if ($sample['language'] !== $language || ! is_file(public_path($place)) || ! is_file(public_path($clean))) {
                continue;
            }
            $out[] = [
                'id' => $sample['slug'],
                'effect' => $sample['effect'],
                'place' => AudioEffects::label($sample['effect'], $arabic),
                'about' => (string) (AudioEffects::forPicker($arabic)[$sample['effect']]['d'] ?? ''),
                'scene' => $sample['scene'],
                'speaker' => $sample['speaker'],
                'text' => $sample['text'],
                'language' => $sample['language'],
                'place_url' => asset($place).'?v='.filemtime(public_path($place)),
                'clean_url' => asset($clean).'?v='.filemtime(public_path($clean)),
            ];
        }

        return $out;
    }

    /**
     * Homepage voice samples in the page language only: Arabic dialects on
     * Arabic pages, English accents on English pages. The gallery holds up
     * to the admin limit; the dialogues follow it.
     *
     * @return array{showcaseVoices: array<int, array<string, mixed>>, showcaseDialogues: array<int, array<string, mixed>>}
     */
    private function homeShowcase(bool $isArabicLocale): array
    {
        $language = $isArabicLocale ? 'ar' : 'en';
        $limit = $this->landingVoiceSampleLimit();

        return Cache::remember('home_showcase:'.$language.':'.$limit, 300, function () use ($isArabicLocale, $language, $limit): array {
            $samples = VoiceSample::query()
                ->active()
                ->whereIn('category', ['hero', 'dialogue'])
                ->where('language', $language)
                ->orderByDesc('is_featured')
                ->ordered()
                ->get()
                ->filter(fn (VoiceSample $sample): bool => trim((string) $sample->audio_path) !== '');

            return [
                'showcaseVoices' => $samples
                    ->filter(fn (VoiceSample $sample): bool => $sample->category === 'hero' && (bool) $sample->show_in_showcase)
                    ->take($limit)
                    ->map(fn (VoiceSample $sample): array => $this->showcaseCard($sample, $isArabicLocale))
                    ->values()
                    ->all(),
                'showcaseDialogues' => $samples
                    ->where('category', 'dialogue')
                    ->map(fn (VoiceSample $sample): array => $this->showcaseCard($sample, $isArabicLocale))
                    ->values()
                    ->all(),
            ];
        });
    }

    /**
     * Every sample made with the latest voices, on one page: the hero cast on
     * the stage, Arabic dialects, English accents, moods, dialogues and sound
     * environments, in both languages, labelled in the page language.
     */
    public function samplesPage()
    {
        $isArabicLocale = app()->getLocale() === 'ar';
        $language = $isArabicLocale ? 'ar' : 'en';

        // Each page holds its own language: Arabic samples on the Arabic page, English on the English one.
        $data = Cache::remember('samples_page:'.$language, 300, function () use ($isArabicLocale, $language): array {
            $cards = VoiceSample::query()
                ->active()
                ->whereIn('category', ['hero', 'dialogue'])
                ->where('language', $language)
                // Made by the latest voices (imported by tts:showcase); older recordings stay off this page.
                ->where('provider', 'gemini')
                ->orderByDesc('is_featured')
                ->ordered()
                ->get()
                ->filter(fn (VoiceSample $sample): bool => trim((string) $sample->audio_path) !== '')
                ->map(fn (VoiceSample $sample): array => $this->showcaseCard($sample, $isArabicLocale));
            $singles = $cards->where('kind', 'single');

            return [
                'voices' => $singles->values()->all(),
                'moodVoices' => $singles->filter(fn (array $card): bool => $card['emotion'] !== '')->values()->all(),
                'dialogues' => $cards->where('kind', 'dialogue')->values()->all(),
            ];
        });

        return view('samples', $data + [
            'stageCast' => array_values(array_filter(HeroCast::for($language), fn (array $person): bool => $person['audio'] !== null)),
            'environments' => $this->environmentSamples($language),
        ]);
    }

    public function pricingPage(Request $request)
    {
        $activePlanSlug = null;
        if ($request->user()) {
            $activePlan = $this->planAccessService->resolveActivePlanForUserId((int) $request->user()->id);
            $activePlanSlug = (string) ($activePlan?->slug ?? 'free');
        }

        return view('pricing', [
            'pricingPlans' => $this->pricingPlans(),
            'activePlanSlug' => $activePlanSlug,
            'countryContext' => $this->localizedPricingService->resolveCountryContext($request),
            'yearlyDiscountPercent' => SiteSetting::yearlyDiscountPercent(),
            'trialPromo' => app(TrialEligibilityService::class)->promoFor($request->user()),
        ]);
    }

    public function startFreeTrial(Request $request)
    {
        $cycle = $request->query('billing_cycle') === 'yearly' ? 'yearly' : 'monthly';
        $query = ['billing_cycle' => $cycle, 'payment_method' => 'stripe'];
        $plan = app(TrialEligibilityService::class)->trialPlan((string) $request->query('plan', ''), $cycle);
        if ($plan) {
            $query = ['plan' => $plan->slug] + $query;
        }
        $billingUrl = route('billing.page', $query);

        if ($request->user()) {
            return redirect()->to($billingUrl);
        }

        $request->session()->put('url.intended', $billingUrl);

        return redirect()->route('register');
    }

    public function seoLandingPage(Request $request, string $page)
    {
        if (! in_array($page, ['ai_text_to_speech', 'ai_voice_generator', 'text_to_speech_arabic', 'text_to_speech'], true)) {
            abort(404);
        }

        $locale = str_starts_with(strtolower((string) app()->getLocale()), 'ar') ? 'ar' : 'en';
        $content = $this->seoLandingContent($page, $locale);

        return view('seo.landing-page', [
            'page' => $page,
            'pageContent' => $content,
            'faqItems' => $content['faqs'] ?? [],
            'ctaPrimaryUrl' => $request->user() ? route('dashboard') : route('register'),
            // English pages play an English voice, Arabic pages a Fusha one: no single dialect is favoured.
            'sample' => $locale === 'ar' ? SeoDialectPages::sample('fusha', $locale) : SeoEnglishPages::sample('american'),
        ]);
    }

    public function dialectHubPage(Request $request)
    {
        $locale = str_starts_with(strtolower((string) app()->getLocale()), 'ar') ? 'ar' : 'en';
        $content = SeoDialectPages::hubContent($locale);

        return view('seo.landing-page', [
            'page' => SeoDialectPages::HUB_PAGE,
            'pageContent' => $content,
            'faqItems' => $content['faqs'],
            'ctaPrimaryUrl' => $request->user() ? route('dashboard') : route('register'),
            'dialectGroups' => SeoDialectPages::hubGroups($locale),
        ]);
    }

    public function accentHubPage(Request $request)
    {
        $locale = str_starts_with(strtolower((string) app()->getLocale()), 'ar') ? 'ar' : 'en';
        $content = SeoEnglishPages::hubContent($locale);

        return view('seo.landing-page', [
            'page' => SeoEnglishPages::HUB_PAGE,
            'pageContent' => $content,
            'faqItems' => $content['faqs'],
            'ctaPrimaryUrl' => $request->user() ? route('dashboard') : route('register'),
            'dialectGroups' => SeoEnglishPages::hubGroups($locale),
            'sample' => SeoEnglishPages::sample('american') ?? SeoEnglishPages::sample('british'),
        ]);
    }

    public function accentPage(Request $request, string $accent)
    {
        $locale = str_starts_with(strtolower((string) app()->getLocale()), 'ar') ? 'ar' : 'en';
        $content = SeoEnglishPages::content($accent, $locale);

        return view('seo.landing-page', [
            'page' => SeoEnglishPages::pageKey($accent),
            'pageContent' => $content,
            'faqItems' => $content['faqs'],
            'ctaPrimaryUrl' => $request->user() ? route('dashboard') : route('register'),
            'sample' => SeoEnglishPages::sample($accent),
            'currentAccent' => $accent,
        ]);
    }

    public function dialectPage(Request $request, string $dialect)
    {
        $locale = str_starts_with(strtolower((string) app()->getLocale()), 'ar') ? 'ar' : 'en';
        $content = SeoDialectPages::content($dialect, $locale);

        return view('seo.landing-page', [
            'page' => SeoDialectPages::pageKey($dialect),
            'pageContent' => $content,
            'faqItems' => $content['faqs'],
            'ctaPrimaryUrl' => $request->user() ? route('dashboard') : route('register'),
            'sample' => SeoDialectPages::sample($dialect, $locale),
            'currentDialect' => $dialect,
        ]);
    }

    public function demoPage(Request $request)
    {
        $this->syncCurrentUserDeviceIdentity($request);

        $defaultTier = VoiceTier::HD;
        $defaultProvider = $this->demoPreferredProviderForTier($defaultTier);
        $defaultVoiceKeysByTier = [];

        foreach (VoiceTier::all() as $tier) {
            $provider = $this->demoPreferredProviderForTier($tier);
            $defaultVoiceKeysByTier[$tier] = [
                'ar' => (string) data_get(
                    VoiceCatalog::voiceMeta(VoiceCatalog::defaultForLanguageAndProvider('ar', $tier, $provider)),
                    'key',
                    ''
                ),
                'en' => (string) data_get(
                    VoiceCatalog::voiceMeta(VoiceCatalog::defaultForLanguageAndProvider('en', $tier, $provider)),
                    'key',
                    ''
                ),
            ];
        }

        $upgradePlanSlug = (string) (Plan::query()->subscriptionPlans()
            ->where('is_active', true)
            ->where('price_cents', '>', 0)
            ->orderBy('price_cents')
            ->value('slug') ?? 'starter');

        $response = response()->view('demo', [
            'voiceCatalog' => $this->demoVoiceCatalog(),
            'defaultTier' => $defaultTier,
            'defaultVoiceAr' => VoiceCatalog::voiceMeta(VoiceCatalog::defaultForLanguageAndProvider('ar', $defaultTier, $defaultProvider)),
            'defaultVoiceEn' => VoiceCatalog::voiceMeta(VoiceCatalog::defaultForLanguageAndProvider('en', $defaultTier, $defaultProvider)),
            'defaultVoiceKeysByTier' => $defaultVoiceKeysByTier,
            'studioPerformanceStyles' => TtsStudioConfig::performanceStyleCatalog(),
            'studioEmotions' => TtsStudioConfig::emotionCatalog(),
            'studioDialectsByLanguage' => TtsStudioConfig::dialectCatalogByLanguage(),
            'studioDefaultsByLanguage' => [
                'ar' => [
                    'performance_style' => TtsStudioConfig::defaultPerformanceStyle('ar'),
                    'emotion' => TtsStudioConfig::defaultEmotion('ar'),
                    'dialect' => TtsStudioConfig::defaultDialect('ar'),
                ],
                'en' => [
                    'performance_style' => TtsStudioConfig::defaultPerformanceStyle('en'),
                    'emotion' => TtsStudioConfig::defaultEmotion('en'),
                    'dialect' => TtsStudioConfig::defaultDialect('en'),
                ],
            ],
            'demoQuota' => $this->demoQuotaForPageLoad($request),
            'trialAllowanceChars' => $this->demoCharAllowance($request),
            'upgradePlanSlug' => $upgradePlanSlug,
            'demoTiers' => array_map(fn (string $tier) => [
                'key' => $tier,
                'label' => VoiceTier::label($tier),
            ], VoiceTier::all()),
        ]);

        return $this->attachDemoDeviceCookie($request, $response);
    }

    public function demoQuota(Request $request)
    {
        $this->syncCurrentUserDeviceIdentity($request);

        return response()->json([
            'ok' => true,
            'quota' => $this->demoQuotaForClient($request),
        ]);
    }

    public function demo(Request $request)
    {
        $this->syncCurrentUserDeviceIdentity($request);

        try {
            $maxChars = $this->demoCharAllowance($request);
            $demoPlan = $this->planAccessService->freePlan();
            $defaultTier = $this->planAccessService->resolveTierForPlan($demoPlan);
            $requestedTier = VoiceTier::normalize((string) ($request->input('voice_tier') ?? $defaultTier));

            if (VoiceTier::normalize($requestedTier) === VoiceTier::HD) {
                $request->merge(TtsStudioConfig::withDefaultChoices($request->only(['performance_style', 'emotion']), $request->input('target_language')));
            }
            $this->ensureRequiredStudioSelections($requestedTier, [
                'performance_style' => $request->input('performance_style'),
                'emotion' => $request->input('emotion'),
            ]);
            $performanceStyleValues = TtsStudioConfig::performanceStyleValues();
            $emotionValues = TtsStudioConfig::emotionValues();

            $validated = $request->validate([
                'text' => ['required', 'string', 'max:50000', new DoesNotContainProfanity(app(ProfanityFilter::class))],
                'target_language' => ['required', 'in:'.implode(',', SpeechLanguages::codes())],
                'translate' => ['nullable', 'boolean'],
                'voice_key' => ['nullable', 'string', 'max:60'],
                'voice_tier' => ['nullable', 'in:standard,hd'],
                // Studio parameters
                'dialect' => ['nullable', 'string', 'max:10'],
                'performance_style' => ['nullable', 'string', 'in:'.implode(',', $performanceStyleValues)],
                'style_prompt' => ['nullable', 'string', 'max:2000'],
                'emotion' => ['nullable', 'string', 'in:'.implode(',', $emotionValues)],
                'speed' => ['nullable', 'numeric', 'min:0.5', 'max:2.0'],
                'pitch' => ['nullable', 'numeric', 'min:-10', 'max:10'],
                'audio_effect' => ['nullable', 'string', 'in:'.implode(',', AudioEffects::keys())],
            ] + DialogueSpeakers::rules(), [
                'performance_style.in' => __('ui.backend.performance_style_invalid'),
                'emotion.in' => __('ui.backend.emotion_invalid'),
            ]);
            $tier = VoiceTier::normalize((string) ($validated['voice_tier'] ?? $defaultTier));
            if (SpeechLanguages::premiumOnly($validated['target_language']) && $tier !== VoiceTier::HD) {
                throw ValidationException::withMessages(['target_language' => __('ui.backend.dashboard_language_not_available_for_tier')]);
            }
            if ($validated['is_dialogue'] ?? false) {
                DialogueSpeakers::ensureUsable($tier, (array) ($validated['dialogue_speakers'] ?? []), (string) $validated['text'], 'text');
            }
            $voiceName = $this->normalizeVoiceSelection(
                $validated['target_language'],
                $validated['voice_key'] ?? null,
                $tier,
            );

            $text = trim($validated['text']);
            // Moods and sounds go with words: a script of tags alone has nothing to speak.
            if (! \App\Support\GeminiVocalTags::hasWords($text)) {
                throw ValidationException::withMessages(['text' => __('ui.backend.dashboard_text_needs_words')]);
            }
            $sourceLanguage = SpeechLanguages::detect($text);
            $estimate = UsageEstimator::estimate($text);
            if ($estimate['chars'] > $maxChars) {
                throw ValidationException::withMessages([
                    'text' => $this->demoLimitExceededMessage($request, $maxChars),
                ]);
            }

            $quota = $this->enforceDemoPolicy($request, $estimate['chars'], false, false);

            return response()->json([
                'ok' => true,
                'message' => __('ui.backend.demo_setup_validated'),
                'source_language' => $sourceLanguage,
                'target_language' => $validated['target_language'],
                'translate' => (bool) ($validated['translate'] ?? false),
                'translate_note' => (bool) ($validated['translate'] ?? false)
                    ? __('ui.backend.demo_translate_note_before')
                    : __('ui.backend.demo_translate_note_as_is'),
                'voice' => VoiceCatalog::voiceMeta($voiceName),
                'voice_tier' => $tier,
                'voice_tier_label' => VoiceTier::label($tier),
                'estimated_chars' => $estimate['chars'],
                'estimated_seconds' => $estimate['seconds'],
                'estimated_minutes' => $estimate['minutes'],
                'quota' => $quota,
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $firstError = '';
            foreach ($errors as $messages) {
                if (is_array($messages) && isset($messages[0]) && is_string($messages[0])) {
                    $firstError = trim($messages[0]);
                    if ($firstError !== '') {
                        break;
                    }
                }
            }

            return response()->json([
                'ok' => false,
                'message' => $firstError !== '' ? $firstError : __('ui.backend.request_validation_failed'),
                'errors' => $errors,
                'quota' => $this->demoQuotaForClient($request),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Demo validation failed unexpectedly.', [
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.request_validation_failed'),
                'quota' => $this->demoQuotaForClient($request),
            ], 422);
        }
    }

    public function preview(
        Request $request,
        TranslationService $translationService,
        TtsService $ttsService,
    ) {
        $this->syncCurrentUserDeviceIdentity($request);

        $maxChars = $this->demoCharAllowance($request);
        $demoPlan = $this->planAccessService->freePlan();
        $defaultTier = $this->planAccessService->resolveTierForPlan($demoPlan);
        $requestedTier = VoiceTier::normalize((string) ($request->input('voice_tier') ?? $defaultTier));

        if (VoiceTier::normalize($requestedTier) === VoiceTier::HD) {
            $request->merge(TtsStudioConfig::withDefaultChoices($request->only(['performance_style', 'emotion']), $request->input('target_language')));
        }
        $this->ensureRequiredStudioSelections($requestedTier, [
            'performance_style' => $request->input('performance_style'),
            'emotion' => $request->input('emotion'),
        ]);
        $performanceStyleValues = TtsStudioConfig::performanceStyleValues();
        $emotionValues = TtsStudioConfig::emotionValues();

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:50000', new DoesNotContainProfanity(app(ProfanityFilter::class))],
            'target_language' => ['required', 'in:'.implode(',', SpeechLanguages::codes())],
            'translate' => ['nullable', 'boolean'],
            'voice_key' => ['nullable', 'string', 'max:60'],
            'voice_tier' => ['nullable', 'in:standard,hd'],
            // Studio parameters
            'dialect' => ['nullable', 'string', 'max:10'],
            'performance_style' => ['nullable', 'string', 'in:'.implode(',', $performanceStyleValues)],
            'style_prompt' => ['nullable', 'string', 'max:2000'],
            'emotion' => ['nullable', 'string', 'in:'.implode(',', $emotionValues)],
            'speed' => ['nullable', 'numeric', 'min:0.5', 'max:2.0'],
            'pitch' => ['nullable', 'numeric', 'min:-10', 'max:10'],
            'audio_effect' => ['nullable', 'string', 'in:'.implode(',', AudioEffects::keys())],
        ] + DialogueSpeakers::rules(), [
            'performance_style.in' => __('ui.backend.performance_style_invalid'),
            'emotion.in' => __('ui.backend.emotion_invalid'),
        ]);
        $tier = VoiceTier::normalize((string) ($validated['voice_tier'] ?? $defaultTier));
        if (SpeechLanguages::premiumOnly($validated['target_language']) && $tier !== VoiceTier::HD) {
            throw ValidationException::withMessages(['target_language' => __('ui.backend.dashboard_language_not_available_for_tier')]);
        }
        $isDialogue = (bool) ($validated['is_dialogue'] ?? false);
        if ($isDialogue) {
            DialogueSpeakers::ensureUsable($tier, (array) ($validated['dialogue_speakers'] ?? []), (string) $validated['text'], 'text');
        }
        $demoRoutingPlan = $this->demoRoutingPlan($demoPlan, $tier);
        $voiceName = $this->normalizeVoiceSelection(
            $validated['target_language'],
            $validated['voice_key'] ?? null,
            $tier,
        );

        $text = trim($validated['text']);
        // Moods and sounds go with words: a script of tags alone has nothing to speak.
        if (! \App\Support\GeminiVocalTags::hasWords($text)) {
            throw ValidationException::withMessages(['text' => __('ui.backend.dashboard_text_needs_words')]);
        }
        $sourceLanguage = SpeechLanguages::detect($text);
        $estimate = UsageEstimator::estimate($text);
        if ($estimate['chars'] > $maxChars) {
            throw ValidationException::withMessages([
                'text' => $this->demoLimitExceededMessage($request, $maxChars),
            ]);
        }

        $quotaReserved = false;
        $quota = [];

        try {
            $quota = $this->enforceDemoPolicy($request, $estimate['chars'], true, true);
            $quotaReserved = true;

            $textForSpeech = $translationService->maybeTranslate(
                $text,
                $sourceLanguage,
                $validated['target_language'],
                (bool) ($validated['translate'] ?? false),
            );

            $studioOptions = $this->sanitizeStudioOptionsForTier($tier, [
                'dialect' => SpeechLanguages::premiumOnly($validated['target_language']) ? null : ($validated['dialect'] ?? null),
                'performance_style' => $validated['performance_style'] ?? null,
                'style_prompt' => $validated['style_prompt'] ?? null,
                'emotion' => $validated['emotion'] ?? 'neutral',
                'speed' => $validated['speed'] ?? 1.0,
                'pitch' => $validated['pitch'] ?? 0.0,
            ], $demoPlan);
            $voiceMeta = VoiceCatalog::voiceMeta($voiceName);

            $voiceOptions = [
                'voice_name' => $voiceName,
                'provider' => VoiceCatalog::providerForName($voiceName),
                'tier' => $tier,
                'gender' => is_array($voiceMeta) ? ($voiceMeta['gender'] ?? null) : null,
                'performance_style' => $studioOptions['performance_style'],
                'style' => $studioOptions['performance_style'],
                'style_prompt' => $studioOptions['style_prompt'],
                'dialect' => $studioOptions['dialect'],
                'emotion' => $studioOptions['emotion'],
                'speed' => $studioOptions['speed'],
                'pitch' => $studioOptions['pitch'],
            ];
            if ($isDialogue) {
                $voiceOptions['speakers'] = DialogueSpeakers::forSpeech(
                    $validated['dialogue_speakers'] ?? [],
                    $validated['target_language'],
                    $tier,
                    is_string($voiceOptions['provider']) ? $voiceOptions['provider'] : null,
                );
            }

            $audio = $ttsService->synthesizeAdvanced(
                $textForSpeech,
                $validated['target_language'],
                $voiceOptions,
                $demoRoutingPlan,
                (int) $request->user()->id,
            );
            $audio['audio_path'] = $this->storeDemoAudioCopy((string) ($audio['audio_path'] ?? ''));
            // A sound environment, as on the dashboard: added to the demo's own copy of the
            // voice. If it cannot be added, the member still gets the clean voice.
            $effect = AudioEffects::available() ? (string) ($validated['audio_effect'] ?? '') : '';
            if ($effect !== '' && $audio['audio_path'] !== '' && ($effected = AudioEffects::apply($audio['audio_path'], $effect)) !== null) {
                if (str_starts_with($audio['audio_path'], 'audio/demo/')) {
                    Storage::disk('public')->delete($audio['audio_path']);
                }
                $audio['audio_path'] = $effected;
                $audio['audio_effect'] = $effect;
            }

            // Use original text chars for demo quota consumption — this is what the user
            // typed and what the frontend counted. Translated text may expand/contract,
            // which would cause a mismatch between the UI counter and actual billing.
            // Speech-text metrics are stored separately in metadata for analytics.
            $speechEstimate = UsageEstimator::estimate($textForSpeech);

            // Split charge: base lifetime cap first, referral reward as overflow.
            $userId = (int) $request->user()->id;
            $lifetimeCap = $this->demoCharAllowance($request);
            $usedBase = $this->trialUsedCharsByUser($userId, $request);
            $baseAvailable = max(0, $lifetimeCap - $usedBase);
            $baseCharge = (int) min($estimate['chars'], $baseAvailable);
            $rewardCharge = (int) max(0, $estimate['chars'] - $baseCharge);

            $generation = $this->storeDemoVoiceGeneration(
                $request,
                $validated,
                $text,
                $textForSpeech,
                $sourceLanguage,
                $voiceName,
                $studioOptions,
                $audio,
                $quota,
                $baseCharge,
                $speechEstimate['chars'],
                $rewardCharge,
                (int) $estimate['chars'],
            );

            if ($rewardCharge > 0) {
                app(DemoRewardLedgerService::class)->consume(
                    $request->user(),
                    $rewardCharge,
                    $generation,
                    'demo_generation_overflow',
                );
            }

            // The stored row now counts these chars. If storing failed, the
            // reservation is left to expire so the audio is still charged for a while.
            $quotaReserved = false;
            if ($generation !== null) {
                $this->releaseDemoReservation($userId, (int) $estimate['chars']);
            }

            // Trigger first-demo-generation qualification check for referrals.
            // Safe to call on every generation — idempotent via conversion status.
            try {
                app(ReferralQualificationService::class)
                    ->evaluate($request->user(), 'first_demo_generation');
            } catch (\Throwable $e) {
                Log::warning('Referral qualification (first demo) evaluation failed.', [
                    'user_id' => $userId,
                    'exception' => $e->getMessage(),
                ]);
            }

            $quota = $this->demoQuotaForClient($request);
            $this->syncStoredDemoVoiceGenerationQuota($generation, $quota);

            return response()->json([
                'ok' => true,
                'source_language' => $sourceLanguage,
                'target_language' => $validated['target_language'],
                'translate' => (bool) ($validated['translate'] ?? false),
                'translate_note' => (bool) ($validated['translate'] ?? false)
                    ? __('ui.backend.demo_translate_note_done')
                    : __('ui.backend.demo_translate_note_as_is_done'),
                'voice' => VoiceCatalog::voiceMeta($voiceName),
                'voice_tier' => $tier,
                'voice_tier_label' => VoiceTier::label($tier),
                'audio_url' => AudioUrl::fromPath($audio['audio_path']),
                'duration_seconds' => $audio['duration_seconds'] ?? $speechEstimate['seconds'],
                'estimated_chars' => $estimate['chars'],
                'quota' => $quota,
            ]);
        } catch (ValidationException $e) {
            if ($quotaReserved) {
                $quota = $this->rollbackDemoPolicy($request, $estimate['chars'], true);
            }

            $errors = $e->errors();
            $firstError = '';
            foreach ($errors as $messages) {
                if (is_array($messages) && isset($messages[0]) && is_string($messages[0])) {
                    $firstError = trim($messages[0]);
                    if ($firstError !== '') {
                        break;
                    }
                }
            }

            return response()->json([
                'ok' => false,
                'message' => $firstError !== '' ? $firstError : __('ui.backend.request_validation_failed'),
                'errors' => $errors,
                'quota' => $quota ?: $this->demoQuotaForClient($request),
            ], 422);
        } catch (\Throwable $e) {
            if ($quotaReserved) {
                $quota = $this->rollbackDemoPolicy($request, $estimate['chars'], true);
            }

            Log::error('Demo preview failed unexpectedly.', [
                'target_language' => $validated['target_language'] ?? null,
                'voice_tier' => $tier ?? null,
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
            ]);

            app(GenerationErrorRecorder::class)->demoFailed(
                (int) $request->user()->id,
                $e,
                [
                    'voice_name' => $voiceName ?? null,
                    'voice_tier' => $tier ?? null,
                    'target_language' => $validated['target_language'] ?? null,
                    'dialect' => $validated['dialect'] ?? null,
                    'performance_style' => $validated['performance_style'] ?? null,
                    'emotion' => $validated['emotion'] ?? null,
                    'translate' => (bool) ($validated['translate'] ?? false),
                    'chars' => $estimate['chars'] ?? null,
                ],
                __('ui.backend.demo_voice_generation_failed'),
            );

            return response()->json([
                'ok' => false,
                'message' => $e instanceof TranslationFailedException ? $e->getMessage() : __('ui.backend.demo_voice_generation_failed'),
                'quota' => $quota ?: $this->demoQuotaForClient($request),
            ], 422);
        }
    }

    /**
     * Checks every demo limit and, when $commit is true, reserves the chars.
     *
     * The check and the reservation run under one lock, and reserved chars
     * count as used until releaseDemoReservation() runs. Without that, many
     * requests sent at once all saw the same "used" total and all passed.
     */
    private function enforceDemoPolicy(Request $request, int $chars, bool $preview, bool $commit = true): array
    {
        $user = $request->user();
        $userId = (int) $user->id;
        $scope = $preview ? 'preview' : 'check';

        $blockReason = app(DemoFraudService::class)->demoBlockReason($request);
        if ($blockReason !== null) {
            Log::warning('Demo request refused.', ['user_id' => $userId, 'reason' => $blockReason]);

            throw ValidationException::withMessages([
                'text' => __('ui.backend.demo_access_blocked'),
            ]);
        }

        $maxPerMinute = max(1, (int) config("listen.demo.{$scope}.user_rate_per_minute", 10));

        // Rate limiting per user
        $rateKey = "demo:rate:{$scope}:u:{$userId}";
        if (RateLimiter::tooManyAttempts($rateKey, $maxPerMinute)) {
            $retryAfter = max(1, RateLimiter::availableIn($rateKey));
            throw ValidationException::withMessages([
                'text' => __('ui.backend.demo_too_many_requests', ['seconds' => $retryAfter]),
            ]);
        }
        RateLimiter::hit($rateKey, 60);

        try {
            Cache::lock(self::DEMO_QUOTA_LOCK, 10)->block(5, function () use ($request, $user, $userId, $scope, $chars, $preview, $commit): void {
                $this->assertDemoLimits($request, $user, $userId, $scope, $chars, $preview, $commit);
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'text' => __('ui.backend.demo_too_many_requests', ['seconds' => 5]),
            ]);
        }

        return $this->demoQuotaForClient($request);
    }

    /**
     * Runs inside the demo quota lock.
     */
    private function assertDemoLimits(Request $request, User $user, int $userId, string $scope, int $chars, bool $preview, bool $commit): void
    {
        $day = now()->format('Ymd');
        $dayEnd = now()->endOfDay();

        // Per-user daily caps. The check endpoint generates nothing, so only
        // its request count is capped; previews are capped on chars too.
        $userRequestKey = "demo:req:{$day}:{$scope}:u:{$userId}";
        $userRequestCap = max(1, (int) config("listen.demo.{$scope}.user_daily_requests", 150));
        $userUsedRequests = (int) Cache::get($userRequestKey, 0);
        if (($userUsedRequests + 1) > $userRequestCap) {
            throw ValidationException::withMessages([
                'text' => __('ui.backend.demo_daily_limit_reached'),
            ]);
        }

        $userCharKey = "demo:chars:{$day}:preview:u:{$userId}";
        $userUsedChars = (int) Cache::get($userCharKey, 0);
        if ($preview) {
            $userCharCap = max(1, (int) config('listen.demo.preview.user_daily_chars', 50000));
            if (($userUsedChars + $chars) > $userCharCap) {
                throw ValidationException::withMessages([
                    'text' => __('ui.backend.demo_daily_limit_reached'),
                ]);
            }
        }

        // Lifetime trial cap — shared across IP-linked and fingerprint-linked accounts,
        // counting chars that are still being generated for any of them.
        // Referral reward characters (when present) act as an overflow bucket
        // consumed only AFTER the base lifetime cap is exhausted, so users see
        // their base allowance drain naturally first.
        $linkedIds = $this->demoLinkedUserIds($userId, $request);
        $lifetimeCap = $this->demoCharAllowance($request);
        $usedChars = $this->usedDemoCharsForUserIds($linkedIds) + $this->pendingDemoChars($linkedIds);
        $overflowNeeded = max(0, ($usedChars + $chars) - $lifetimeCap);
        $rewardBalance = app(DemoRewardLedgerService::class)->balanceFor($user);

        if ($overflowNeeded > $rewardBalance) {
            throw ValidationException::withMessages([
                'text' => __('ui.backend.demo_trial_exhausted'),
            ]);
        }

        // Global preview capacity
        $globalRequestKey = "demo:req:{$day}:preview:global";
        $globalCharKey = "demo:chars:{$day}:preview:global";
        $globalUsedRequests = (int) Cache::get($globalRequestKey, 0);
        $globalUsedChars = (int) Cache::get($globalCharKey, 0);
        if ($preview) {
            $globalRequestCap = max(1, (int) config('listen.demo.global_preview_daily_requests', 3000));
            if (($globalUsedRequests + 1) > $globalRequestCap) {
                throw ValidationException::withMessages([
                    'text' => __('ui.backend.demo_preview_capacity_reached'),
                ]);
            }

            $globalCharCap = max(1, (int) config('listen.demo.global_preview_daily_chars', 500000));
            if (($globalUsedChars + $chars) > $globalCharCap) {
                throw ValidationException::withMessages([
                    'text' => __('ui.backend.demo_preview_capacity_reached'),
                ]);
            }
        }

        Cache::put($userRequestKey, $userUsedRequests + 1, $dayEnd);

        if ($preview && $commit) {
            Cache::put($userCharKey, $userUsedChars + $chars, $dayEnd);
            Cache::put($globalRequestKey, $globalUsedRequests + 1, $dayEnd);
            Cache::put($globalCharKey, $globalUsedChars + $chars, $dayEnd);

            $pendingKey = $this->pendingDemoCharsKey($userId);
            Cache::put($pendingKey, (int) Cache::get($pendingKey, 0) + $chars, self::DEMO_PENDING_TTL_SECONDS);
        }
    }

    /**
     * The generation has been stored (or failed), so its chars no longer
     * need holding as pending.
     */
    private function releaseDemoReservation(int $userId, int $chars): void
    {
        $this->withDemoQuotaLock(function () use ($userId, $chars): void {
            $this->decrementCacheCounter($this->pendingDemoCharsKey($userId), $chars, now()->addSeconds(self::DEMO_PENDING_TTL_SECONDS));
        });
    }

    private function rollbackDemoPolicy(Request $request, int $chars, bool $preview): array
    {
        if ($preview) {
            $userId = (int) $request->user()->id;
            $day = now()->format('Ymd');
            $dayEnd = now()->endOfDay();

            $this->withDemoQuotaLock(function () use ($userId, $chars, $day, $dayEnd): void {
                $this->decrementCacheCounter("demo:req:{$day}:preview:u:{$userId}", 1, $dayEnd);
                $this->decrementCacheCounter("demo:chars:{$day}:preview:u:{$userId}", $chars, $dayEnd);
                $this->decrementCacheCounter("demo:req:{$day}:preview:global", 1, $dayEnd);
                $this->decrementCacheCounter("demo:chars:{$day}:preview:global", $chars, $dayEnd);
                $this->decrementCacheCounter($this->pendingDemoCharsKey($userId), $chars, now()->addSeconds(self::DEMO_PENDING_TTL_SECONDS));
            });
        }

        return $this->demoQuotaForClient($request);
    }

    /**
     * Releasing must never fail the request: if the lock is busy the
     * counters are corrected anyway, and pending chars expire on their own.
     */
    private function withDemoQuotaLock(callable $callback): void
    {
        try {
            Cache::lock(self::DEMO_QUOTA_LOCK, 10)->block(5, $callback);
        } catch (LockTimeoutException) {
            $callback();
        }
    }

    private function decrementCacheCounter(string $key, int $amount, \DateTimeInterface $ttl): void
    {
        $current = (int) Cache::get($key, 0);
        $next = max(0, $current - max(0, $amount));
        Cache::put($key, $next, $ttl);
    }

    private function pendingDemoCharsKey(int $userId): string
    {
        return "demo:pending_chars:u:{$userId}";
    }

    /**
     * @param  list<int>  $userIds
     */
    private function pendingDemoChars(array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        $keys = array_map(fn (int $id) => $this->pendingDemoCharsKey($id), $userIds);

        return (int) array_sum(array_map('intval', Cache::many($keys)));
    }

    private function normalizeVoiceSelection(string $language, ?string $voiceKey, string $tier): string
    {
        $voiceKey = trim((string) $voiceKey);
        $preferredProvider = $this->demoPreferredProviderForTier($tier);

        if ($voiceKey === '') {
            $defaultVoice = VoiceCatalog::defaultForLanguageAndProvider($language, $tier, $preferredProvider);
            if ($defaultVoice === '') {
                throw ValidationException::withMessages([
                    'target_language' => __('ui.backend.demo_language_not_available_for_quality'),
                ]);
            }

            return $defaultVoice;
        }

        $voiceName = VoiceCatalog::nameFromKeyForLanguageAndProvider($language, $voiceKey, $tier, $preferredProvider);
        if (! $voiceName) {
            throw ValidationException::withMessages([
                'voice_key' => __('ui.backend.demo_speaker_not_available'),
            ]);
        }

        return $voiceName;
    }

    /**
     * Demo rule: standard previews use Google, premium/HD previews use Gemini when available.
     *
     * @return array<string, array<string, array<int, array<string, mixed>>>>
     */
    private function demoVoiceCatalog(): array
    {
        $catalog = [];

        foreach (VoiceTier::all() as $tier) {
            $provider = $this->demoPreferredProviderForTier($tier);
            $voices = VoiceCatalog::publicCatalog($tier, $provider);

            if (! $this->voiceCatalogHasVoices($voices)) {
                $voices = VoiceCatalog::publicCatalog($tier);
            }

            $catalog[$tier] = $voices;
        }

        return $catalog;
    }

    private function demoPreferredProviderForTier(string $tier): ?string
    {
        $normalizedTier = VoiceTier::normalize($tier);

        if ($normalizedTier === VoiceTier::STANDARD) {
            return 'google_cloud';
        }

        return $this->ttsRoutingService->getProviderByName('gemini')->isAvailable()
            ? 'gemini'
            : 'google_cloud';
    }

    private function demoRoutingPlan(?Plan $plan, string $tier): ?Plan
    {
        if (! $plan || VoiceTier::normalize($tier) === VoiceTier::STANDARD) {
            return $plan;
        }

        $routingPlan = clone $plan;
        $routingPlan->tts_provider_policy = 'auto';

        return $routingPlan;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $catalog
     */
    private function voiceCatalogHasVoices(array $catalog): bool
    {
        foreach ($catalog as $voices) {
            if (is_array($voices) && $voices !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pricingPlans(): array
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('price_cents')
            ->get();

        $locale = app()->getLocale();
        $countryContext = $this->localizedPricingService->resolveCountryContext(request());

        $packs = app(CharPackService::class);
        $viewer = auth()->user();
        $viewerCanBuyPack = $viewer ? $packs->canBuy($viewer) : true;
        $viewerHasPack = $viewer ? $packs->remaining((int) $viewer->id) > 0 : false;
        // New members only get the demo: the free card then shows the demo instead of a monthly allowance.
        $freeTryChars = FreeAllowance::freePlanGivesMonthlyChars() ? null : DemoAllowance::chars();

        $mapped = $plans->map(function (Plan $plan) use ($locale, $countryContext, $viewerCanBuyPack, $viewerHasPack, $freeTryChars): array {
            $baseMonthlyCents = max(0, (int) $plan->price_cents);
            $yearlyDiscountPercent = SiteSetting::yearlyDiscountPercent();
            $monthlyCents = max(0, (int) $plan->getEffectivePriceCents());
            $yearlyCents = max(0, (int) $plan->getEffectiveYearlyPriceCents($yearlyDiscountPercent));
            $monthly = round($monthlyCents / 100, 2);
            $yearly = round($yearlyCents / 100, 2);
            $tier = VoiceTier::normalize((string) ($plan->voice_tier ?: VoiceTier::STANDARD));
            $stripeMonthlyQuote = $this->localizedPricingService->convertedQuoteFromUsd(
                $monthlyCents,
                'monthly',
                $countryContext,
                'stripe',
                $locale
            );
            $stripeYearlyQuote = $this->localizedPricingService->convertedQuoteFromUsd(
                $yearlyCents,
                'yearly',
                $countryContext,
                'stripe',
                $locale
            );
            $manualMethod = $this->localizedPricingService->preferredManualMethodForPlan($plan, $countryContext);
            $manualMonthlyQuote = $manualMethod
                ? $this->localizedPricingService->manualQuoteForPlan($plan, 'monthly', $countryContext, $manualMethod, $locale)
                : null;
            $manualYearlyQuote = $manualMethod
                ? $this->localizedPricingService->manualQuoteForPlan($plan, 'yearly', $countryContext, $manualMethod, $locale)
                : null;
            $displayMonthlyQuote = $manualMonthlyQuote ?? $stripeMonthlyQuote;
            $displayYearlyQuote = $manualYearlyQuote ?? $stripeYearlyQuote;
            $savingsMinor = max(0, ($displayMonthlyQuote['equivalent_monthly_minor'] * 12) - $displayYearlyQuote['amount_minor']);

            $premiumMinutes = (int) ($plan->premium_voice_minutes ?? 0);
            $recentDays = (int) ($plan->recent_voices_days ?? 7);
            $topupCharCents = $plan->topup_char_price_cents !== null ? (int) $plan->topup_char_price_cents : null;
            $topupMinCents = $plan->topup_minute_price_cents !== null ? (int) $plan->topup_minute_price_cents : null;

            // Use localized name/description from admin panel, or fall back to translation file
            $localizedName = $plan->getLocalizedName($locale);
            $localizedDescription = $plan->getLocalizedDescription($locale);
            $localizedButtonText = $plan->getLocalizedButtonText($locale);

            // Lines written from the plan's settings (Admin → Plans → Pricing page)
            $features = PlanPricingLines::forPlan($plan, $locale);

            // Discount data
            $hasDiscount = $plan->hasActiveDiscount();
            $discountPercentage = $hasDiscount ? (int) $plan->discount_percentage : 0;
            $discountEndsAt = $hasDiscount && $plan->discount_ends_at ? $plan->discount_ends_at->toIso8601String() : null;
            $originalMonthlyCents = $baseMonthlyCents;

            // Original price quotes for strikethrough display
            $originalMonthlyQuote = $hasDiscount
                ? $this->localizedPricingService->convertedQuoteFromUsd($originalMonthlyCents, 'monthly', $countryContext, 'stripe', $locale)
                : null;

            // How long the discount lasts, and the regular price after it in the
            // currency the card shows (the same amount as the crossed-out price).
            $discountTerms = null;
            if ($hasDiscount && $originalMonthlyQuote) {
                $fullYearlyQuote = $this->localizedPricingService->convertedQuoteFromUsd(
                    (int) round($baseMonthlyCents * 12 * (1 - $yearlyDiscountPercent / 100)),
                    'yearly',
                    $countryContext,
                    'stripe',
                    $locale
                );
                $yearlyPercent = min(99, $discountPercentage + $yearlyDiscountPercent);
                $discountTerms = [
                    'offer_monthly' => DiscountTerms::offer($plan, 'monthly', $discountPercentage),
                    'then_monthly' => DiscountTerms::then($plan, 'monthly', (string) $originalMonthlyQuote['amount_formatted']),
                    'offer_yearly' => DiscountTerms::offer($plan, 'yearly', $yearlyPercent),
                    'then_yearly' => DiscountTerms::then($plan, 'yearly', (string) $fullYearlyQuote['amount_formatted']),
                ];
            }

            return [
                'slug' => $plan->slug,
                'name' => $localizedName,
                'name_raw' => $plan->name, // Keep raw name for internal use
                'description' => $localizedDescription,
                'button_text' => $localizedButtonText,
                'price_monthly' => $monthly,
                'price_monthly_cents' => $monthlyCents,
                'price_yearly' => $yearly,
                'price_yearly_cents' => $yearlyCents,
                'has_discount' => $hasDiscount,
                'discount_percentage' => $discountPercentage,
                'discount_ends_at' => $discountEndsAt,
                'original_price_monthly_cents' => $originalMonthlyCents,
                'original_price_monthly_formatted' => $originalMonthlyQuote ? (string) $originalMonthlyQuote['amount_formatted'] : null,
                'discount_terms' => $discountTerms,
                'display_payment_method' => $manualMethod ?? 'stripe',
                'display_currency' => (string) $displayMonthlyQuote['currency'],
                'display_price_monthly_minor' => (int) $displayMonthlyQuote['amount_minor'],
                'display_price_monthly_formatted' => (string) $displayMonthlyQuote['amount_formatted'],
                'display_price_yearly_total_minor' => (int) $displayYearlyQuote['amount_minor'],
                'display_price_yearly_total_formatted' => (string) $displayYearlyQuote['amount_formatted'],
                'display_price_yearly_monthly_formatted' => (string) $displayYearlyQuote['equivalent_monthly_formatted'],
                'display_savings_formatted' => $this->localizedPricingService->formatAmount(
                    $savingsMinor,
                    (string) $displayMonthlyQuote['currency'],
                    $locale
                ),
                'display_is_manual' => $manualMethod !== null,
                'monthly_minutes' => (int) $plan->monthly_minutes,
                'monthly_chars' => (int) $plan->monthly_chars,
                'monthly_requests' => (int) $plan->monthly_requests,
                'requests_unlimited' => (int) $plan->monthly_requests <= 0,
                'max_chars_per_request' => (int) $plan->max_chars_per_request,
                'allow_overage' => (bool) $plan->allow_overage,
                'overage_price_per_million_cents' => $plan->overage_price_per_million_cents !== null
                    ? (int) $plan->overage_price_per_million_cents
                    : null,
                'commercial_use_allowed' => (bool) $plan->commercial_use_allowed,
                'processing_priority' => (string) ($plan->processing_priority ?: 'standard'),
                'voice_tier' => $tier,
                'voice_tier_label' => VoiceTier::label($tier),
                'premium_voice_minutes' => $premiumMinutes,
                'recent_voices_days' => $recentDays,
                'dialogue_enabled' => (bool) ($plan->dialogue_enabled ?? false),
                'topup_char_price_cents' => $topupCharCents,
                'topup_minute_price_cents' => $topupMinCents,
                'is_featured' => (bool) ($plan->is_featured ?? false),
                'features' => $features,
                'is_pack' => $plan->isPack(),
                'pack_days' => $plan->isPack() ? $plan->packDays() : null,
                'can_buy_pack' => $plan->isPack() && $viewerCanBuyPack,
                'has_pack' => $plan->isPack() && $viewerHasPack,
                'free_try_chars' => ! $plan->isPack() && (int) $plan->price_cents <= 0 ? $freeTryChars : null,
            ];
        })->values();

        $featuredSlug = (string) ($mapped->firstWhere('is_featured', true)['slug'] ?? '');
        $popularSlug = $featuredSlug !== ''
            ? $featuredSlug
            : (
                $mapped->contains(fn (array $plan) => $plan['slug'] === 'creator')
                    ? 'creator'
                    : (string) ($mapped->firstWhere('price_monthly', '>', 0)['slug'] ?? '')
            );

        return $mapped->map(function (array $plan) use ($popularSlug): array {
            $plan['is_popular'] = $popularSlug !== '' && $plan['slug'] === $popularSlug;

            return $plan;
        })->all();
    }

    private function demoCharAllowance(Request $request): int
    {
        return DemoAllowance::chars();
    }

    private function demoLimitExceededMessage(Request $request, int $maxChars): string
    {
        return __('ui.backend.demo_trial_limit_exceeded', ['max' => $maxChars]);
    }

    /**
     * Quota snapshot for the page load (GET).
     *
     * Source of truth is the authenticated user's own usage history.
     */
    private function demoQuotaForPageLoad(Request $request): array
    {
        return $this->formatDemoQuotaForClient(
            $this->demoQuotaSnapshot($request, false),
            true,
        );
    }

    /**
     * @return array{scope:string,limit_chars:int,used_chars:int,remaining_chars:int,is_exhausted:bool,verification_source:string,requires_client_sync:bool}
     */
    private function demoQuotaForClient(Request $request): array
    {
        return $this->formatDemoQuotaForClient(
            $this->demoQuotaSnapshot($request, false),
            false,
        );
    }

    /**
     * @param  array{scope:string,limit_chars:int,used_chars:int,remaining_chars:int,is_exhausted:bool,verification_source:string,reward_chars_remaining?:int,reward_chars_earned?:int,total_remaining_chars?:int}  $quota
     * @return array{scope:string,limit_chars:int,used_chars:int,remaining_chars:int,is_exhausted:bool,verification_source:string,requires_client_sync:bool,reward_chars_remaining:int,reward_chars_earned:int,total_remaining_chars:int}
     */
    private function formatDemoQuotaForClient(array $quota, bool $requiresClientSync): array
    {
        $remaining = (int) ($quota['remaining_chars'] ?? 0);
        $reward = (int) ($quota['reward_chars_remaining'] ?? 0);

        return [
            'scope' => (string) ($quota['scope'] ?? 'trial'),
            'limit_chars' => (int) ($quota['limit_chars'] ?? 0),
            'used_chars' => (int) ($quota['used_chars'] ?? 0),
            'remaining_chars' => $remaining,
            'is_exhausted' => (bool) ($quota['is_exhausted'] ?? false),
            'verification_source' => (string) ($quota['verification_source'] ?? 'account_only'),
            'requires_client_sync' => $requiresClientSync,
            'reward_chars_remaining' => $reward,
            'reward_chars_earned' => (int) ($quota['reward_chars_earned'] ?? 0),
            'total_remaining_chars' => (int) ($quota['total_remaining_chars'] ?? ($remaining + $reward)),
        ];
    }

    /**
     * @return array{scope:string,limit_chars:int,used_chars:int,remaining_chars:int,is_exhausted:bool,verification_source:string,reward_chars_remaining:int,reward_chars_earned:int,total_remaining_chars:int}
     */
    private function demoQuotaSnapshot(Request $request, bool $preview): array
    {
        $user = $request->user();
        $userId = (int) $user->id;
        $limit = $this->demoCharAllowance($request);
        $used = min($limit, max(0, $this->trialUsedCharsByUser($userId, $request)));
        $remaining = max(0, $limit - $used);

        $ledger = app(DemoRewardLedgerService::class);
        $rewardRemaining = $ledger->balanceFor($user);
        $rewardEarned = $ledger->totalGrantedFor($user);
        $totalRemaining = $remaining + $rewardRemaining;

        return [
            'scope' => 'trial',
            'limit_chars' => $limit,
            'used_chars' => $used,
            'remaining_chars' => $remaining,
            'is_exhausted' => $totalRemaining <= 0,
            'verification_source' => 'user_only',
            'reward_chars_remaining' => $rewardRemaining,
            'reward_chars_earned' => $rewardEarned,
            'total_remaining_chars' => $totalRemaining,
        ];
    }

    private function trialUsedCharsByUser(int $userId, ?Request $request = null): int
    {
        return $this->usedDemoCharsForUserIds($this->demoLinkedUserIds($userId, $request));
    }

    /**
     * @param  list<int>  $userIds
     */
    private function usedDemoCharsForUserIds(array $userIds): int
    {
        return (int) (DemoVoiceGeneration::query()
            ->whereIn('user_id', $userIds)
            ->sum('billable_chars') ?? 0);
    }

    /**
     * The user plus every account that shares an IP, device or fingerprint with them.
     *
     * @return list<int>
     */
    private function demoLinkedUserIds(int $userId, ?Request $request = null): array
    {
        $userIds = $this->ipLinkedUserIds($userId);
        $deviceLinkedIds = $this->deviceLinkedUserIds($userId, $request);
        $fpLinkedIds = $this->fingerprintLinkedUserIds($userId, $request);
        $requestIpLinkedIds = $this->requestIpLinkedUserIds($userId, $request);

        // Request signature is intentionally excluded — it is a browser-config
        // hash (Chrome version + platform), NOT a unique fingerprint. Including
        // it would pool demo chars across unrelated users who simply share the
        // same browser version.

        return array_values(array_unique(array_merge(
            $userIds,
            $deviceLinkedIds,
            $fpLinkedIds,
            $requestIpLinkedIds
        )));
    }

    /**
     * Find all user IDs that share a browser/device cookie identity.
     * This blocks VPN bypass in the same browser even before IPs reconnect.
     *
     * @return list<int>
     */
    private function deviceLinkedUserIds(int $userId, ?Request $request = null): array
    {
        $deviceIds = [];

        if ($request) {
            $requestDeviceId = $this->demoDeviceIdFromRequest($request);
            if ($requestDeviceId !== null) {
                $deviceIds[] = $requestDeviceId;
            }
        }

        $user = User::query()
            ->select(['id', 'registration_device_id', 'last_seen_device_id'])
            ->find($userId);

        if ($user) {
            if (filled($user->registration_device_id)) {
                $deviceIds[] = (string) $user->registration_device_id;
            }
            if (filled($user->last_seen_device_id)) {
                $deviceIds[] = (string) $user->last_seen_device_id;
            }
        }

        $deviceIds = array_values(array_unique(array_filter($deviceIds)));
        if ($deviceIds === []) {
            return [$userId];
        }

        $linkedIds = User::query()
            ->where(fn ($q) => $q->whereIn('registration_device_id', $deviceIds)->orWhereIn('last_seen_device_id', $deviceIds))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (! in_array($userId, $linkedIds, true)) {
            $linkedIds[] = $userId;
        }

        return $linkedIds;
    }

    /**
     * Find all user IDs that share any known IP with the given user.
     * Checks both registration_ip and last_login_ip for maximum coverage.
     *
     * @return list<int>
     */
    private function ipLinkedUserIds(int $userId): array
    {
        // Not cached: a second account made a minute ago must count at once.
        $user = User::query()->find($userId);
        if (! $user) {
            return [$userId];
        }

        $ips = array_values(array_unique(array_filter([
            $user->registration_ip,
            $user->last_login_ip,
        ])));

        if ($ips === []) {
            return [$userId];
        }

        $linkedIds = User::query()
            ->where(function ($q) use ($ips) {
                $q->whereIn('registration_ip', $ips)
                    ->orWhereIn('last_login_ip', $ips);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $linkedIds !== [] ? $linkedIds : [$userId];
    }

    /**
     * Find all user IDs linked via the current request IP.
     * This catches VPN abuse: user registers with IP A, activates VPN,
     * creates a second account with IP B, then turns off VPN — the
     * current request IP (A) links them back together.
     *
     * @return list<int>
     */
    private function requestIpLinkedUserIds(int $userId, ?Request $request = null): array
    {
        $requestIp = $request?->ip();
        if (! $requestIp || $requestIp === '') {
            return [$userId];
        }

        $linkedIds = User::query()
            ->where(function ($q) use ($requestIp) {
                $q->where('registration_ip', $requestIp)
                    ->orWhere('last_login_ip', $requestIp);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (! in_array($userId, $linkedIds, true)) {
            $linkedIds[] = $userId;
        }

        return $linkedIds;
    }

    /**
     * Find all user IDs whose demo generations share the same browser fingerprint.
     * This catches multi-account abuse even through VPN/IP changes.
     *
     * @return list<int>
     */
    private function fingerprintLinkedUserIds(int $userId, ?Request $request = null): array
    {
        $fp = $request ? $this->extractClientFingerprint($request) : null;

        if (! $fp || $fp === '') {
            return [$userId];
        }

        $linkedIds = DemoVoiceGeneration::query()
            ->where('primary_identity_hash', $fp)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (! in_array($userId, $linkedIds, true)) {
            $linkedIds[] = $userId;
        }

        return $linkedIds;
    }

    private function extractClientFingerprint(Request $request): string
    {
        // 1. Canvas/WebGL fingerprint — strongest signal, unique per device hardware.
        $fp = trim((string) $request->header('X-Client-FP', ''));
        if ($fp !== '' && strlen($fp) >= 16 && strlen($fp) <= 256) {
            return 'fp:'.$fp;
        }

        // 2. Device cookie — unique random token per browser instance.
        $deviceId = $this->demoDeviceIdFromRequest($request);
        if ($deviceId !== null) {
            return 'dc:'.$deviceId;
        }

        // 3. IP + User-Agent hash — weak last resort.
        // Request signature (Sec-CH-UA headers) is intentionally skipped:
        // it is a browser-config hash, not a fingerprint — millions of users
        // with the same Chrome version produce the same value.
        $requestIdentity = trim((string) $request->ip()).'|'.trim((string) $request->userAgent());
        if ($requestIdentity !== '|') {
            return 'rq:'.hash('sha256', strtolower($requestIdentity));
        }

        return 'u:'.(int) ($request->user()?->id ?? 0);
    }

    private function demoDeviceIdFromRequest(Request $request): ?string
    {
        $raw = trim((string) $request->cookie(self::DEMO_DEVICE_COOKIE, ''));

        if ($raw !== '' && preg_match('/^[A-Za-z0-9_-]{24,128}$/', $raw) === 1) {
            return $raw;
        }

        return null;
    }

    private function attachDemoDeviceCookie(Request $request, Response $response): Response
    {
        if ($this->demoDeviceIdFromRequest($request) !== null) {
            return $response;
        }

        return $response->cookie(
            cookie(
                self::DEMO_DEVICE_COOKIE,
                Str::random(48),
                60 * 24 * 730,
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'lax'
            )
        );
    }

    private function syncCurrentUserDeviceIdentity(Request $request): void
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return;
        }

        $updates = [];
        $deviceId = $this->demoDeviceIdFromRequest($request);

        if ($deviceId !== null) {
            if ((string) $user->last_seen_device_id !== $deviceId) {
                $updates['last_seen_device_id'] = $deviceId;
            }

            if (! filled($user->registration_device_id)) {
                $updates['registration_device_id'] = $deviceId;
            }
        }

        if ($updates === []) {
            return;
        }

        $user->forceFill($updates)->saveQuietly();
        Cache::forget("demo:ip_linked_users:{$user->id}");
        Cache::forget('admin:suspicious_users_count');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $studioOptions
     * @param  array<string, mixed>  $audio
     * @param  array<string, mixed>  $quota
     */
    private function storeDemoVoiceGeneration(
        Request $request,
        array $validated,
        string $inputText,
        string $speechText,
        string $sourceLanguage,
        ?string $voiceName,
        array $studioOptions,
        array $audio,
        array $quota,
        ?int $originalChars = null,
        ?int $speechChars = null,
        ?int $rewardCharsConsumed = null,
        ?int $totalChars = null,
    ): ?DemoVoiceGeneration {
        try {
            $voiceMeta = VoiceCatalog::voiceMeta($voiceName);
            $voiceLabel = null;

            if (is_array($voiceMeta)) {
                $voiceLabel = trim((string) ($voiceMeta['label'] ?? $voiceMeta['label_ar'] ?? $voiceMeta['name'] ?? ''));
            }

            return DemoVoiceGeneration::query()->create([
                'user_id' => $request->user()?->id,
                'primary_identity_hash' => $this->extractClientFingerprint($request),
                'link_group' => null,
                'input_text' => $inputText,
                'speech_text' => $speechText,
                'source_language' => $sourceLanguage,
                'target_language' => (string) ($validated['target_language'] ?? ''),
                'translate' => (bool) ($validated['translate'] ?? false),
                'voice_key' => isset($validated['voice_key']) ? (string) $validated['voice_key'] : null,
                'voice_name' => $voiceName,
                'voice_label' => $voiceLabel !== '' ? $voiceLabel : $voiceName,
                'voice_provider' => isset($audio['provider']) ? (string) $audio['provider'] : VoiceCatalog::providerForName($voiceName),
                'voice_tier' => isset($validated['voice_tier']) ? VoiceTier::normalize((string) $validated['voice_tier']) : null,
                'audio_path' => isset($audio['audio_path']) ? (string) $audio['audio_path'] : null,
                'duration_seconds' => isset($audio['duration_seconds']) ? (int) $audio['duration_seconds'] : null,
                'billable_chars' => $originalChars ?? UsageEstimator::estimate($inputText)['chars'],
                'dialect' => isset($studioOptions['dialect']) ? (string) $studioOptions['dialect'] : null,
                'performance_style' => isset($studioOptions['performance_style']) ? (string) $studioOptions['performance_style'] : null,
                'style_prompt' => isset($studioOptions['style_prompt']) ? (string) $studioOptions['style_prompt'] : null,
                'emotion' => isset($studioOptions['emotion']) ? (string) $studioOptions['emotion'] : null,
                'speed' => isset($studioOptions['speed']) ? (float) $studioOptions['speed'] : null,
                'pitch' => isset($studioOptions['pitch']) ? (float) $studioOptions['pitch'] : null,
                'tts_model' => isset($audio['model']) ? (string) $audio['model'] : null,
                'tts_latency_ms' => isset($audio['latency_ms']) ? (int) $audio['latency_ms'] : null,
                'tts_fallback_used' => (bool) ($audio['fallback_used'] ?? false),
                'tts_cost_estimate_usd' => isset($audio['cost_estimate_usd']) ? (float) $audio['cost_estimate_usd'] : null,
                'quota_limit_chars' => isset($quota['limit_chars']) ? (int) $quota['limit_chars'] : null,
                'quota_remaining_chars' => isset($quota['remaining_chars']) ? (int) $quota['remaining_chars'] : null,
                'metadata' => [
                    'voice_meta' => is_array($voiceMeta) ? [
                        'gender' => $voiceMeta['gender'] ?? null,
                        'language' => $voiceMeta['language'] ?? null,
                        'provider' => $voiceMeta['provider'] ?? null,
                    ] : null,
                    'quota' => $quota,
                    'speech_chars' => $speechChars ?? UsageEstimator::estimate($speechText)['chars'],
                    'routing_mode' => $audio['routing_mode'] ?? null,
                    'plan_slug' => $audio['plan_slug'] ?? 'free',
                    'reward_chars_consumed' => $rewardCharsConsumed !== null ? (int) $rewardCharsConsumed : 0,
                    'total_chars' => $totalChars !== null ? (int) $totalChars : ($originalChars ?? UsageEstimator::estimate($inputText)['chars']),
                    'dialogue_speakers' => ($validated['is_dialogue'] ?? false) ? ($validated['dialogue_speakers'] ?? []) : null,
                    'audio_effect' => $audio['audio_effect'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to store demo voice generation.', [
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array{scope:string,limit_chars:int,used_chars:int,remaining_chars:int,is_exhausted:bool}  $quota
     */
    private function syncStoredDemoVoiceGenerationQuota(?DemoVoiceGeneration $generation, array $quota): void
    {
        if (! $generation) {
            return;
        }

        try {
            $metadata = is_array($generation->metadata) ? $generation->metadata : [];
            $metadata['quota'] = $quota;

            $generation->fill([
                'quota_limit_chars' => (int) ($quota['limit_chars'] ?? 0),
                'quota_remaining_chars' => (int) ($quota['remaining_chars'] ?? 0),
                'metadata' => $metadata,
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('Failed to sync demo voice generation quota snapshot.', [
                'generation_id' => $generation->id,
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
            ]);
        }
    }

    private function storeDemoAudioCopy(string $sourcePath): string
    {
        $sourcePath = ltrim(trim($sourcePath), '/');
        if ($sourcePath === '') {
            return $sourcePath;
        }

        if (str_starts_with($sourcePath, 'audio/demo/')) {
            return $sourcePath;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($sourcePath)) {
            return $sourcePath;
        }

        $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = 'mp3';
        }

        $targetPath = 'audio/demo/'.now()->format('Ymd_His').'_'.Str::random(12).'.'.$extension;

        if (! $disk->exists('audio/demo')) {
            $disk->makeDirectory('audio/demo');
        }

        $disk->copy($sourcePath, $targetPath);

        return $targetPath;
    }

    /**
     * A sample as the homepage shows it: flag, dialect or accent,
     * speaker, and for dialogues the turns with each speaker's name.
     *
     * @return array<string, mixed>
     */
    private function showcaseCard(VoiceSample $sample, bool $isArabicLocale): array
    {
        $metadata = is_array($sample->metadata) ? $sample->metadata : [];
        $language = $sample->language === 'ar' ? 'ar' : 'en';
        $speakers = [];
        foreach ((array) ($metadata['speakers'] ?? []) as $speaker) {
            $name = (string) ($speaker['name'] ?? '');
            $speakers[$name] = [
                'name' => $isArabicLocale ? (string) ($speaker['name_ar'] ?? $name) : $name,
                'avatar' => SpeakerAvatar::url($name, $language),
                'gender' => (string) ($speaker['gender'] ?? ''),
                'dialect' => VoiceFlag::label($speaker['dialect'] ?? $sample->dialect, $isArabicLocale),
                'flag' => VoiceFlag::url($speaker['dialect'] ?? $sample->dialect),
            ];
        }
        $speakerName = trim((string) ($isArabicLocale
            ? ($metadata['speaker_name_ar'] ?? $metadata['speaker_name'] ?? '')
            : ($metadata['speaker_name'] ?? '')));
        $avatarName = (string) (array_key_first($speakers) ?? '');
        if ($speakerName === '') {
            $voiceMeta = VoiceCatalog::voiceMeta($sample->voice_name);
            $speakerName = trim((string) ($isArabicLocale ? ($voiceMeta['label_ar'] ?? $voiceMeta['label'] ?? '') : ($voiceMeta['label'] ?? '')));
            $avatarName = (string) ($voiceMeta['label'] ?? '');
        }

        $turns = [];
        foreach ((array) ($metadata['turns'] ?? []) as $turn) {
            $key = (string) ($turn['speaker'] ?? '');
            $turns[] = [
                'speaker' => $speakers[$key]['name'] ?? $key,
                'index' => max(0, (int) array_search($key, array_keys($speakers), true)),
                'text' => (string) ($turn['text'] ?? ''),
                // When the line is spoken, in seconds, when known.
                'start' => isset($turn['start']) ? (float) $turn['start'] : null,
                'end' => isset($turn['end']) ? (float) $turn['end'] : null,
            ];
        }

        $text = trim((string) ($isArabicLocale ? ($sample->sample_text_ar ?: $sample->sample_text) : $sample->sample_text));

        return [
            'id' => (int) $sample->id,
            'kind' => $sample->category === 'dialogue' ? 'dialogue' : 'single',
            'emotion' => strtolower(trim((string) $sample->emotion)),
            'title' => trim((string) ($isArabicLocale ? ($sample->title_ar ?: $sample->title) : $sample->title)),
            'language' => $language,
            'dialect' => (string) $sample->dialect,
            'region' => VoiceFlag::region($sample->dialect),
            'dialect_label' => VoiceFlag::label($sample->dialect, $isArabicLocale),
            'flag' => VoiceFlag::url($sample->dialect, $sample->country),
            'speaker' => $speakerName,
            'avatar' => SpeakerAvatar::url($avatarName, $language),
            'gender' => (string) $sample->gender,
            'speakers' => array_values($speakers),
            'turns' => $turns,
            // Vocal tags such as <laugh> are performed, not shown.
            'text' => trim((string) preg_replace('/\s*<[^<>\n]{1,24}>\s*/u', ' ', $text)),
            'audio_url' => $this->resolveSampleAudioUrl($sample->audio_path, (string) $sample->language),
            'duration' => (int) ($sample->duration_seconds ?? 0),
        ];
    }

    private function sampleLanguageForLocale(?string $locale): string
    {
        $locale = strtolower(trim((string) $locale));

        return str_starts_with($locale, 'en') ? 'en' : 'ar';
    }

    private function landingVoiceSampleLimit(): int
    {
        $raw = (int) SiteSetting::get('landing_voice_samples_limit', '12');

        return max(1, min(60, $raw));
    }

    /**
     * @param  array{dialect:mixed,performance_style:mixed,style_prompt:mixed,emotion:mixed,speed:mixed,pitch:mixed}  $options
     * @return array{dialect:?string,performance_style:string,style_prompt:?string,emotion:string,speed:float,pitch:float}
     */
    private function sanitizeStudioOptionsForTier(string $tier, array $options, ?Plan $plan = null): array
    {
        $normalizedTier = VoiceTier::normalize($tier);

        // Standard tier: no performance controls - use defaults only.
        if ($normalizedTier === VoiceTier::STANDARD) {
            return [
                'dialect' => null,
                'performance_style' => 'neutral',
                'style_prompt' => null,
                'emotion' => 'neutral',
                'speed' => 1.0,
                'pitch' => 0.0,
            ];
        }

        $performanceStyle = isset($options['performance_style']) && is_string($options['performance_style'])
            ? trim($options['performance_style'])
            : '';
        $emotion = isset($options['emotion']) && is_string($options['emotion'])
            ? trim($options['emotion'])
            : '';

        // Backward compatibility for old clients that used emotion for style.
        if (
            $performanceStyle === ''
            && in_array($emotion, TtsStudioConfig::performanceStyleValues(), true)
            && ! in_array($emotion, TtsStudioConfig::emotionValues(), true)
        ) {
            $performanceStyle = $emotion;
            $emotion = 'neutral';
        }

        // Style prompt is only allowed if the plan permits it (custom_prompt_enabled)
        $stylePrompt = null;
        if ($plan === null || (bool) ($plan->custom_prompt_enabled ?? false)) {
            $stylePrompt = isset($options['style_prompt']) && is_string($options['style_prompt'])
                ? trim($options['style_prompt']) ?: null
                : null;
        }

        // Premium tier: full performance controls.
        return [
            'dialect' => isset($options['dialect']) && is_string($options['dialect']) ? trim($options['dialect']) ?: null : null,
            'performance_style' => $performanceStyle !== '' ? $performanceStyle : 'neutral',
            'style_prompt' => $stylePrompt,
            'emotion' => $emotion !== '' ? $emotion : 'neutral',
            'speed' => isset($options['speed']) ? (float) $options['speed'] : 1.0,
            'pitch' => isset($options['pitch']) ? (float) $options['pitch'] : 0.0,
        ];
    }

    /**
     * @param  array{performance_style:mixed,emotion:mixed}  $input
     */
    private function ensureRequiredStudioSelections(string $tier, array $input): void
    {
        if (VoiceTier::normalize($tier) !== VoiceTier::HD) {
            return;
        }

        $errors = [];

        if (! is_string($input['performance_style'] ?? null) || trim((string) $input['performance_style']) === '') {
            $errors['performance_style'] = [__('ui.backend.performance_style_required')];
        }

        if (! is_string($input['emotion'] ?? null) || trim((string) $input['emotion']) === '') {
            $errors['emotion'] = [__('ui.backend.emotion_required')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function seoLandingContent(string $page, string $locale): array
    {
        $locale = $locale === 'ar' ? 'ar' : 'en';
        $defaults = $this->seoLandingDefaults()[$page][$locale] ?? [];
        $raw = trim((string) SiteSetting::get("seo_landing_{$page}_content_{$locale}", ''));

        if ($raw === '') {
            return $defaults;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $defaults;
        }

        return array_replace_recursive($defaults, $decoded);
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function seoLandingDefaults(): array
    {
        return SeoHelper::landingPageContentDefaults();
    }

    private function resolveSampleAudioUrl(?string $audioPath, string $language): ?string
    {
        $normalizedPath = ltrim(trim((string) $audioPath), '/');
        if (
            $normalizedPath !== ''
            && (
                Storage::disk('public')->exists($normalizedPath)
                || is_file(public_path($normalizedPath))
            )
        ) {
            return AudioUrl::fromPath($normalizedPath);
        }

        return strtolower(trim($language)) === 'ar' ? '/audio/ar.mp3' : '/audio/en.mp3';
    }
}
