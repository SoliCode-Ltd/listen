<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\TranslationService;
use App\Services\TtsService;
use App\Support\UsageEstimator;
use App\Support\VoiceCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
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

    public function demoPage()
    {
        return view('demo', [
            'voiceCatalog' => VoiceCatalog::publicCatalog(),
            'defaultVoiceAr' => VoiceCatalog::voiceMeta(VoiceCatalog::defaultForLanguage('ar')),
            'defaultVoiceEn' => VoiceCatalog::voiceMeta(VoiceCatalog::defaultForLanguage('en')),
        ]);
    }

    public function demo(Request $request)
    {
        $maxChars = $request->user() ? 3500 : 1500;

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:'.$maxChars],
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

        $this->enforceDemoPolicy($request, $estimate['chars'], false);

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
        ]);
    }

    public function preview(
        Request $request,
        TranslationService $translationService,
        TtsService $ttsService,
    ) {
        $maxChars = $request->user() ? 1500 : 700;

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:'.$maxChars],
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

        $this->enforceDemoPolicy($request, UsageEstimator::estimate($text)['chars'], true);

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
                'audio_url' => Storage::disk('public')->url($audio['audio_path']),
                'duration_seconds' => $audio['duration_seconds'] ?? $estimate['seconds'],
                'estimated_chars' => $estimate['chars'],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Voice generation failed. Please try again in a moment.',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 422);
        }
    }

    private function enforceDemoPolicy(Request $request, int $chars, bool $preview): void
    {
        $identity = $request->user()
            ? 'u:'.$request->user()->id
            : 'ip:'.$request->ip();

        $scope = $preview ? 'preview' : 'check';
        $maxPerMinute = $preview
            ? ($request->user() ? 30 : 8)
            : ($request->user() ? 80 : 20);

        $rateKey = "demo:rate:{$scope}:{$identity}";
        if (RateLimiter::tooManyAttempts($rateKey, $maxPerMinute)) {
            $retryAfter = max(1, RateLimiter::availableIn($rateKey));

            throw ValidationException::withMessages([
                'text' => "Too many demo requests. Please retry in {$retryAfter}s.",
            ]);
        }

        RateLimiter::hit($rateKey, 60);

        $dailyCapChars = $preview
            ? ($request->user() ? 120000 : 20000)
            : ($request->user() ? 250000 : 50000);

        $dailyKey = 'demo:chars:'.now()->format('Ymd').':'.$scope.':'.$identity;
        $used = (int) Cache::get($dailyKey, 0);

        if (($used + $chars) > $dailyCapChars) {
            throw ValidationException::withMessages([
                'text' => 'Demo daily character limit reached. Please try again tomorrow or sign in for higher limits.',
            ]);
        }

        Cache::put($dailyKey, $used + $chars, now()->addDay());
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
                'max_chars_per_request' => (int) $plan->max_chars_per_request,
                'features' => is_array($plan->features) ? $plan->features : [],
            ];
        })->values();

        $paid = $mapped->filter(fn (array $plan) => $plan['price_monthly'] > 0)->values();
        $popularSlug = $paid->count() > 1
            ? (string) $paid->get(1)['slug']
            : (string) ($paid->first()['slug'] ?? '');

        return $mapped->map(function (array $plan) use ($popularSlug): array {
            $plan['is_popular'] = $popularSlug !== '' && $plan['slug'] === $popularSlug;
            return $plan;
        })->all();
    }
}
