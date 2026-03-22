<?php

namespace App\Http\Controllers;

use App\Models\DemoIdentityLink;
use App\Models\DemoVoiceGeneration;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\VoiceSample;
use App\Rules\DoesNotContainProfanity;
use App\Services\DemoFraudService;
use App\Services\LocalizedPricingService;
use App\Services\PlanAccessService;
use App\Services\Tts\TtsRoutingService;
use App\Services\TranslationService;
use App\Services\TtsService;
use App\Support\AudioUrl;
use App\Support\SeoHelper;
use App\Support\TtsStudioConfig;
use App\Support\UsageEstimator;
use App\Support\VoiceCatalog;
use App\Support\VoiceTier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HomeController extends Controller
{
    public function __construct(
        private readonly PlanAccessService $planAccessService,
        private readonly LocalizedPricingService $localizedPricingService,
        private readonly TtsRoutingService $ttsRoutingService,
        private readonly DemoFraudService $fraudService,
    ) {
    }

    public function index()
    {
        $countryContext = $this->localizedPricingService->resolveCountryContext(request());
        $pricingPlans = $this->pricingPlans();
        $highlightPlans = array_slice($pricingPlans, 0, 3);
        $sampleLanguage = $this->sampleLanguageForLocale(app()->getLocale());
        $isArabicLocale = $sampleLanguage === 'ar';
        $homepageSampleLimit = $this->landingVoiceSampleLimit();

        $heroVoiceSample = Cache::remember("landing_voice_sample_hero:{$sampleLanguage}", 300, function () use ($sampleLanguage, $isArabicLocale) {
            $sample = VoiceSample::query()
                ->active()
                ->where('category', 'hero')
                ->where('language', $sampleLanguage)
                ->showInHero()
                ->orderByDesc('is_featured')
                ->ordered()
                ->first();

            return $sample ? $this->mapVoiceSample($sample, $isArabicLocale) : null;
        });

        $voiceSamples = array_slice(
            Cache::remember("landing_voice_samples_showcase:{$sampleLanguage}", 300, function () use ($sampleLanguage, $isArabicLocale) {
                return VoiceSample::query()
                    ->active()
                    ->where('category', 'hero')
                    ->where('language', $sampleLanguage)
                    ->showInShowcase()
                    ->orderByDesc('is_featured')
                    ->ordered()
                    ->get()
                    ->map(fn (VoiceSample $s): array => $this->mapVoiceSample($s, $isArabicLocale))
                    ->toArray();
            }),
            0,
            $homepageSampleLimit
        );

        return view('landing', [
            'highlightPlans' => $highlightPlans,
            'heroVoiceSample' => $heroVoiceSample,
            'voiceSamples' => $voiceSamples,
            'countryContext' => $countryContext,
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
        ]);
    }

    public function seoLandingPage(Request $request, string $page)
    {
        if (! in_array($page, ['ai_text_to_speech', 'ai_voice_generator', 'text_to_speech_arabic'], true)) {
            abort(404);
        }

        $locale = str_starts_with(strtolower((string) app()->getLocale()), 'ar') ? 'ar' : 'en';
        if ($page === 'text_to_speech_arabic' && $locale === 'ar') {
            return redirect('/en/text-to-speech-arabic', 301);
        }

        $content = $this->seoLandingContent($page, $locale);

        return view('seo.landing-page', [
            'page' => $page,
            'pageContent' => $content,
            'faqItems' => $content['faqs'],
            'ctaPrimaryUrl' => $request->user() ? route('dashboard') : route('register'),
        ]);
    }

    public function demoPage(Request $request)
    {
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

        $upgradePlanSlug = (string) (Plan::query()
            ->where('is_active', true)
            ->where('price_cents', '>', 0)
            ->orderBy('price_cents')
            ->value('slug') ?? 'starter');

        return view('demo', [
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
    }

    public function demoQuota(Request $request)
    {
        return response()->json([
            'ok' => true,
            'quota' => $this->demoQuotaForClient($request),
        ]);
    }

    public function demo(Request $request)
    {
        try {
            $maxChars = $this->demoCharAllowance($request);
            $demoPlan = $this->planAccessService->freePlan();
            $defaultTier = $this->planAccessService->resolveTierForPlan($demoPlan);
            $requestedTier = VoiceTier::normalize((string) ($request->input('voice_tier') ?? $defaultTier));

            $this->ensureRequiredStudioSelections($requestedTier, [
                'performance_style' => $request->input('performance_style'),
                'emotion' => $request->input('emotion'),
            ]);
            $performanceStyleValues = TtsStudioConfig::performanceStyleValues();
            $emotionValues = TtsStudioConfig::emotionValues();

            $validated = $request->validate([
                'text' => ['required', 'string', 'max:50000', new DoesNotContainProfanity(app(\App\Services\ProfanityFilter::class))],
                'target_language' => ['required', 'in:ar,en'],
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
            ], [
                'performance_style.in' => __('ui.backend.performance_style_invalid'),
                'emotion.in' => __('ui.backend.emotion_invalid'),
            ]);
            $tier = VoiceTier::normalize((string) ($validated['voice_tier'] ?? $defaultTier));
            $voiceName = $this->normalizeVoiceSelection(
                $validated['target_language'],
                $validated['voice_key'] ?? null,
                $tier,
            );

            $text = trim($validated['text']);
            $sourceLanguage = preg_match('/[\x{0600}-\x{06FF}]/u', $text) ? 'ar' : 'en';
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
        $maxChars = $this->demoCharAllowance($request);
        $demoPlan = $this->planAccessService->freePlan();
        $defaultTier = $this->planAccessService->resolveTierForPlan($demoPlan);
        $requestedTier = VoiceTier::normalize((string) ($request->input('voice_tier') ?? $defaultTier));

        $this->ensureRequiredStudioSelections($requestedTier, [
            'performance_style' => $request->input('performance_style'),
            'emotion' => $request->input('emotion'),
        ]);
        $performanceStyleValues = TtsStudioConfig::performanceStyleValues();
        $emotionValues = TtsStudioConfig::emotionValues();

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:50000', new DoesNotContainProfanity(app(\App\Services\ProfanityFilter::class))],
            'target_language' => ['required', 'in:ar,en'],
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
        ], [
            'performance_style.in' => __('ui.backend.performance_style_invalid'),
            'emotion.in' => __('ui.backend.emotion_invalid'),
        ]);
        $tier = VoiceTier::normalize((string) ($validated['voice_tier'] ?? $defaultTier));
        $demoRoutingPlan = $this->demoRoutingPlan($demoPlan, $tier);
        $voiceName = $this->normalizeVoiceSelection(
            $validated['target_language'],
            $validated['voice_key'] ?? null,
            $tier,
        );

        $text = trim($validated['text']);
        $sourceLanguage = preg_match('/[\x{0600}-\x{06FF}]/u', $text) ? 'ar' : 'en';
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
                'dialect' => $validated['dialect'] ?? null,
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

            $audio = $ttsService->synthesizeAdvanced(
                $textForSpeech,
                $validated['target_language'],
                $voiceOptions,
                $demoRoutingPlan,
                null,
            );
            $audio['audio_path'] = $this->storeDemoAudioCopy((string) ($audio['audio_path'] ?? ''));

            // Use original text chars for demo quota consumption — this is what the user
            // typed and what the frontend counted. Translated text may expand/contract,
            // which would cause a mismatch between the UI counter and actual billing.
            // Speech-text metrics are stored separately in metadata for analytics.
            $speechEstimate = UsageEstimator::estimate($textForSpeech);
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
                $estimate['chars'],
                $speechEstimate['chars'],
            );

            // Sync device-level usage in DemoGuestUsage so quota is shared
            // across accounts on the same device (prevents multi-account abuse).
            // Use original text chars to match what enforceDemoPolicy() checked.
            $this->syncDemoGuestUsage($request, $estimate['chars']);

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

            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.demo_voice_generation_failed'),
                'quota' => $quota ?: $this->demoQuotaForClient($request),
            ], 422);
        }
    }

    private function enforceDemoPolicy(Request $request, int $chars, bool $preview, bool $commit = true): array
    {
        $user = $request->user();
        $userId = (int) $user->id;
        $scope = $preview ? 'preview' : 'check';
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

        // Lifetime trial cap - combines user's own usage + usage from
        // other accounts that share the same device fingerprint.
        $lifetimeCap = $this->demoCharAllowance($request);
        $usedChars = $this->trialUsedCharsWithFingerprint($request, $userId);

        if (($usedChars + $chars) > $lifetimeCap) {
            throw ValidationException::withMessages([
                'text' => __('ui.backend.demo_trial_exhausted'),
            ]);
        }

        // Global preview capacity
        if ($preview) {
            $globalRequestCap = max(1, (int) config('listen.demo.global_preview_daily_requests', 3000));
            $globalRequestKey = 'demo:req:'.now()->format('Ymd').':preview:global';
            $globalUsedRequests = (int) Cache::get($globalRequestKey, 0);
            if (($globalUsedRequests + 1) > $globalRequestCap) {
                throw ValidationException::withMessages([
                    'text' => __('ui.backend.demo_preview_capacity_reached'),
                ]);
            }

            $globalCharCap = max(1, (int) config('listen.demo.global_preview_daily_chars', 500000));
            $globalCharKey = 'demo:chars:'.now()->format('Ymd').':preview:global';
            $globalUsedChars = (int) Cache::get($globalCharKey, 0);
            if (($globalUsedChars + $chars) > $globalCharCap) {
                throw ValidationException::withMessages([
                    'text' => __('ui.backend.demo_preview_capacity_reached'),
                ]);
            }

            if ($commit) {
                Cache::put($globalRequestKey, $globalUsedRequests + 1, now()->endOfDay());
                Cache::put($globalCharKey, $globalUsedChars + $chars, now()->endOfDay());
            }
        }

        return $this->demoQuotaForClient($request);
    }

    private function rollbackDemoPolicy(Request $request, int $chars, bool $preview): array
    {
        if ($preview) {
            $globalRequestKey = 'demo:req:'.now()->format('Ymd').':preview:global';
            $globalCharKey = 'demo:chars:'.now()->format('Ymd').':preview:global';
            $this->decrementCacheCounter($globalRequestKey, 1, now()->endOfDay());
            $this->decrementCacheCounter($globalCharKey, $chars, now()->endOfDay());
        }

        return $this->demoQuotaForClient($request);
    }

    private function decrementCacheCounter(string $key, int $amount, \DateTimeInterface $ttl): void
    {
        $current = (int) Cache::get($key, 0);
        $next = max(0, $current - max(0, $amount));
        Cache::put($key, $next, $ttl);
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

        $mapped = $plans->map(function (Plan $plan) use ($locale, $countryContext): array {
            $monthlyCents = max(0, (int) $plan->price_cents);
            $yearlyCents = (int) round($monthlyCents * 12 * 0.8);
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

            // Use admin-configured features if available, otherwise auto-generate
            $customFeatures = $plan->getLocalizedFeatures($locale);
            $features = $customFeatures ?? self::resolveStructuredFeatures($plan, $tier, $locale);

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

    /**
     * Build structured features array for the pricing card.
     * Auto-builds from plan attributes so labels are always locale-aware.
     *
     * @return array<int, array{label: string, value: string, icon: string}>
     */
    private static function resolveStructuredFeatures(Plan $plan, string $tier, string $locale = 'en'): array
    {
        $priority = (string) ($plan->processing_priority ?? 'standard');
        $priorityLabel = match ($priority) {
            'standard_plus' => __('ui.pricing.standard_plus'),
            'high' => __('ui.pricing.high'),
            'priority' => __('ui.pricing.priority'),
            'highest' => __('ui.pricing.highest'),
            default => __('ui.pricing.standard'),
        };

        $recentDays = (int) ($plan->recent_voices_days ?? 7);
        $isFree = (int) $plan->price_cents <= 0;

        $emotionValue = $isFree
            ? __('ui.pricing.basic_controls')
            : __('ui.pricing.full_controls');

        $recentValue = $recentDays <= 0
            ? __('ui.pricing.unlimited')
            : $recentDays . ' ' . __('ui.pricing.days');

        $features = [
            ['label' => __('ui.pricing.feature_label_requests'), 'value' => (int) $plan->monthly_requests <= 0 ? __('ui.pricing.unlimited') : number_format((int) $plan->monthly_requests), 'icon' => 'activity'],
            ['label' => __('ui.pricing.feature_label_max_input'), 'value' => number_format((int) $plan->max_chars_per_request) . ' ' . __('ui.pricing.chars'), 'icon' => 'type'],
            ['label' => __('ui.pricing.feature_label_voice_quality'), 'value' => VoiceTier::label($tier), 'icon' => 'mic'],
            ['label' => __('ui.pricing.feature_label_emotion_controls'), 'value' => $emotionValue, 'icon' => 'heart'],
            ['label' => __('ui.pricing.feature_label_priority_queue'), 'value' => $priorityLabel, 'icon' => 'bolt'],
            ['label' => __('ui.pricing.feature_label_commercial_use'), 'value' => $plan->commercial_use_allowed ? __('ui.pricing.allowed') : __('ui.pricing.personal_only'), 'icon' => 'lock'],
            ['label' => __('ui.pricing.feature_label_recent_voices'), 'value' => $recentValue, 'icon' => 'clock'],
        ];

        $charTopupCents = $plan->topup_char_price_cents !== null
            ? (int) $plan->topup_char_price_cents
            : (
                $plan->allow_overage && $plan->overage_price_per_million_cents !== null
                    ? (int) $plan->overage_price_per_million_cents
                    : 0
            );
        if ($charTopupCents > 0) {
            $features[] = [
                'label' => __('ui.pricing.topup_chars'),
                'value' => '$'.number_format($charTopupCents / 100, 0).__('ui.pricing.topup_per_million'),
                'icon' => 'download',
            ];
        }

        if ((bool) ($plan->dialogue_enabled ?? false)) {
            $features[] = ['label' => __('ui.pricing.feature_label_dialogue_mode'), 'value' => __('ui.pricing.included_mark'), 'icon' => 'users'];
        }

        // Custom Prompt is enabled per-plan
        if ((bool) ($plan->custom_prompt_enabled ?? false)) {
            $features[] = ['label' => __('ui.pricing.feature_label_custom_prompt'), 'value' => __('ui.pricing.included_mark'), 'icon' => 'star'];
        }

        return $features;
    }

    private function demoCharAllowance(Request $request): int
    {
        return max(1, (int) config('listen.demo.guest_char_allowance', 300));
    }

    private function demoLimitExceededMessage(Request $request, int $maxChars): string
    {
        return __('ui.backend.demo_trial_limit_exceeded', ['max' => $maxChars]);
    }

    /**
     * @return array{0:array<int,string>,1:string,2:array,3:array}
     */
    private function resolveDemoIdentities(Request $request): array
    {
        if ($request->user()) {
            $identity = 'u:'.$request->user()->id;
            return [[$identity], $identity, [], []];
        }

        // Use enhanced fraud detection service
        $extracted = $this->fraudService->extractIdentities($request);

        // For GET requests (page loads), don't link or expand identities.
        // This prevents false positives where IP-sharing causes wrong quota display.
        // Only POST requests (actual voice generation) should trigger linking.
        if ($request->isMethod('GET')) {
            return [
                $extracted['identities'],
                $extracted['primary'],
                $extracted['identity_types'],
                [], // No fraud signals on GET
            ];
        }

        // For POST requests: Get all linked identities (catches users trying to reset via VPN/incognito)
        $allIdentities = $this->fraudService->getLinkedIdentities($extracted['identities']);

        // Link identities that appear together (only on POST)
        if (count($extracted['identities']) >= 2) {
            try {
                $this->fraudService->linkIdentities($extracted['identities'], $extracted['identity_types']);
            } catch (\Throwable $e) {
                Log::warning('Failed to link demo identities', ['error' => $e->getMessage()]);
            }
        }

        // Record fraud signals if any (only on POST)
        if (!empty($extracted['fraud_signals'])) {
            try {
                $this->fraudService->recordFraudSignals($allIdentities, $extracted['fraud_signals']);
            } catch (\Throwable $e) {
                Log::warning('Failed to record fraud signals', ['error' => $e->getMessage()]);
            }
        }

        return [
            $allIdentities,
            $extracted['primary'],
            $extracted['identity_types'],
            $extracted['fraud_signals'],
        ];
    }

    /**
     * Quota snapshot for the page load (GET).
     *
     * Uses ONLY the authenticated user's own DemoVoiceGeneration records.
     * This avoids the fingerprint asymmetry problem: on GET requests the
     * client-side fingerprint may not be available yet (first visit, mobile
     * cookie issues), so relying on device-level DemoGuestUsage would show
     * inaccurate numbers. The user's own DB records are always reliable.
     *
     * Cross-device fraud detection still runs at generation time via
     * enforceDemoPolicy() + trialUsedCharsWithFingerprint(), so abuse is
     * still caught — it just won't confuse the display on page load.
     */
    private function demoQuotaForPageLoad(Request $request): array
    {
        $quota = $this->demoQuotaSnapshot($request, false);
        $requiresClientSync = ! $this->requestHasStrongDemoFingerprint($request)
            && (int) ($quota['remaining_chars'] ?? 0) > 0;

        return $this->formatDemoQuotaForClient($quota, $requiresClientSync);
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
     * @param  array{scope:string,limit_chars:int,used_chars:int,remaining_chars:int,is_exhausted:bool,verification_source:string}  $quota
     * @return array{scope:string,limit_chars:int,used_chars:int,remaining_chars:int,is_exhausted:bool,verification_source:string,requires_client_sync:bool}
     */
    private function formatDemoQuotaForClient(array $quota, bool $requiresClientSync): array
    {
        return [
            'scope' => (string) ($quota['scope'] ?? 'trial'),
            'limit_chars' => (int) ($quota['limit_chars'] ?? 0),
            'used_chars' => (int) ($quota['used_chars'] ?? 0),
            'remaining_chars' => (int) ($quota['remaining_chars'] ?? 0),
            'is_exhausted' => (bool) ($quota['is_exhausted'] ?? false),
            'verification_source' => (string) ($quota['verification_source'] ?? 'account_only'),
            'requires_client_sync' => $requiresClientSync,
        ];
    }

    /**
     * Quota snapshot for POST responses (after generation or enforcement check).
     *
     * Uses trialUsedCharsWithFingerprint() which includes cross-device usage,
     * so the response reflects the full picture including fraud detection.
     *
     * @return array{scope:string,limit_chars:int,used_chars:int,remaining_chars:int,is_exhausted:bool,verification_source:string}
     */
    private function demoQuotaSnapshot(Request $request, bool $preview): array
    {
        $userId = (int) $request->user()->id;
        $limit = $this->demoCharAllowance($request);
        $used = min($limit, max(0, $this->trialUsedCharsWithFingerprint($request, $userId)));
        $remaining = max(0, $limit - $used);
        $hasStrongFingerprint = $this->requestHasStrongDemoFingerprint($request);

        return [
            'scope' => 'trial',
            'limit_chars' => $limit,
            'used_chars' => $used,
            'remaining_chars' => $remaining,
            'is_exhausted' => $remaining <= 0,
            'verification_source' => $hasStrongFingerprint ? 'device_verified' : 'account_only',
        ];
    }

    /**
     * Get total trial chars used, considering both the user's own generations
     * and the device-level usage tracked in DemoGuestUsage.
     *
     * DemoGuestUsage tracks per-device usage across all accounts, so if
     * email A uses 300 chars on device X, email B on device X sees 0 remaining.
     * This runs on ALL requests (GET and POST) so page-load quota always
     * matches what the generation endpoint enforces.
     */
    private function trialUsedCharsWithFingerprint(Request $request, int $userId): int
    {
        $ownUsed = (int) (DemoVoiceGeneration::query()
            ->where('user_id', $userId)
            ->sum('billable_chars') ?? 0);

        // Check device-level usage via DemoGuestUsage (shared across accounts on same device).
        // On GET, fingerprints come from the cookie; on POST, from headers/body.
        $extracted = $this->fraudService->extractIdentities($request);
        $identityTypes = (array) ($extracted['identity_types'] ?? []);
        $strongFingerprints = $this->strongDemoFingerprintIdentities($identityTypes);

        if (count($strongFingerprints) >= 2) {
            $deviceUsed = $this->fraudService->getConfirmedLinkedUsedChars($strongFingerprints, 2);

            return max($ownUsed, $deviceUsed);
        }

        return $ownUsed;
    }

    /**
     * @param  array<string, string>  $identityTypes
     * @return array<int, string>
     */
    private function strongDemoFingerprintIdentities(array $identityTypes): array
    {
        $strongFingerprints = [];

        foreach (['canvas', 'webgl', 'audio'] as $type) {
            $identity = trim((string) ($identityTypes[$type] ?? ''));
            if ($identity !== '') {
                $strongFingerprints[] = $identity;
            }
        }

        return array_values(array_unique($strongFingerprints));
    }

    private function requestHasStrongDemoFingerprint(Request $request): bool
    {
        $extracted = $this->fraudService->extractIdentities($request);
        $identityTypes = (array) ($extracted['identity_types'] ?? []);

        return count($this->strongDemoFingerprintIdentities($identityTypes)) >= 2;
    }

    /**
     * Update DemoGuestUsage after a successful generation so device-level
     * quota is shared across accounts. Also links identities when 2+
     * strong fingerprints are present.
     */
    private function syncDemoGuestUsage(Request $request, int $chars): void
    {
        try {
            $extracted = $this->fraudService->extractIdentities($request);
            $identities = array_values(array_filter(
                array_map(static fn ($v) => trim((string) $v), (array) ($extracted['identities'] ?? [])),
                static fn (string $v) => $v !== ''
            ));

            if ($identities === []) {
                return;
            }

            // Link identities that appear together on this device
            $identityTypes = (array) ($extracted['identity_types'] ?? []);
            if (count($identities) >= 2) {
                $this->fraudService->linkIdentities($identities, $identityTypes);
            }

            // Record fraud signals if any
            $fraudSignals = (array) ($extracted['fraud_signals'] ?? []);
            if ($fraudSignals !== []) {
                $allIdentities = $this->fraudService->getLinkedIdentities($identities);
                $this->fraudService->recordFraudSignals($allIdentities, $fraudSignals);
            }

            // Reserve chars in device-level tracker
            $limit = $this->demoCharAllowance($request);
            $this->fraudService->reserveChars($identities, $chars, $limit);
        } catch (\Throwable $e) {
            Log::warning('Failed to sync demo guest usage.', [
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
            ]);
        }
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
    ): ?DemoVoiceGeneration {
        try {
            $identityContext = $this->demoTrackingContext($request);
            $voiceMeta = VoiceCatalog::voiceMeta($voiceName);
            $voiceLabel = null;

            if (is_array($voiceMeta)) {
                $voiceLabel = trim((string) ($voiceMeta['label'] ?? $voiceMeta['label_ar'] ?? $voiceMeta['name'] ?? ''));
            }

            return DemoVoiceGeneration::query()->create([
                'user_id' => $request->user()?->id,
                'primary_identity_hash' => $identityContext['primary_identity_hash'],
                'link_group' => $identityContext['link_group'],
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
                    'identities' => $identityContext['identities'],
                    'identity_types' => $identityContext['identity_types'],
                    'fraud_signal_types' => $identityContext['fraud_signal_types'],
                    'voice_meta' => is_array($voiceMeta) ? [
                        'gender' => $voiceMeta['gender'] ?? null,
                        'language' => $voiceMeta['language'] ?? null,
                        'provider' => $voiceMeta['provider'] ?? null,
                    ] : null,
                    'quota' => $quota,
                    'speech_chars' => $speechChars ?? UsageEstimator::estimate($speechText)['chars'],
                    'routing_mode' => $audio['routing_mode'] ?? null,
                    'plan_slug' => $audio['plan_slug'] ?? 'free',
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

    /**
     * @return array{
     *   primary_identity_hash:string,
     *   link_group:string|null,
     *   identities:array<int,string>,
     *   identity_types:array<string,string>,
     *   fraud_signal_types:array<int,string>
     * }
     */
    private function demoTrackingContext(Request $request): array
    {
        $extracted = $this->fraudService->extractIdentities($request);
        $identities = array_values(array_filter(
            array_map(static fn ($value) => trim((string) $value), (array) ($extracted['identities'] ?? [])),
            static fn (string $value) => $value !== ''
        ));

        $linkGroup = null;
        if ($identities !== []) {
            $linkGroup = DemoIdentityLink::query()
                ->whereIn('identity_hash', $identities)
                ->value('link_group');
        }

        $fraudSignalTypes = [];
        foreach ((array) ($extracted['fraud_signals'] ?? []) as $signal) {
            if (is_array($signal) && isset($signal['type']) && is_string($signal['type'])) {
                $fraudSignalTypes[] = $signal['type'];
            }
        }

        // For authenticated users, use user-based identity as primary so that
        // cross-account fingerprint matching does not falsely link records
        // from different users who happen to share device hardware (common on
        // mobile where many phones produce identical canvas/WebGL hashes).
        $primary = trim((string) ($extracted['primary'] ?? 'unknown')) ?: 'unknown';
        $user = $request->user();
        if ($user) {
            $primary = 'u:' . $user->id;
        }

        return [
            'primary_identity_hash' => $primary,
            'link_group' => $linkGroup ? (string) $linkGroup : null,
            'identities' => $identities,
            'identity_types' => array_map(
                static fn ($value) => trim((string) $value),
                array_filter((array) ($extracted['identity_types'] ?? []), static fn ($value) => trim((string) $value) !== '')
            ),
            'fraud_signal_types' => array_values(array_unique($fraudSignalTypes)),
        ];
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
     * @return array{
     *   title:string,
     *   text:string,
     *   audio_url:?string,
     *   language:string,
     *   dialect:?string,
     *   emotion:?string,
     *   gender:?string,
     *   voice_name:?string,
     *   voice_label:string,
     *   is_featured:bool
     * }
     */
    private function mapVoiceSample(VoiceSample $sample, bool $isArabicLocale): array
    {
        $voiceMeta = VoiceCatalog::voiceMeta($sample->voice_name);
        $title = $this->stripDialectCodes($this->humanReadableSampleTitle($sample, $isArabicLocale));
        $description = $isArabicLocale
            ? ($sample->description_ar ?: $sample->description ?: '')
            : ($sample->description ?: '');
        $text = trim((string) ($isArabicLocale ? ($sample->sample_text_ar ?: $sample->sample_text) : $sample->sample_text));

        // Use custom speaker name from metadata if available
        $metadata = is_array($sample->metadata) ? $sample->metadata : [];
        $speakerName = $isArabicLocale
            ? ($metadata['speaker_name_ar'] ?? $metadata['speaker_name'] ?? '')
            : ($metadata['speaker_name'] ?? '');
        $speakerName = trim((string) $speakerName);

        // Fall back to voice catalog label if no custom speaker name
        if ($speakerName === '') {
            $speakerName = trim((string) ($isArabicLocale
                ? ($voiceMeta['label_ar'] ?? $voiceMeta['label'] ?? '')
                : ($voiceMeta['label'] ?? '')));
            $speakerName = $this->stripDialectCodes($speakerName);
        }

        if ($speakerName === '') {
            $speakerName = $title !== '' ? $title : '';
        }

        return [
            'title' => $title,
            'description' => $description,
            'icon' => $sample->icon ?: $this->countryFlagEmoji($sample->country),
            'text' => $text,
            'audio_url' => $this->resolveSampleAudioUrl($sample->audio_path, (string) $sample->language),
            'language' => (string) $sample->language,
            'dialect' => $sample->dialect,
            'emotion' => $sample->emotion,
            'gender' => $sample->gender,
            'country' => $sample->country,
            'voice_name' => $voiceMeta ? $sample->voice_name : null,
            'voice_label' => $speakerName,
            'is_featured' => (bool) $sample->is_featured,
        ];
    }

    /**
     * Convert a 2-letter country code to a flag emoji.
     */
    private function countryFlagEmoji(?string $countryCode): string
    {
        $code = strtoupper(trim((string) $countryCode));
        if (strlen($code) !== 2) {
            return '🌐';
        }

        $flagOffset = 0x1F1E6;
        $asciiOffset = ord('A');

        return mb_chr($flagOffset + ord($code[0]) - $asciiOffset)
            . mb_chr($flagOffset + ord($code[1]) - $asciiOffset);
    }

    private function humanReadableSampleTitle(VoiceSample $sample, bool $isArabicLocale): string
    {
        $fallback = trim((string) ($isArabicLocale ? ($sample->title_ar ?: $sample->title) : $sample->title));
        $emotion = strtolower(trim((string) ($sample->emotion ?? 'neutral')));

        $emotionLabelsAr = [
            'neutral' => 'نمط عام',
            'formal' => 'نمط رسمي',
            'ad_style' => 'نمط إعلاني',
            'news' => 'نمط إخباري',
            'storytelling' => 'نمط قصصي',
            'warm' => 'نمط دافئ',
            'excited' => 'حماسي',
            'calm' => 'نمط هادئ',
        ];

        $emotionLabelsEn = [
            'neutral' => 'General Style',
            'formal' => 'Formal Style',
            'ad_style' => 'Commercial Style',
            'news' => 'News Style',
            'storytelling' => 'Story Style',
            'warm' => 'Warm',
            'excited' => 'Energetic',
            'calm' => 'Calm Style',
        ];

        $emotionLabel = $isArabicLocale
            ? ($emotionLabelsAr[$emotion] ?? null)
            : ($emotionLabelsEn[$emotion] ?? null);

        return $emotionLabel ?: $fallback;
    }

    private function stripDialectCodes(string $value): string
    {
        $cleaned = preg_replace('/\b[a-z]{2}-[A-Z]{2}\b/u', '', $value);
        $cleaned = preg_replace('/\s{2,}/u', ' ', (string) $cleaned);

        return trim((string) $cleaned, " \t\n\r\0\x0B-");
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
                'dialect'  => null,
                'performance_style' => 'neutral',
                'style_prompt' => null,
                'emotion'  => 'neutral',
                'speed'    => 1.0,
                'pitch'    => 0.0,
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
     * @param array{performance_style:mixed,emotion:mixed} $input
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
