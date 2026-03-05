<?php

namespace App\Http\Controllers;

use App\Models\DemoGuestUsage;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\VoiceSample;
use App\Services\PlanAccessService;
use App\Services\TranslationService;
use App\Services\TtsService;
use App\Support\AudioUrl;
use App\Support\UsageEstimator;
use App\Support\VoiceCatalog;
use App\Support\VoiceTier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class HomeController extends Controller
{
    /** @var array<int, string> */
    private const PERFORMANCE_STYLE_VALUES = [
        'neutral',
        'educational',
        'commercial',
        'promo',
        'podcast',
        'assistant',
        'narrative',
        'comedic',
        'corporate',
        'conversational',
        'inspirational',
        'cinematic',
        'documentary',
        'storytelling',
        'ad_style',
        'news',
    ];

    /** @var array<int, string> */
    private const EMOTION_VALUES = [
        'neutral',
        'warm',
        'sad',
        'excited',
        'formal',
        'calm',
        'angry',
    ];

    public function __construct(
        private readonly PlanAccessService $planAccessService,
    ) {
    }

    public function index()
    {
        $pricingPlans = $this->pricingPlans();
        $highlightPlans = array_slice($pricingPlans, 0, 3);
        $sampleLanguage = $this->sampleLanguageForLocale(app()->getLocale());
        $isArabicLocale = $sampleLanguage === 'ar';
        $homepageSampleLimit = $this->landingVoiceSampleLimit();

        // Load hero voice samples from admin-managed records.
        $voiceSamples = array_slice(
            Cache::remember("landing_voice_samples:{$sampleLanguage}", 300, function () use ($sampleLanguage, $isArabicLocale) {
                return VoiceSample::query()
                    ->active()
                    ->where('category', 'hero')
                    ->where('language', $sampleLanguage)
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
            'voiceSamples' => $voiceSamples,
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
        ]);
    }

    public function demoPage(Request $request)
    {
        if ($response = $this->denyDemoForAuthenticatedUser($request)) {
            return $response;
        }

        $freePlan = $this->planAccessService->freePlan();
        $defaultTier = $this->planAccessService->resolveTierForPlan($freePlan);
        $defaultProvider = VoiceCatalog::preferredProviderForTier($defaultTier);
        return view('demo', [
            'voiceCatalog' => VoiceCatalog::publicCatalogByTier(),
            'defaultTier' => $defaultTier,
            'defaultVoiceAr' => VoiceCatalog::voiceMeta(VoiceCatalog::defaultForLanguageAndProvider('ar', $defaultTier, $defaultProvider)),
            'defaultVoiceEn' => VoiceCatalog::voiceMeta(VoiceCatalog::defaultForLanguageAndProvider('en', $defaultTier, $defaultProvider)),
            'demoQuota' => $this->demoQuotaSnapshot($request, true),
            'guestAllowanceChars' => $this->demoCharAllowance($request),
            'starterPlanChars' => max(1, (int) (Plan::query()->where('slug', 'free')->value('monthly_chars') ?? 30000)),
            'demoTiers' => array_map(fn (string $tier) => [
                'key' => $tier,
                'label' => VoiceTier::label($tier),
            ], VoiceTier::all()),
        ]);
    }

    public function demo(Request $request)
    {
        if ($response = $this->denyDemoForAuthenticatedUser($request)) {
            return $response;
        }

        $maxChars = $this->demoCharAllowance($request);

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:50000'],
            'target_language' => ['required', 'in:ar,en'],
            'translate' => ['nullable', 'boolean'],
            'voice_key' => ['nullable', 'string', 'max:60'],
            'voice_tier' => ['nullable', 'in:standard,hd'],
            // Studio parameters
            'dialect' => ['nullable', 'string', 'max:10'],
            'performance_style' => ['nullable', 'string', 'in:'.implode(',', self::PERFORMANCE_STYLE_VALUES)],
            'style_prompt' => ['nullable', 'string', 'max:2000'],
            'emotion' => ['nullable', 'string', 'in:'.implode(',', self::EMOTION_VALUES)],
            'speed' => ['nullable', 'numeric', 'min:0.5', 'max:2.0'],
            'pitch' => ['nullable', 'numeric', 'min:-10', 'max:10'],
        ]);

        $demoPlan = $this->planAccessService->freePlan();
        $defaultTier = $this->planAccessService->resolveTierForPlan($demoPlan);
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
    }

    public function preview(
        Request $request,
        TranslationService $translationService,
        TtsService $ttsService,
    ) {
        if ($response = $this->denyDemoForAuthenticatedUser($request)) {
            return $response;
        }

        $maxChars = $this->demoCharAllowance($request);

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:50000'],
            'target_language' => ['required', 'in:ar,en'],
            'translate' => ['nullable', 'boolean'],
            'voice_key' => ['nullable', 'string', 'max:60'],
            'voice_tier' => ['nullable', 'in:standard,hd'],
            // Studio parameters
            'dialect' => ['nullable', 'string', 'max:10'],
            'performance_style' => ['nullable', 'string', 'in:'.implode(',', self::PERFORMANCE_STYLE_VALUES)],
            'style_prompt' => ['nullable', 'string', 'max:2000'],
            'emotion' => ['nullable', 'string', 'in:'.implode(',', self::EMOTION_VALUES)],
            'speed' => ['nullable', 'numeric', 'min:0.5', 'max:2.0'],
            'pitch' => ['nullable', 'numeric', 'min:-10', 'max:10'],
        ]);

        $demoPlan = $this->planAccessService->freePlan();
        $defaultTier = $this->planAccessService->resolveTierForPlan($demoPlan);
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
                $demoPlan,
                null,
            );
            $estimate = UsageEstimator::estimate($textForSpeech);

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
                'duration_seconds' => $audio['duration_seconds'] ?? $estimate['seconds'],
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
                'quota' => $quota ?: $this->demoQuotaSnapshot($request, true),
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
                'quota' => $quota ?: $this->demoQuotaSnapshot($request, true),
            ], 422);
        }
    }

    private function enforceDemoPolicy(Request $request, int $chars, bool $preview, bool $commit = true): array
    {
        $isUser = (bool) $request->user();
        [$identities, $primaryIdentity] = $this->resolveDemoIdentities($request);
        $scope = $preview ? 'preview' : 'check';
        $maxPerMinute = max(1, (int) config("listen.demo.{$scope}.".($isUser ? 'user' : 'guest')."_rate_per_minute", 10));

        $retryAfter = 0;
        foreach ($identities as $identity) {
            $rateKey = "demo:rate:{$scope}:{$identity}";
            if (RateLimiter::tooManyAttempts($rateKey, $maxPerMinute)) {
                $retryAfter = max($retryAfter, max(1, RateLimiter::availableIn($rateKey)));
            }
        }
        if ($retryAfter > 0) {
            throw ValidationException::withMessages([
                'text' => __('ui.backend.demo_too_many_requests', ['seconds' => $retryAfter]),
            ]);
        }

        foreach ($identities as $identity) {
            $rateKey = "demo:rate:{$scope}:{$identity}";
            RateLimiter::hit($rateKey, 60);
        }

        if (! $isUser) {
            $lifetimeCap = $this->demoCharAllowance($request);
            if (! $commit) {
                $usedChars = $this->guestLifetimeUsedChars($identities);
                if (($usedChars + $chars) > $lifetimeCap) {
                    throw ValidationException::withMessages([
                        'text' => $this->demoDailyCapReachedMessage($request),
                    ]);
                }
            }

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

            if ($commit) {
                $writeIdentities = $this->writableGuestIdentities($identities, $primaryIdentity);

                $this->reserveGuestLifetimeChars($request, $writeIdentities, $chars, $lifetimeCap);
            }

            return $this->demoQuotaSnapshot($request, $preview);
        }

        $dailyRequestCap = max(1, (int) config("listen.demo.{$scope}.user_daily_requests", 50));
        foreach ($identities as $identity) {
            $dailyRequestKey = 'demo:req:'.now()->format('Ymd').':'.$scope.':'.$identity;
            $usedRequests = (int) Cache::get($dailyRequestKey, 0);
            if (($usedRequests + 1) > $dailyRequestCap) {
                throw ValidationException::withMessages([
                    'text' => __('ui.backend.demo_daily_request_limit'),
                ]);
            }
        }

        $dailyCharCap = $this->demoDailyCharCap($request, $scope);
        foreach ($identities as $identity) {
            $dailyCharKey = 'demo:chars:'.now()->format('Ymd').':'.$scope.':'.$identity;
            $usedChars = (int) Cache::get($dailyCharKey, 0);
            if (($usedChars + $chars) > $dailyCharCap) {
                throw ValidationException::withMessages([
                    'text' => $this->demoDailyCapReachedMessage($request),
                ]);
            }
        }

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

        if ($commit) {
            foreach ($identities as $identity) {
                $dailyRequestKey = 'demo:req:'.now()->format('Ymd').':'.$scope.':'.$identity;
                $dailyCharKey = 'demo:chars:'.now()->format('Ymd').':'.$scope.':'.$identity;

                $usedRequests = (int) Cache::get($dailyRequestKey, 0);
                $usedChars = (int) Cache::get($dailyCharKey, 0);

                Cache::put($dailyRequestKey, $usedRequests + 1, now()->endOfDay());
                Cache::put($dailyCharKey, $usedChars + $chars, now()->endOfDay());
            }
        }

        return $this->demoQuotaSnapshot($request, $preview);
    }

    private function rollbackDemoPolicy(Request $request, int $chars, bool $preview): array
    {
        $isUser = (bool) $request->user();
        [$identities, $primaryIdentity] = $this->resolveDemoIdentities($request);
        $scope = $preview ? 'preview' : 'check';

        if ($preview) {
            $globalRequestKey = 'demo:req:'.now()->format('Ymd').':preview:global';
            $globalCharKey = 'demo:chars:'.now()->format('Ymd').':preview:global';
            $this->decrementCacheCounter($globalRequestKey, 1, now()->endOfDay());
            $this->decrementCacheCounter($globalCharKey, $chars, now()->endOfDay());
        }

        if (! $isUser) {
            $writeIdentities = $this->writableGuestIdentities($identities, $primaryIdentity);

            $this->decrementGuestLifetimeChars($writeIdentities, $chars);

            return $this->demoQuotaSnapshot($request, $preview);
        }

        foreach ($identities as $identity) {
            $dailyRequestKey = 'demo:req:'.now()->format('Ymd').':'.$scope.':'.$identity;
            $dailyCharKey = 'demo:chars:'.now()->format('Ymd').':'.$scope.':'.$identity;

            $this->decrementCacheCounter($dailyRequestKey, 1, now()->endOfDay());
            $this->decrementCacheCounter($dailyCharKey, $chars, now()->endOfDay());
        }

        return $this->demoQuotaSnapshot($request, $preview);
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
        $preferredProvider = VoiceCatalog::preferredProviderForTier($tier);

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
     * @return array<int, array<string, mixed>>
     */
    private function pricingPlans(): array
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('price_cents')
            ->get();

        $locale = app()->getLocale();

        $mapped = $plans->map(function (Plan $plan) use ($locale): array {
            $monthlyCents = max(0, (int) $plan->price_cents);
            $yearlyCents = (int) round($monthlyCents * 12 * 0.8);
            $monthly = round($monthlyCents / 100, 2);
            $yearly = round($yearlyCents / 100, 2);
            $tier = VoiceTier::normalize((string) ($plan->voice_tier ?: VoiceTier::STANDARD));

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
        if ($request->user()) {
            return max(1, (int) config('listen.demo.user_char_allowance', 30000));
        }

        return max(1, (int) config('listen.demo.guest_char_allowance', 300));
    }

    private function demoDailyCharCap(Request $request, string $scope): int
    {
        if (! $request->user()) {
            return $this->demoCharAllowance($request);
        }

        return max(1, (int) config("listen.demo.{$scope}.user_daily_chars", 50000));
    }

    private function demoLimitExceededMessage(Request $request, int $maxChars): string
    {
        if ($request->user()) {
            return __('ui.backend.demo_limit_exceeded', ['max' => $maxChars]);
        }

        $starterPlanChars = max(1, (int) (Plan::query()->where('slug', 'free')->value('monthly_chars') ?? 30000));

        return __('ui.backend.demo_guest_limit_exceeded', ['max' => $maxChars, 'starter' => $starterPlanChars]);
    }

    private function demoDailyCapReachedMessage(Request $request): string
    {
        if ($request->user()) {
            return __('ui.backend.demo_daily_char_limit');
        }

        $starterPlanChars = max(1, (int) (Plan::query()->where('slug', 'free')->value('monthly_chars') ?? 30000));
        $allowance = $this->demoCharAllowance($request);

        return __('ui.backend.demo_guest_quota_reached', ['allowance' => $allowance, 'starter' => $starterPlanChars]);
    }

    /**
     * @return array{0:array<int,string>,1:string}
     */
    private function resolveDemoIdentities(Request $request): array
    {
        if ($request->user()) {
            $identity = 'u:'.$request->user()->id;
            return [[$identity], $identity];
        }

        $ua = mb_substr(trim((string) $request->userAgent()), 0, 180);
        $acceptLanguage = mb_substr(trim((string) $request->header('Accept-Language', '')), 0, 64);
        $secChUa = mb_substr(trim((string) $request->header('Sec-CH-UA', '')), 0, 180);
        $secChUaPlatform = mb_substr(trim((string) $request->header('Sec-CH-UA-Platform', '')), 0, 80);
        $secChUaMobile = mb_substr(trim((string) $request->header('Sec-CH-UA-Mobile', '')), 0, 20);
        $ip = trim((string) $request->ip());
        $fingerprintRaw = trim((string) $request->header('X-Listen-Fingerprint', ''));
        if ($fingerprintRaw === '') {
            $fingerprintRaw = trim((string) $request->cookie('listen_demo_fp', ''));
        }

        $fingerprintIdentity = $fingerprintRaw !== ''
            ? 'fp:'.hash('sha256', $fingerprintRaw)
            : null;
        $deviceIdentity = 'dv:'.hash('sha256', implode('|', [
            $ua,
            $acceptLanguage,
            $secChUa,
            $secChUaPlatform,
            $secChUaMobile,
        ]));
        $uaIdentity = 'ua:'.hash('sha256', implode('|', [
            $ua,
            $acceptLanguage,
            $secChUa,
            $secChUaPlatform,
            $secChUaMobile,
        ]));
        $ipIdentity = $ip !== '' ? 'ip:'.hash('sha256', $ip) : null;

        $identities = array_values(array_unique(array_filter([
            $fingerprintIdentity,
            $deviceIdentity,
            $uaIdentity,
            $ipIdentity,
        ])));

        $primary = $fingerprintIdentity ?: $deviceIdentity;

        return [$identities, $primary];
    }

    /**
     * @return array{scope:string,limit_chars:int,used_chars:int,remaining_chars:int,is_exhausted:bool}
     */
    private function demoQuotaSnapshot(Request $request, bool $preview): array
    {
        [$identities, $primaryIdentity] = $this->resolveDemoIdentities($request);
        $scope = $request->user() ? ($preview ? 'preview' : 'check') : 'lifetime';
        $limit = $request->user()
            ? $this->demoDailyCharCap($request, $scope)
            : $this->demoCharAllowance($request);

        if ($request->user()) {
            $key = 'demo:chars:'.now()->format('Ymd').':'.$scope.':'.$primaryIdentity;
            $used = min($limit, max(0, (int) Cache::get($key, 0)));
        } else {
            $used = $this->guestLifetimeUsedChars($identities);
            $used = min($limit, max(0, $used));
        }

        $remaining = max(0, $limit - $used);

        return [
            'scope' => $scope,
            'limit_chars' => $limit,
            'used_chars' => $used,
            'remaining_chars' => $remaining,
            'is_exhausted' => $remaining <= 0,
        ];
    }

    /**
     * @param array<int, string> $identities
     */
    private function guestLifetimeUsedChars(array $identities): int
    {
        $identities = array_values(array_filter($identities, fn ($value) => trim((string) $value) !== ''));
        if (empty($identities)) {
            return 0;
        }

        return (int) (DemoGuestUsage::query()
            ->whereIn('identity_hash', $identities)
            ->max('used_chars') ?? 0);
    }

    /**
     * Persist quota usage on stable, non-IP identities so full-page reloads
     * see the same allowance without over-coupling unrelated visitors by IP.
     *
     * @param array<int, string> $identities
     * @return array<int, string>
     */
    private function writableGuestIdentities(array $identities, string $primaryIdentity): array
    {
        $normalized = array_values(array_unique(array_filter(
            array_map(static fn ($value) => trim((string) $value), $identities),
            static fn (string $value) => $value !== ''
        )));

        $preferred = [];

        if (trim($primaryIdentity) !== '' && ! str_starts_with($primaryIdentity, 'ip:')) {
            $preferred[] = $primaryIdentity;
        }

        foreach ($normalized as $identity) {
            if (str_starts_with($identity, 'ip:')) {
                continue;
            }

            $preferred[] = $identity;
        }

        $preferred = array_values(array_unique($preferred));

        if ($preferred !== []) {
            return $preferred;
        }

        return $normalized;
    }

    /**
     * @param array<int, string> $identities
     */
    private function reserveGuestLifetimeChars(Request $request, array $identities, int $chars, int $limit): void
    {
        $chars = max(0, $chars);
        $identities = array_values(array_unique(array_filter($identities, fn ($value) => trim((string) $value) !== '')));

        if ($chars <= 0 || empty($identities)) {
            return;
        }

        DB::transaction(function () use ($request, $identities, $chars, $limit) {
            $now = now();
            $seedRows = array_map(static fn (string $identity): array => [
                'identity_hash' => $identity,
                'used_chars' => 0,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $identities);

            DemoGuestUsage::query()->upsert(
                $seedRows,
                ['identity_hash'],
                ['last_seen_at', 'updated_at']
            );

            $rows = DemoGuestUsage::query()
                ->whereIn('identity_hash', $identities)
                ->lockForUpdate()
                ->get()
                ->keyBy('identity_hash');

            $usedChars = 0;
            foreach ($identities as $identity) {
                $usedChars = max($usedChars, (int) ($rows[$identity]->used_chars ?? 0));
            }

            if (($usedChars + $chars) > $limit) {
                throw ValidationException::withMessages([
                    'text' => $this->demoDailyCapReachedMessage($request),
                ]);
            }

            foreach ($identities as $identity) {
                /** @var \App\Models\DemoGuestUsage|null $row */
                $row = $rows[$identity] ?? null;
                if (! $row) {
                    continue;
                }

                $row->update([
                    'used_chars' => max(0, (int) $row->used_chars + $chars),
                    'last_seen_at' => $now,
                ]);
            }
        }, 3);
    }

    /**
     * @param array<int, string> $identities
     */
    private function decrementGuestLifetimeChars(array $identities, int $chars): void
    {
        $chars = max(0, $chars);
        $identities = array_values(array_unique(array_filter($identities, fn ($value) => trim((string) $value) !== '')));

        if ($chars <= 0 || empty($identities)) {
            return;
        }

        DB::transaction(function () use ($identities, $chars) {
            $rows = DemoGuestUsage::query()
                ->whereIn('identity_hash', $identities)
                ->lockForUpdate()
                ->get();

            $now = now();
            foreach ($rows as $row) {
                $row->update([
                    'used_chars' => max(0, (int) $row->used_chars - $chars),
                    'last_seen_at' => $now,
                ]);
            }
        }, 3);
    }

    private function denyDemoForAuthenticatedUser(Request $request)
    {
        if (! $request->user()) {
            return null;
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'message' => 'Demo is available for visitors only.',
            ], 403);
        }

        return redirect()->route('dashboard');
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

        // Standard tier: no performance controls — use defaults only.
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
        if ($performanceStyle === '' && in_array($emotion, self::PERFORMANCE_STYLE_VALUES, true) && ! in_array($emotion, self::EMOTION_VALUES, true)) {
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

    private function resolveSampleAudioUrl(?string $audioPath, string $language): ?string
    {
        $normalizedPath = ltrim(trim((string) $audioPath), '/');
        if ($normalizedPath !== '' && Storage::disk('public')->exists($normalizedPath)) {
            return AudioUrl::fromPath($normalizedPath);
        }

        return strtolower(trim($language)) === 'ar' ? '/audio/ar.mp3' : '/audio/en.mp3';
    }
}
