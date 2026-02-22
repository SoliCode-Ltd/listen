<?php

namespace App\Http\Controllers;

use App\Models\DemoGuestUsage;
use App\Models\Plan;
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
use Illuminate\Validation\ValidationException;

class HomeController extends Controller
{
    public function __construct(
        private readonly PlanAccessService $planAccessService,
    ) {
    }

    public function index()
    {
        $pricingPlans = $this->pricingPlans();
        $highlightPlans = array_slice($pricingPlans, 0, 3);

        return view('landing', [
            'highlightPlans' => $highlightPlans,
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

        $defaultTier = VoiceTier::STANDARD;

        return view('demo', [
            'voiceCatalog' => VoiceCatalog::publicCatalogByTier(),
            'defaultTier' => $defaultTier,
            'defaultVoiceAr' => VoiceCatalog::voiceMeta(VoiceCatalog::defaultForLanguage('ar', $defaultTier)),
            'defaultVoiceEn' => VoiceCatalog::voiceMeta(VoiceCatalog::defaultForLanguage('en', $defaultTier)),
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
            'voice_tier' => ['nullable', 'in:standard,hd,hd_plus'],
        ]);

        $tier = VoiceTier::normalize((string) ($validated['voice_tier'] ?? VoiceTier::STANDARD));
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
            'message' => 'Setup validated. You can generate voice now.',
            'source_language' => $sourceLanguage,
            'target_language' => $validated['target_language'],
            'translate' => (bool) ($validated['translate'] ?? false),
            'translate_note' => (bool) ($validated['translate'] ?? false)
                ? 'Text will be translated to the target language before audio generation.'
                : 'Text will be spoken as-is without translation.',
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
            'voice_tier' => ['nullable', 'in:standard,hd,hd_plus'],
        ]);

        $tier = VoiceTier::normalize((string) ($validated['voice_tier'] ?? VoiceTier::STANDARD));
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

            $audio = $ttsService->synthesize($textForSpeech, $validated['target_language'], $voiceName, $tier);
            $estimate = UsageEstimator::estimate($textForSpeech);

            return response()->json([
                'ok' => true,
                'source_language' => $sourceLanguage,
                'target_language' => $validated['target_language'],
                'translate' => (bool) ($validated['translate'] ?? false),
                'translate_note' => (bool) ($validated['translate'] ?? false)
                    ? 'Text was translated to the target language before audio generation.'
                    : 'Text was spoken as-is without translation.',
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
                'message' => $firstError !== '' ? $firstError : 'Request validation failed.',
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
                'message' => 'Voice generation failed. Please try again in a moment.',
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
                'text' => "Too many demo requests. Please retry in {$retryAfter}s.",
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
                        'text' => 'Demo preview capacity reached for today. Please try again tomorrow.',
                    ]);
                }

                $globalCharCap = max(1, (int) config('listen.demo.global_preview_daily_chars', 500000));
                $globalCharKey = 'demo:chars:'.now()->format('Ymd').':preview:global';
                $globalUsedChars = (int) Cache::get($globalCharKey, 0);
                if (($globalUsedChars + $chars) > $globalCharCap) {
                    throw ValidationException::withMessages([
                        'text' => 'Demo preview capacity reached for today. Please try again tomorrow.',
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
                    'text' => 'Demo daily request limit reached. Please try again tomorrow.',
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
                    'text' => 'Demo preview capacity reached for today. Please try again tomorrow.',
                ]);
            }

