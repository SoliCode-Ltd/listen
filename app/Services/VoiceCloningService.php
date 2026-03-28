<?php

namespace App\Services;

use App\Models\ClonedVoice;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Voice Cloning Service.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ IMPLEMENTATION NOTE: Google API Flow Used                          │
 * ├─────────────────────────────────────────────────────────────────────┤
 * │ This service uses Google Cloud TTS Chirp 3: Instant Custom Voice   │
 * │ via the v1beta1 API. This is the ONLY officially supported path    │
 * │ for programmatic voice cloning on Google Cloud as of 2026.         │
 * │                                                                    │
 * │ - The old "Custom Voice" (studio-quality training) is CLOSED to    │
 * │   new customers.                                                   │
 * │ - Dialogflow CX voice cloning is for conversational agents, NOT    │
 * │   standalone TTS applications.                                     │
 * │ - Gemini 2.5 Flash TTS does NOT support custom/cloned voices.      │
 * │                                                                    │
 * │ Flow:                                                              │
 * │ 1. POST v1beta1/voices:generateVoiceCloningKey                     │
 * │    - Sends reference_audio + voice_talent_consent + consent_script │
 * │    - Returns a voice_cloning_key (string)                          │
 * │ 2. POST v1beta1/text:synthesize                                    │
 * │    - Uses voice.voice_clone.voice_cloning_key                      │
 * │    - Returns synthesized audio in the cloned voice                 │
 * │                                                                    │
 * │ IMPORTANT: Access to Instant Custom Voice requires the GCP project │
 * │ to be on Google's allow-list. Contact Google Cloud sales to enable.│
 * │                                                                    │
 * │ Rate limit: 10 voice cloning keys per minute per GCP project.      │
 * │ Supported regions: global, us, eu, asia-southeast1,                │
 * │                    asia-northeast1, europe-west2                    │
 * │ Supported audio: LINEAR16, PCM, MP3, M4A (single-channel)         │
 * │ Reference audio: up to ~10 seconds recommended                     │
 * └─────────────────────────────────────────────────────────────────────┘
 */
class VoiceCloningService
{
    /**
     * Consent scripts required by Google for voice cloning.
     * The voice owner must record themselves speaking this text.
     */
    public const CONSENT_SCRIPTS = [
        'en' => 'I, the owner of this voice, consent to the creation of a synthetic voice model based on my voice recording.',
        'ar' => 'أنا، صاحب هذا الصوت، أوافق على إنشاء نموذج صوت اصطناعي بناءً على تسجيلي الصوتي.',
    ];

