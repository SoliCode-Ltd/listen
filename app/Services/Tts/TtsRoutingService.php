<?php

namespace App\Services\Tts;

use App\Contracts\TtsProviderInterface;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Support\VoiceTier;
use Illuminate\Support\Facades\Log;

/**
 * TTS Routing Service.
 *
 * Determines which TTS provider makes a request, by its voice quality:
 * - Standard voices always use Google Cloud TTS (WaveNet, or Chirp 3 HD).
 * - HD voices always use Gemini, and never fall back to a standard voice:
 *   when Gemini is not set up or fails, the request fails (the generation's
 *   credits are given back) instead. The global mode and the plans' provider
 *   policies no longer send HD requests anywhere else.
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

        $result = $provider === $this->geminiProvider
            ? $this->synthesizePremium($text, $targetLanguage, $voiceConfig, $userId)
            : $provider->synthesize($text, $targetLanguage, $voiceConfig);

        // Add routing metadata (HD never falls back, so no request uses a backup engine).
        $result['fallback_used'] = false;
        $result['routing_mode'] = $mode;
        $result['plan_slug'] = $plan?->slug ?? 'free';

        // Log telemetry
        $this->logTelemetry($result, $userId);

        return $result;
    }

    /**
     * Resolve which provider to use. The voice quality decides it: the mode
     * and the plan's provider policy are kept as settings but no longer move
     * a request to another engine.
     */
    public function resolveProvider(string $mode, ?Plan $plan, array $voiceConfig = []): TtsProviderInterface
    {
        $requestedTier = VoiceTier::normalize((string) ($voiceConfig['tier'] ?? VoiceTier::STANDARD));

        // Business rule: Standard quality must always run on Google WaveNet
        // (never Gemini), regardless of plan override or hybrid mode.
        if ($requestedTier === VoiceTier::STANDARD) {
            return $this->googleProvider;
        }

        // Business rule: HD quality is always made by Gemini. A member who pays
        // for premium voices never gets a standard voice, whatever the mode, the
        // plan's policy or the voice's own provider says (synthesize() fails the
        // request when Gemini is not set up).
        return $this->geminiProvider;
    }

    /**
     * An HD request, made by Gemini or not at all: when Gemini is not set up or
     * fails, this throws, so the generation fails and its credits are given back.
     * The exception says whether the failure may pass within the minute, is a
     * limit on our side that lasts, or is the text itself, so the member is
     * told which.
     */
    private function synthesizePremium(string $text, string $targetLanguage, array $voiceConfig, ?int $userId): array
    {
        if (! $this->geminiProvider->isAvailable()) {
            throw new PremiumVoiceUnavailableException('The premium voice engine is not set up (no Gemini API key).', 0, null, PremiumVoiceUnavailableException::OUR_LIMIT);
        }

        try {
            return $this->geminiProvider->synthesize($text, $targetLanguage, $voiceConfig);
        } catch (\Throwable $e) {
            Log::warning('Premium voice failed; the request fails rather than use a standard voice.', [
                'error' => mb_substr($e->getMessage(), 0, 300),
                'user_id' => $userId,
            ]);

            throw new PremiumVoiceUnavailableException(
                'Premium voice failed: '.mb_substr((string) preg_replace('/\s+/u', ' ', $e->getMessage()), 0, 490),
                0,
                $e,
                $this->failureReason($e),
            );
        }
    }

    /**
     * The site's daily ceiling, or every key out (the day's quota, the spending
     * cap, refused keys), lasts; text the engine refused fails again as it is;
     * anything else may pass within the minute.
     */
    private function failureReason(\Throwable $e): string
    {
        return match (true) {
            $e instanceof GeminiTextRefusedException => PremiumVoiceUnavailableException::TEXT_REFUSED,
            $e instanceof TtsCostLimitException, $e instanceof GeminiKeysOutException => PremiumVoiceUnavailableException::OUR_LIMIT,
            default => PremiumVoiceUnavailableException::BUSY,
        };
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