            $globalCharCap = max(1, (int) config('listen.demo.global_preview_daily_chars', 500000));
            $globalCharKey = 'demo:chars:'.now()->format('Ymd').':preview:global';
            $globalUsedChars = (int) Cache::get($globalCharKey, 0);
            if (($globalUsedChars + $chars) > $globalCharCap) {
                throw ValidationException::withMessages([
                    'text' => 'Demo preview capacity reached for today. Please try again tomorrow.',
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

        if ($voiceKey === '') {
            $defaultVoice = VoiceCatalog::defaultForLanguage($language, $tier);
            if ($defaultVoice === '') {
                throw ValidationException::withMessages([
                    'target_language' => 'Selected language is not available for this voice quality.',
                ]);
            }

            return $defaultVoice;
        }

        $voiceName = VoiceCatalog::nameFromKeyForLanguage($language, $voiceKey, $tier);
        if (! $voiceName) {
            throw ValidationException::withMessages([
                'voice_key' => 'Selected voice is not available for the chosen language.',
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

        $mapped = $plans->map(function (Plan $plan): array {
            $monthlyCents = max(0, (int) $plan->price_cents);
            $yearlyCents = (int) round($monthlyCents * 12 * 0.8);
            $monthly = round($monthlyCents / 100, 2);
            $yearly = round($yearlyCents / 100, 2);
            $tier = VoiceTier::normalize((string) ($plan->voice_tier ?: VoiceTier::STANDARD));

            return [
                'slug' => $plan->slug,
                'name' => $plan->name,
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
                'features' => self::resolveStructuredFeatures($plan, $tier),
            ];
        })->values();

        $popularSlug = $mapped->contains(fn (array $plan) => $plan['slug'] === 'creator')
            ? 'creator'
            : (string) ($mapped->firstWhere('price_monthly', '>', 0)['slug'] ?? '');

        return $mapped->map(function (array $plan) use ($popularSlug): array {
            $plan['is_popular'] = $popularSlug !== '' && $plan['slug'] === $popularSlug;
            return $plan;
        })->all();
    }

    /**
     * Build structured features array for the pricing card.
     * If features are already structured (with 'label' key), use them.
     * Otherwise, auto-build defaults from plan attributes.
     *
     * @return array<int, array{label: string, value: string, icon: string}>
     */
    private static function resolveStructuredFeatures(Plan $plan, string $tier): array
    {
        $raw = is_array($plan->features) ? $plan->features : [];

        // Check if features are already in structured format
        if (!empty($raw) && isset($raw[0]) && is_array($raw[0]) && array_key_exists('label', $raw[0])) {
            return $raw;
        }

        // Auto-build defaults from plan attributes
        return [
            ['label' => 'Requests', 'value' => (int) $plan->monthly_requests <= 0 ? 'Unlimited' : number_format((int) $plan->monthly_requests), 'icon' => 'activity'],
            ['label' => 'Max input', 'value' => number_format((int) $plan->max_chars_per_request) . ' chars', 'icon' => 'type'],
            ['label' => 'Commercial use', 'value' => $plan->commercial_use_allowed ? 'Allowed' : 'Personal only', 'icon' => 'lock'],
            ['label' => 'Priority queue', 'value' => ($plan->processing_priority ?? 'standard') === 'high' ? 'High' : 'Standard', 'icon' => 'bolt'],
            ['label' => 'Voice quality', 'value' => VoiceTier::label($tier), 'icon' => 'mic'],
        ];
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
            return "Demo limit exceeded ({$maxChars} non-space characters).";
        }

        $starterPlanChars = max(1, (int) (Plan::query()->where('slug', 'free')->value('monthly_chars') ?? 30000));

        return "Guest demo allows {$maxChars} non-space characters total. Create a free account to unlock {$starterPlanChars} characters to start.";
    }

    private function demoDailyCapReachedMessage(Request $request): string
    {
        if ($request->user()) {
            return 'Demo daily character limit reached. Please try again tomorrow.';
        }

        $starterPlanChars = max(1, (int) (Plan::query()->where('slug', 'free')->value('monthly_chars') ?? 30000));
        $allowance = $this->demoCharAllowance($request);

        return "Guest demo quota reached ({$allowance} chars total). Create a free account to unlock {$starterPlanChars} characters to start.";
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
}
