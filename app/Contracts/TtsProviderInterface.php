<?php

namespace App\Contracts;

/**
 * Contract for all TTS (Text-to-Speech) providers.
 *
 * Each provider must implement synthesize() and return a standardized result array.
 * Providers are swappable and selected by the TtsRoutingService based on
 * the user's plan, global mode, and fallback policy.
 */
interface TtsProviderInterface
{
    /**
     * Unique slug for this provider (e.g. 'google_cloud', 'gemini').
     */
    public function name(): string;

    /**
     * Synthesize text into audio.
     *
     * @param  string  $text           The text to speak.
     * @param  string  $targetLanguage Language code (e.g. 'ar', 'en').
     * @param  array   $voiceConfig    Provider-specific voice configuration:
     *                                  - voice_name: string (provider voice ID)
     *                                  - gender: string (MALE|FEMALE|NEUTRAL)
     *                                  - speed: float (0.25–4.0, default 1.0)
     *                                  - pitch: float (-20.0–20.0, default 0.0)
     *                                  - style: string|null (emotion/style hint)
     *                                  - dialect: string|null (country/dialect code)
     *                                  - speakers: array|null (for dialogue mode)
     * @return array{
     *     audio_path: string,
     *     duration_seconds: int|null,
     *     provider: string,
     *     model: string|null,
     *     latency_ms: int|null,
     *     char_count: int,
     *     cost_estimate_usd: float|null,
     * }
     *
     * @throws \RuntimeException on unrecoverable failure
     */
    public function synthesize(
        string $text,
        string $targetLanguage,
        array $voiceConfig = [],
    ): array;

    /**
     * Whether this provider supports dialogue/multi-speaker mode.
     */
    public function supportsDialogue(): bool;

    /**
     * Whether this provider is currently available (credentials configured, etc.).
     */
    public function isAvailable(): bool;
}
