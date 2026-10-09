<?php

namespace App\Services\Tts;

use App\Contracts\TtsProviderInterface;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Services\GenerationErrorRecorder;
use App\Support\VoiceTier;
use Illuminate\Support\Facades\Log;

/**
 * TTS Routing Service.
 *
 * Determines which TTS provider to use based on:
 * - Global mode (legacy / hybrid)
 * - User's plan provider policy override
 * - Plan-aware engine selection (free=WaveNet, paid=Gemini)
 * - Fallback policy (Gemini fails → Google Cloud TTS)
 *
 * Logs telemetry for every TTS request.
 */
class TtsRoutingService
{
    public const MODE_LEGACY = 'legacy';
    public const MODE_GOOGLE = 'google';
    public const MODE_HYBRID = 'hybrid';

    private GoogleCloudTtsProvider $googleProvider;
    private GeminiTtsProvider $geminiProvider;

    public function __construct(
        GoogleCloudTtsProvider $googleProvider,
        GeminiTtsProvider $geminiProvider,
    ) {
        $this->googleProvider = $googleProvider;
        $this->geminiProvider = $geminiProvider;
    }

    /**
     * Synthesize text using the appropriate provider based on routing policy.
     *
     * @param  string     $text           Text to synthesize
     * @param  string     $targetLanguage Language code
     * @param  array      $voiceConfig    Voice configuration
     * @param  Plan|null  $plan           User's active plan
     * @param  int|null   $userId         User ID for telemetry
     * @return array                      Standard TTS result array
     */
    public function synthesize(
        string $text,
        string $targetLanguage,
        array $voiceConfig = [],
        ?Plan $plan = null,
        ?int $userId = null,
    ): array {
        $mode = $this->getGlobalMode();
        $provider = $this->resolveProvider($mode, $plan, $voiceConfig);
        $fallbackUsed = false;

        try {
            $result = $provider->synthesize($text, $targetLanguage, $voiceConfig);
        } catch (\Throwable $primaryError) {
            // If the primary provider fails and it's not Google Cloud, try fallback 
            if ($provider->name() !== $this->googleProvider->name() && $this->shouldFallback($mode)) {
                Log::warning('TTS primary provider failed, falling back to Google Cloud.', [
                    'primary_provider' => $provider->name(),
                    'error' => $primaryError->getMessage(),
                    'user_id' => $userId,
                ]);

                try {
                    $fallbackVoiceConfig = $this->adaptVoiceConfigForFallback($voiceConfig, $targetLanguage);
                    $result = $this->googleProvider->synthesize($text, $targetLanguage, $fallbackVoiceConfig);
                    $fallbackUsed = true;
                    app(GenerationErrorRecorder::class)->fallbackUsed($provider->name(), $primaryError, $userId, $targetLanguage, $voiceConfig);
                } catch (\Throwable $fallbackError) {
                    Log::error('TTS fallback to Google Cloud also failed.', [
                        'primary_error' => $primaryError->getMessage(),
                        'fallback_error' => $fallbackError->getMessage(),
                        'user_id' => $userId,
                    ]);
                    $compact = fn (\Throwable $e, int $max) => mb_substr(
                        (string) preg_replace('/\s+/u', ' ', $e->getMessage()),
                        0,
                        $max,
                    );
                    throw new \RuntimeException(
                        $compact($primaryError, 200).' | Google fallback failed: '.$compact($fallbackError, 290),
                        0,
                        $primaryError,
                    );
                }
            } else {
                throw $primaryError;
            }
        }

        // Add routing metadata
        $result['fallback_used'] = $fallbackUsed;
        $result['routing_mode'] = $mode;
        $result['plan_slug'] = $plan?->slug ?? 'free';

        // Log telemetry
        $this->logTelemetry($result, $userId);

        return $result;
    }

    /**
     * Resolve which provider to use.
     */
    public function resolveProvider(string $mode, ?Plan $plan, array $voiceConfig = []): TtsProviderInterface
    {
        $requestedTier = VoiceTier::normalize((string) ($voiceConfig['tier'] ?? VoiceTier::STANDARD));

        // Business rule: Standard quality must always run on Google WaveNet
        // (never Gemini), regardless of plan override or hybrid mode.
        if ($requestedTier === VoiceTier::STANDARD) {
            return $this->googleProvider;
        }

        // Google / Legacy mode: always use Google Cloud (WaveNet + Chirp)
        if ($mode === self::MODE_LEGACY || $mode === self::MODE_GOOGLE) {
            return $this->googleProvider;
        }

        // Check for per-plan provider override FIRST (plan policy takes priority).
        // This ensures HD plans with tts_provider_policy=gemini use Gemini
        // even if the selected voice metadata says google_cloud.
        $planOverride = $this->getPlanProviderOverride($plan);
        if ($planOverride !== null) {
            $provider = $this->getProviderByName($planOverride);
            // Verify the provider is available
            if ($planOverride === 'gemini' && !$this->geminiProvider->isAvailable()) {
                Log::info('Plan policy requires Gemini but unavailable, falling back to Google Cloud.', [
                    'plan_slug' => $plan?->slug,
                ]);
                return $this->googleProvider;
            }
            return $provider;
        }

        // Explicit provider hint from UI voice selection (demo/dashboard HD catalogs).
        // Only used when there's no plan-level override.
        $requestedProvider = strtolower(trim((string) ($voiceConfig['provider'] ?? '')));
        if ($requestedProvider !== '' && $mode === self::MODE_HYBRID) {
            if ($requestedProvider === 'google_cloud') {
                return $this->googleProvider;
            }

            if ($requestedProvider === 'gemini') {
                if ($this->geminiProvider->isAvailable()) {
                    return $this->geminiProvider;
                }

                Log::info('Gemini requested by UI voice selection but unavailable, using Google Cloud.', [
                    'plan_slug' => $plan?->slug,
                ]);

                return $this->googleProvider;
            }
        }

        // Hybrid mode: Free plan → Google WaveNet, Paid plans → Gemini
        if ($mode === self::MODE_HYBRID) {
            $isFree = ($plan === null || ($plan->slug ?? 'free') === 'free' || (int) $plan->price_cents === 0);

            if ($isFree) {
                return $this->googleProvider;
            }

            // Paid plans: use Gemini if available
            if ($this->geminiProvider->isAvailable()) {
                return $this->geminiProvider;
            }

            // Gemini not configured, fall back to Google Cloud
            Log::info('Gemini TTS not available, using Google Cloud for paid plan.', [
                'plan_slug' => $plan?->slug,
            ]);
            return $this->googleProvider;
        }

        // Default: Google Cloud
        return $this->googleProvider;
    }

