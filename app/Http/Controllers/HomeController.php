<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\TranslationService;
use App\Services\TtsService;
use App\Support\AudioUrl;
use App\Support\UsageEstimator;
use App\Support\VoiceCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class HomeController extends Controller
{
    public function index()
    {
        $pricingPlans = $this->pricingPlans();
        $highlightPlans = array_slice($pricingPlans, 0, 3);

        return view('landing', [
            'highlightPlans' => $highlightPlans,
        ]);
    }

    public function pricingPage()
    {
        return view('pricing', [
            'pricingPlans' => $this->pricingPlans(),
        ]);
    }

    public function demoPage(Request $request)
    {
        return view('demo', [
            'voiceCatalog' => VoiceCatalog::publicCatalog(),
            'defaultVoiceAr' => VoiceCatalog::voiceMeta(VoiceCatalog::defaultForLanguage('ar')),
            'defaultVoiceEn' => VoiceCatalog::voiceMeta(VoiceCatalog::defaultForLanguage('en')),
            'demoQuota' => $this->demoQuotaSnapshot($request, true),
            'guestAllowanceChars' => $this->demoCharAllowance($request),
            'starterPlanChars' => max(1, (int) (Plan::query()->where('slug', 'free')->value('monthly_chars') ?? 30000)),
        ]);
    }

    public function demo(Request $request)
    {
        $maxChars = $this->demoCharAllowance($request);

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:50000'],
            'target_language' => ['required', 'in:ar,en'],
            'translate' => ['nullable', 'boolean'],
            'voice_key' => ['nullable', 'string', 'max:60'],
        ]);

        $voiceName = $this->normalizeVoiceSelection(
            $validated['target_language'],
            $validated['voice_key'] ?? null,
        );

        $text = trim($validated['text']);
        $sourceLanguage = preg_match('/[\x{0600}-\x{06FF}]/u', $text) ? 'ar' : 'en';
        $estimate = UsageEstimator::estimate($text);
        if ($estimate['chars'] > $maxChars) {
            throw ValidationException::withMessages([
                'text' => $this->demoLimitExceededMessage($request, $maxChars),
            ]);
        }

        $quota = $this->enforceDemoPolicy($request, $estimate['chars'], false);

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
        $maxChars = $this->demoCharAllowance($request);

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:50000'],
            'target_language' => ['required', 'in:ar,en'],
            'translate' => ['nullable', 'boolean'],
            'voice_key' => ['nullable', 'string', 'max:60'],
        ]);

        $voiceName = $this->normalizeVoiceSelection(
            $validated['target_language'],
            $validated['voice_key'] ?? null,
        );

        $text = trim($validated['text']);
        $sourceLanguage = preg_match('/[\x{0600}-\x{06FF}]/u', $text) ? 'ar' : 'en';
        $estimate = UsageEstimator::estimate($text);
        if ($estimate['chars'] > $maxChars) {
            throw ValidationException::withMessages([
                'text' => $this->demoLimitExceededMessage($request, $maxChars),
            ]);
        }

        $quota = $this->enforceDemoPolicy($request, $estimate['chars'], true);

        $textForSpeech = $translationService->maybeTranslate(
            $text,
            $sourceLanguage,
            $validated['target_language'],
            (bool) ($validated['translate'] ?? false),
        );

        try {
            $audio = $ttsService->synthesize($textForSpeech, $validated['target_language'], $voiceName);
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
                'audio_url' => AudioUrl::fromPath($audio['audio_path']),
                'duration_seconds' => $audio['duration_seconds'] ?? $estimate['seconds'],
                'estimated_chars' => $estimate['chars'],
                'quota' => $quota,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Voice generation failed. Please try again in a moment.',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 422);
        }
    }

    private function enforceDemoPolicy(Request $request, int $chars, bool $preview): array
    {
        $isUser = (bool) $request->user();
        [$identities] = $this->resolveDemoIdentities($request);
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
            foreach ($identities as $identity) {
                $lifetimeCharsKey = "demo:guest:lifetime:chars:{$identity}";
                $usedChars = (int) Cache::get($lifetimeCharsKey, 0);

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

                Cache::put($globalRequestKey, $globalUsedRequests + 1, now()->endOfDay());
                Cache::put($globalCharKey, $globalUsedChars + $chars, now()->endOfDay());
            }

            foreach ($identities as $identity) {
                $lifetimeCharsKey = "demo:guest:lifetime:chars:{$identity}";
                $usedChars = (int) Cache::get($lifetimeCharsKey, 0);
                Cache::put($lifetimeCharsKey, $usedChars + $chars, now()->addYears(10));
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

            Cache::put($globalRequestKey, $globalUsedRequests + 1, now()->endOfDay());
            Cache::put($globalCharKey, $globalUsedChars + $chars, now()->endOfDay());
        }

        foreach ($identities as $identity) {
            $dailyRequestKey = 'demo:req:'.now()->format('Ymd').':'.$scope.':'.$identity;
            $dailyCharKey = 'demo:chars:'.now()->format('Ymd').':'.$scope.':'.$identity;

            $usedRequests = (int) Cache::get($dailyRequestKey, 0);
            $usedChars = (int) Cache::get($dailyCharKey, 0);

            Cache::put($dailyRequestKey, $usedRequests + 1, now()->endOfDay());
            Cache::put($dailyCharKey, $usedChars + $chars, now()->endOfDay());
        }

        return $this->demoQuotaSnapshot($request, $preview);
    }

    private function normalizeVoiceSelection(string $language, ?string $voiceKey): string
    {
        $voiceKey = trim((string) $voiceKey);

        if ($voiceKey === '') {
            return VoiceCatalog::defaultForLanguage($language);
        }

        $voiceName = VoiceCatalog::nameFromKeyForLanguage($language, $voiceKey);
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
            $monthly = round($plan->price_cents / 100, 2);
            $yearly = round($monthly * 0.8, 2);

            return [
                'slug' => $plan->slug,
                'name' => $plan->name,
                'price_monthly' => $monthly,
                'price_yearly' => $yearly,
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
                'features' => is_array($plan->features) ? $plan->features : [],
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

        $ip = trim((string) $request->ip());
        $ua = mb_substr(trim((string) $request->userAgent()), 0, 180);
        $acceptLanguage = mb_substr(trim((string) $request->header('Accept-Language', '')), 0, 64);
        $fingerprintRaw = trim((string) $request->header('X-Listen-Fingerprint', ''));

        $fingerprintIdentity = $fingerprintRaw !== ''
            ? 'fp:'.hash('sha256', $fingerprintRaw)
            : null;
        $ipIdentity = 'ip:'.hash('sha256', $ip);
        $ipUaIdentity = 'ipua:'.hash('sha256', $ip.'|'.$ua.'|'.$acceptLanguage);

        $identities = array_values(array_unique(array_filter([
            $fingerprintIdentity,
            $ipUaIdentity,
            $ipIdentity,
        ])));

        $primary = $fingerprintIdentity ?: $ipUaIdentity;

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
            $used = 0;
            foreach ($identities as $identity) {
                $key = "demo:guest:lifetime:chars:{$identity}";
                $used = max($used, (int) Cache::get($key, 0));
            }
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
}