    /**
     * Generate a voice cloning key from Google Cloud TTS Chirp 3.
     *
     * @param  ClonedVoice  $clonedVoice  The cloned voice record with stored audio
     * @return array{success: bool, voice_cloning_key?: string, error?: string}
     */
    public function generateVoiceCloningKey(ClonedVoice $clonedVoice): array
    {
        $region = $this->getRegion();
        $endpoint = $this->buildEndpoint($region, 'voices:generateVoiceCloningKey');

        try {
            $referenceAudioBytes = $this->loadAudioBytes($clonedVoice->source_audio_path);
            $consentAudioBytes = $this->loadConsentAudioBytes($clonedVoice);

            $languageCode = $clonedVoice->language_code ?: 'ar-XA';
            $consentLang = str_starts_with($languageCode, 'ar') ? 'ar' : 'en';
            $consentScript = self::CONSENT_SCRIPTS[$consentLang] ?? self::CONSENT_SCRIPTS['en'];

            $audioEncoding = $this->detectAudioEncoding($clonedVoice->source_audio_path);

            $payload = [
                'reference_audio' => [
                    'audio_config' => ['audio_encoding' => $audioEncoding],
                    'content' => base64_encode($referenceAudioBytes),
                ],
                'voice_talent_consent' => [
                    'audio_config' => ['audio_encoding' => $audioEncoding],
                    'content' => base64_encode($consentAudioBytes),
                ],
                'consent_script' => $consentScript,
                'language_code' => $languageCode,
            ];

            // Instant Custom Voice requires OAuth with x-goog-user-project.
            $response = $this->makeAuthenticatedRequest($endpoint, $payload, 30, true);

            // Some regional TTS hosts may not expose this method.
            // Retry once on global before treating it as a hard failure.
            if ($region !== 'global' && $this->isMethodNotFound($response)) {
                $globalEndpoint = $this->buildEndpoint('global', 'voices:generateVoiceCloningKey');
                Log::warning('Voice cloning key endpoint not found on regional host; retrying global endpoint.', [
                    'cloned_voice_id' => $clonedVoice->id,
                    'region' => $region,
                ]);
                $response = $this->makeAuthenticatedRequest($globalEndpoint, $payload, 30, true);
            }

            if ($response->successful() && $response->json('voiceCloningKey')) {
                return [
                    'success' => true,
                    'voice_cloning_key' => $response->json('voiceCloningKey'),
                ];
            }

            $errorMessage = $response->json('error.message') ?? $response->body();
            if ($this->isMethodNotFound($response)) {
                $errorMessage = 'Voice cloning is not enabled for the Google Cloud project behind your current TTS API key. No new API key is required; enable/allow-list Instant Custom Voice for this same project.';
            }
            Log::error('Voice cloning key generation failed.', [
                'cloned_voice_id' => $clonedVoice->id,
                'status' => $response->status(),
                'error' => $errorMessage,
                'region' => $region,
                'endpoint' => $endpoint,
            ]);

            return [
                'success' => false,
                'error' => $errorMessage,
            ];
        } catch (\Throwable $e) {
            Log::error('Voice cloning key generation exception.', [
                'cloned_voice_id' => $clonedVoice->id,
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Synthesize text using a cloned voice.
     *
     * This is used by the TTS pipeline when a user selects their cloned voice.
     * Goes through Google Cloud TTS v1beta1 with voice_clone parameter.
     *
     * @param  string  $text          Text to synthesize
     * @param  string  $languageCode  Language code (e.g. ar-XA, en-US)
     * @param  string  $voiceCloningKey  The stored voice cloning key
     * @return array{audio_content: string, audio_path: string, provider: string, model: string, latency_ms: int, cost_estimate_usd: float, char_count: int}
     */
    public function synthesizeWithClonedVoice(
        string $text,
        string $languageCode,
        string $voiceCloningKey,
    ): array {
        $region = $this->getRegion();
        $endpoint = $this->buildEndpoint($region, 'text:synthesize');
        $startTime = microtime(true);

        $payload = [
            'input' => ['text' => mb_substr($text, 0, 5000)],
            'voice' => [
                'language_code' => $languageCode,
                'voice_clone' => [
                    'voice_cloning_key' => $voiceCloningKey,
                ],
            ],
            'audioConfig' => [
                'audioEncoding' => 'MP3',
                'sampleRateHertz' => 24000,
            ],
        ];

        // Keep cloned-voice synthesis on OAuth as well for consistent project billing and access.
        $response = $this->makeAuthenticatedRequest($endpoint, $payload, 60, true);

        if (! $response->successful()) {
            $errorMsg = $response->json('error.message') ?? 'Voice cloning synthesis failed.';
            throw new \RuntimeException("Cloned voice synthesis failed: {$errorMsg}");
        }

        $audioContent = base64_decode($response->json('audioContent'));
        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        // Store the synthesized audio
        $filename = 'audio/cloned/' . now()->format('Ymd') . '/' . \Illuminate\Support\Str::uuid() . '.mp3';
        Storage::disk('public')->put($filename, $audioContent);

        return [
            'audio_content' => $audioContent,
            'audio_path' => $filename,
            'provider' => 'google_cloud_chirp3',
            'model' => 'chirp3-instant-custom-voice',
            'latency_ms' => $latencyMs,
            'cost_estimate_usd' => $this->estimateCost(mb_strlen($text)),
            'char_count' => mb_strlen($text),
            'duration_seconds' => null,
        ];
    }

    /**
     * Check if voice cloning is enabled globally.
     */
    public static function isEnabled(): bool
    {
        return (string) SiteSetting::get('voice_cloning_enabled', '1') === '1';
    }

    /**
     * Get the maximum number of cloned voices per user (global fallback).
     */
    public static function maxVoicesPerUser(): int
    {
        return (int) SiteSetting::get('voice_cloning_max_per_user', 5);
    }

    /**
     * Get the maximum number of cloned voices for a specific plan.
     *
     * Uses the exact per-plan value when present (including 0).
     * Falls back to the global SiteSetting limit only when the plan value is null.
     */
    public static function maxVoicesForPlan(?\App\Models\Plan $plan): int
    {
        if ($plan && $plan->voice_cloning_max_voices !== null) {
            return max(0, (int) $plan->voice_cloning_max_voices);
        }

        return self::maxVoicesPerUser();
    }

    /**
     * Get the minimum required sample duration in seconds.
     */
    public static function minSampleDuration(): int
    {
        return (int) SiteSetting::get('voice_cloning_min_duration', 10);
    }

    /**
     * Get the maximum upload size in MB.
     */
    public static function maxUploadSizeMb(): int
    {
        return (int) SiteSetting::get('voice_cloning_max_upload_mb', 25);
    }

    /**
     * Get allowed audio MIME types.
     */
    public static function allowedMimeTypes(): array
    {
        return [
            'audio/wav',
            'audio/x-wav',
            'audio/wave',
            'audio/mpeg',
            'audio/mp3',
            'audio/mp4',
            'audio/x-m4a',
            'audio/m4a',
            'audio/ogg',
            'audio/webm',
            'audio/flac',
            'video/mp4',        // Browsers may report .mp4 files as video/mp4
            'application/mp4',  // Some systems report .mp4 as application/mp4
            'audio/3gpp',       // Messaging apps may export audio as 3GPP containers
            'video/3gpp',       // Some detectors report 3GPP as video/*
            'audio/3gpp2',
            'video/3gpp2',
        ];
    }

    /**
     * Get allowed file extensions.
     */
    public static function allowedExtensions(): array
    {
        return ['wav', 'mp3', 'mp4', 'm4a', 'ogg', 'webm', 'flac'];
    }

    /* ------------------------------------------------------------------ */
    /*  Private helpers                                                    */
    /* ------------------------------------------------------------------ */

    private function getRegion(): string
    {
        return (string) SiteSetting::get('voice_cloning_region', 'global');
    }

    private function buildEndpoint(string $region, string $path): string
    {
        $host = $region === 'global'
            ? 'https://texttospeech.googleapis.com'
            : "https://{$region}-texttospeech.googleapis.com";

        return "{$host}/v1beta1/{$path}";
    }

    private function makeAuthenticatedRequest(string $url, array $payload, int $timeout = 30, bool $forceOAuth = false): \Illuminate\Http\Client\Response
    {
        $apiKey = trim((string) config('services.google_tts.api_key', ''));
        $verify = (bool) config('services.google_tts.verify_ssl', true);

        if (! $forceOAuth && $apiKey !== '') {
            return $this->makeApiKeyRequest($url, $payload, $timeout, $apiKey, $verify);
        }

        try {
            // Preferred path for Instant Custom Voice: OAuth2 access token
            $accessToken = $this->getAccessToken();
            $projectId = trim((string) config('services.google_tts.project_id', ''));

            $request = Http::timeout($timeout)
                ->withToken($accessToken)
                ->withOptions(['verify' => $verify]);

            if ($projectId !== '') {
                $request = $request->withHeaders([
                    'x-goog-user-project' => $projectId,
                ]);
            }

            return $request->post($url, $payload);
        } catch (\RuntimeException $e) {
            // Fallback for environments configured with API key only.
            if ($apiKey !== '') {
                Log::warning('Voice cloning OAuth configuration unavailable, falling back to API key.', [
                    'reason' => $e->getMessage(),
                ]);

                return $this->makeApiKeyRequest($url, $payload, $timeout, $apiKey, $verify);
            }

            throw $e;
        }
    }

    private function makeApiKeyRequest(string $url, array $payload, int $timeout, string $apiKey, bool $verify): \Illuminate\Http\Client\Response
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return Http::timeout($timeout)
            ->withOptions(['verify' => $verify])
            ->post($url . $separator . 'key=' . $apiKey, $payload);
    }

    private function getAccessToken(): string
    {
        $credentialsPath = config('services.google_tts.credentials_path');
        $credentialsJson = trim((string) config('services.google_tts.credentials_json', ''));

        if ($credentialsPath && file_exists($credentialsPath)) {
            $credentialsRaw = file_get_contents($credentialsPath);
        } elseif ($credentialsJson !== '') {
            $credentialsRaw = $credentialsJson;
        } else {
            throw new \RuntimeException('Google Cloud credentials are missing for voice cloning. Set SPEECH_TTS_CREDENTIALS_PATH or SPEECH_TTS_CREDENTIALS_JSON.');
        }

        $credentials = json_decode((string) $credentialsRaw, true);
        if (! is_array($credentials) || ! isset($credentials['client_email'], $credentials['private_key'])) {
            throw new \RuntimeException('Invalid Google Cloud service account credentials for voice cloning.');
        }

        $now = time();
        $header = $this->base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = $this->base64UrlEncode((string) json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/cloud-platform',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        openssl_sign("{$header}.{$claim}", $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);
        $jwt = "{$header}.{$claim}." . $this->base64UrlEncode($signature);

        $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if (! $tokenResponse->successful() || ! filled($tokenResponse->json('access_token'))) {
            $errorMessage = $tokenResponse->json('error_description')
                ?? $tokenResponse->json('error')
                ?? $tokenResponse->body();
            throw new \RuntimeException('Failed to obtain Google OAuth access token: ' . $errorMessage);
        }

        return (string) $tokenResponse->json('access_token');
    }

    private function loadAudioBytes(string $path): string
    {
        if (Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->get($path);
        }

        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->get($path);
        }

        throw new \RuntimeException("Audio file not found: {$path}");
    }

    private function loadConsentAudioBytes(ClonedVoice $clonedVoice): string
    {
        // If a separate consent audio was recorded, use that
        if (filled($clonedVoice->consent_audio_path)) {
            return $this->loadAudioBytes($clonedVoice->consent_audio_path);
        }

        // Otherwise, use the reference audio as consent audio too.
        // This is valid when the user reads the consent script as their
        // reference sample (simplified flow).
        return $this->loadAudioBytes($clonedVoice->source_audio_path);
    }

    private function detectAudioEncoding(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'wav' => 'LINEAR16',
            'mp3' => 'MP3',
            'mp4', 'm4a' => 'M4A',
            'ogg', 'webm' => 'OGG_OPUS',
            'flac' => 'FLAC',
            default => 'LINEAR16',
        };
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function isMethodNotFound(\Illuminate\Http\Client\Response $response): bool
    {
        if ($response->status() !== 404) {
            return false;
        }

        $message = strtolower((string) ($response->json('error.message') ?? $response->body()));

        return str_contains($message, 'method not found');
    }

    private function estimateCost(int $charCount): float
    {
        // Google Cloud TTS Chirp 3 pricing: ~$0.000016 per character
        return round($charCount * 0.000016, 6);
    }
}