    /**
     * Get the global TTS mode from admin settings.
     */
    public function getGlobalMode(): string
    {
        $mode = strtolower(trim((string) SiteSetting::get('tts_mode', config('listen.tts.mode', self::MODE_GOOGLE))));

        return in_array($mode, [self::MODE_LEGACY, self::MODE_GOOGLE, self::MODE_HYBRID], true) ? $mode : self::MODE_GOOGLE;
    }

    /**
     * Check if fallback is enabled.
     */
    public function shouldFallback(string $mode): bool
    {
        if ($mode === self::MODE_LEGACY || $mode === self::MODE_GOOGLE) {
            return false;
        }

        $setting = SiteSetting::get('tts_fallback_enabled', '1');
        return (string) $setting === '1';
    }

    /**
     * Get per-plan provider override.
     */
    private function getPlanProviderOverride(?Plan $plan): ?string
    {
        if (! $plan) {
            return null;
        }

        // Site settings override always wins (admin runtime control).
        $override = SiteSetting::get("tts_plan_provider_{$plan->slug}");
        $override = $this->normalizeProviderPolicyValue($override);
        if ($override !== null) {
            return $override;
        }

        // Otherwise use plan-level policy from DB.
        return $this->normalizeProviderPolicyValue($plan->tts_provider_policy ?? null);
    }

    private function normalizeProviderPolicyValue(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            '', 'auto', 'default' => null,
            'google_cloud', 'google_only' => 'google_cloud',
            'gemini', 'gemini_only', 'gemini_preferred' => 'gemini',
            default => null,
        };
    }

    /**
     * Get a provider by its name.
     */
    public function getProviderByName(string $name): TtsProviderInterface
    {
        return match ($name) {
            'gemini' => $this->geminiProvider,
            default => $this->googleProvider,
        };
    }

    /**
     * Get all available providers.
     *
     * @return TtsProviderInterface[]
     */
    public function getAvailableProviders(): array
    {
        $providers = [$this->googleProvider];
        if ($this->geminiProvider->isAvailable()) {
            $providers[] = $this->geminiProvider;
        }
        return $providers;
    }

    /**
     * Adapt voice config when falling back from Gemini to Google Cloud.
     * Maps Gemini-specific voice names back to Google voice IDs.
     */
    private function adaptVoiceConfigForFallback(array $voiceConfig, string $targetLanguage): array
    {
        $adapted = $voiceConfig;

        // Any Gemini voice (raw or branded) is invalid for Google Cloud.
        // Try to resolve a matching Google Cloud voice from the DB first,
        // otherwise use the default for the target language.
        $voiceName = $adapted['voice_name'] ?? '';
        $geminiVoices = ['Zephyr', 'Puck', 'Charon', 'Kore', 'Fenrir', 'Leda', 'Orus', 'Aoede',
            'Callirrhoe', 'Autonoe', 'Enceladus', 'Iapetus', 'Umbriel', 'Algieba', 'Despina',
            'Erinome', 'Gacrux', 'Laomedeia', 'Achernar', 'Achird', 'Algenib', 'Alnilam',
            'Pulcherrima', 'Rasalgethi', 'Sadachbia', 'Sadaltager', 'Schedar', 'Sulafat',
            'Vindemiatrix', 'Zubenelgenubi'];

        $isGeminiVoice = in_array(ucfirst(strtolower($voiceName)), $geminiVoices, true);

        // Also treat branded internal IDs (gemini-*) as Gemini voices
        if (!$isGeminiVoice && str_starts_with(strtolower($voiceName), 'gemini-')) {
            $isGeminiVoice = true;
        }

        if ($isGeminiVoice) {
            // Use default Google Cloud voice for the language
            $adapted['voice_name'] = \App\Support\VoiceCatalog::defaultForLanguage($targetLanguage, 'standard');
        }

        // Remove Gemini-specific params
        unset($adapted['style'], $adapted['speakers']);

        return $adapted;
    }

    /**
     * Log telemetry for a TTS request.
     */
    private function logTelemetry(array $result, ?int $userId): void
    {
        $entry = [
            'provider' => $result['provider'] ?? 'unknown',
            'model' => $result['model'] ?? null,
            'latency_ms' => $result['latency_ms'] ?? null,
            'char_count' => $result['char_count'] ?? 0,
            'cost_estimate_usd' => $result['cost_estimate_usd'] ?? null,
            'fallback_used' => $result['fallback_used'] ?? false,
            'routing_mode' => $result['routing_mode'] ?? 'unknown',
            'plan_slug' => $result['plan_slug'] ?? 'free',
            'user_id' => $userId,
            'timestamp' => now()->toIso8601String(),
        ];

        Log::channel(config('listen.tts.telemetry_channel', 'stack'))->info('TTS request completed.', $entry);
    }
}
