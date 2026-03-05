<?php

namespace App\Services\Tts;

use App\Contracts\TtsProviderInterface;
use App\Support\VoiceCatalog;
use App\Support\VoiceTier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Google Cloud TTS Provider.
 *
 * Wraps the existing Google Cloud Text-to-Speech API integration.
 * Preserves all existing behavior from the original TtsService.
 */
class GoogleCloudTtsProvider implements TtsProviderInterface
{
    public function name(): string
    {
        return 'google_cloud';
    }

    public function supportsDialogue(): bool
    {
        return false;
    }

    public function isAvailable(): bool
    {
        $apiKey = trim((string) config('services.google_tts.api_key', ''));
        if ($apiKey !== '') {
            return true;
        }

        try {
            $credentials = $this->loadGoogleCredentials();
            return !empty($credentials['client_email']) && !empty($credentials['private_key']);
        } catch (\Throwable) {
            return false;
        }
    }

    public function synthesize(string $text, string $targetLanguage, array $voiceConfig = []): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('TTS text is empty.');
        }

        $startTime = microtime(true);

        $voiceName = $this->resolveGoogleVoiceName(
            (string) ($voiceConfig['voice_name'] ?? ''),
            $targetLanguage,
            (string) ($voiceConfig['tier'] ?? 'standard'),
            (string) ($voiceConfig['gender'] ?? ''),
        );
        $speed = (float) ($voiceConfig['speed'] ?? config('services.google_tts.speaking_rate', 1.0));
        $pitch = (float) ($voiceConfig['pitch'] ?? config('services.google_tts.pitch', 0.0));

        $endpoint = (string) config('services.google_tts.endpoint');
        $timeout = (int) config('services.google_tts.timeout_seconds', 30);
        $verifySsl = (bool) config('services.google_tts.verify_ssl', true);

        $languageCode = $this->resolveLanguageCode($targetLanguage);
        $gender = strtoupper($voiceConfig['gender'] ?? $this->resolveSsmlGender($voiceName));

        // Chirp voices (chirp, chirp3, chirp2) don't support pitch parameters
        $supportsProsodicParams = !$this->isChirpVoice($voiceName);
        $audioConfig = [
            'audioEncoding' => strtoupper((string) config('services.google_tts.audio_encoding', 'MP3')),
            'speakingRate' => max(0.25, min(4.0, $speed)),
        ];
        if ($supportsProsodicParams) {
            $audioConfig['pitch'] = max(-20.0, min(20.0, $pitch));
        }

        $payload = [
            'input' => [
                'text' => mb_substr($text, 0, 5000),
            ],
            'voice' => [
                'languageCode' => $languageCode,
                'name' => $voiceName,
                'ssmlGender' => $gender,
            ],
            'audioConfig' => $audioConfig,
        ];

        $apiKey = trim((string) config('services.google_tts.api_key', ''));
        if ($apiKey !== '') {
            $separator = str_contains($endpoint, '?') ? '&' : '?';
            $response = $this->sendWithSslFallback(
                fn(bool $verify) => Http::timeout($timeout)
                    ->withOptions(['verify' => $verify])
                    ->post($endpoint . $separator . 'key=' . $apiKey, $payload),
                $verifySsl,
                'Speech API request',
            );
        } else {
            $response = $this->sendWithSslFallback(
                fn(bool $verify) => Http::timeout($timeout)
                    ->withOptions(['verify' => $verify])
                    ->withToken($this->getGoogleAccessToken())
                    ->post($endpoint, $payload),
                $verifySsl,
                'Speech OAuth request',
            );
        }

        if (!$response->ok()) {
            throw new \RuntimeException('Speech request failed: ' . $response->body());
        }

        $json = $response->json();
        $audioContent = is_array($json) ? ($json['audioContent'] ?? null) : null;
        if (!is_string($audioContent) || $audioContent === '') {
            throw new \RuntimeException('Speech service returned empty audio payload.');
        }

        $binary = base64_decode($audioContent, true);
        if ($binary === false || $binary === '') {
            throw new \RuntimeException('Failed to decode speech audio payload.');
        }

        $filename = 'audio/' . now()->format('Ymd_His') . '_' . Str::random(12) . '.mp3';
        $disk = Storage::disk('public');

        if (!$disk->exists('audio')) {
            $disk->makeDirectory('audio');
        }

        $disk->put($filename, $binary);

        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
        $charCount = mb_strlen($text);

        return [
            'audio_path' => $filename,
            'duration_seconds' => $this->estimateDurationSeconds($text),
            'provider' => $this->name(),
            'model' => 'google-cloud-tts',
            'latency_ms' => $latencyMs,
            'char_count' => $charCount,
            'cost_estimate_usd' => $this->estimateCost($charCount, $voiceName),
        ];
    }

    // ──────────────────────────────────────────────────────────
    //  Internal helpers (preserved from original TtsService)
    // ──────────────────────────────────────────────────────────

    private function resolveLanguageCode(string $targetLanguage): string
    {
        return match (strtolower($targetLanguage)) {
            'ar' => 'ar-XA',
            'en' => 'en-US',
            default => $targetLanguage,
        };
    }

    /**
     * Ensure we always send a Google-compatible voice ID.
     *
     * Voice names selected in the UI are internal IDs and may belong to Gemini.
     * In that case, we fallback to a Google Cloud voice matching language/tier/gender.
     */
    private function resolveGoogleVoiceName(string $voiceName, string $targetLanguage, string $tier, string $gender): string
    {
        $voiceName = trim($voiceName);
        $targetLanguage = strtolower(trim($targetLanguage));
        $baseLanguage = str_starts_with($targetLanguage, 'ar') ? 'ar'
            : (str_starts_with($targetLanguage, 'en') ? 'en' : $targetLanguage);
        $tier = VoiceTier::normalize($tier);
        $gender = strtolower(trim($gender));

        if ($voiceName !== '') {
            // Internal catalog voice ID selected in UI.
            $provider = VoiceCatalog::providerForName($voiceName);
            if ($provider !== null) {
                if ($provider === 'google_cloud') {
                    $resolvedVoice = VoiceCatalog::providerVoiceIdForName($voiceName) ?: $voiceName;

                    return $this->enforceWaveNetForStandard($resolvedVoice, $baseLanguage, $tier, $gender);
                }

                // Selected voice belongs to another provider (Gemini, etc.).
                return $this->fallbackGoogleVoice($baseLanguage, $tier, $gender);
            }

            // Raw Google voice ID passed directly.
            if (str_contains(strtolower($voiceName), '-standard-')
                || str_contains(strtolower($voiceName), '-wavenet-')
                || str_contains(strtolower($voiceName), '-neural2-')
                || str_contains(strtolower($voiceName), '-chirp3-')
            ) {
                return $this->enforceWaveNetForStandard($voiceName, $baseLanguage, $tier, $gender);
            }
        }

        return $this->fallbackGoogleVoice($baseLanguage, $tier, $gender);
    }

    private function fallbackGoogleVoice(string $targetLanguage, string $tier, string $gender): string
    {
        $lookupTier = $this->effectiveGoogleTier($tier);

        $voice = VoiceCatalog::defaultForLanguageByProvider($targetLanguage, 'google_cloud', $lookupTier, $gender);
        if ($voice !== '') {
            return VoiceCatalog::providerVoiceIdForName($voice) ?: $voice;
        }

        // Final fallback: any Google voice for language.
        $voice = VoiceCatalog::defaultForLanguageByProvider($targetLanguage, 'google_cloud', null, $gender);
        if ($voice !== '') {
            return VoiceCatalog::providerVoiceIdForName($voice) ?: $voice;
        }

        // Let Google auto-pick if catalog is empty.
        return '';
    }

    private function effectiveGoogleTier(string $tier): string
    {
        $normalizedTier = VoiceTier::normalize($tier);

        // Standard plan quality must still run through WaveNet for consistency.
        return $normalizedTier === VoiceTier::STANDARD ? VoiceTier::HD : $normalizedTier;
    }

    private function enforceWaveNetForStandard(string $voiceName, string $targetLanguage, string $tier, string $gender): string
    {
        $normalizedTier = VoiceTier::normalize($tier);
        if ($normalizedTier !== VoiceTier::STANDARD) {
            return $voiceName;
        }

        $trimmed = trim($voiceName);
        if ($trimmed === '') {
            return $this->fallbackGoogleVoice($targetLanguage, VoiceTier::HD, $gender);
        }

        if (str_contains(strtolower($trimmed), '-wavenet-')) {
            return $trimmed;
        }

        $converted = preg_replace('/-standard-/i', '-Wavenet-', $trimmed);
        if (is_string($converted) && $converted !== '' && $converted !== $trimmed) {
            return $converted;
        }

        $fallback = $this->fallbackGoogleVoice($targetLanguage, VoiceTier::HD, $gender);

        return $fallback !== '' ? $fallback : $trimmed;
    }

    private function resolveSsmlGender(string $voiceName): string
    {
        $meta = VoiceCatalog::voiceMeta($voiceName);
        $gender = strtolower((string) ($meta['gender'] ?? ''));

        return match ($gender) {
            'male' => 'MALE',
            'female' => 'FEMALE',
            default => 'NEUTRAL',
        };
    }

    /**
     * Check if the voice is a Chirp variant (Chirp, Chirp2, Chirp3).
     * Chirp voices don't support pitch parameters in the API.
     */
    private function isChirpVoice(string $voiceName): bool
    {
        $voiceNameLower = strtolower(trim($voiceName));

        return str_contains($voiceNameLower, '-chirp')
            || str_contains($voiceNameLower, 'chirp-')
            || preg_match('/chirp[0-3]?$/i', $voiceNameLower);
    }

    private function estimateDurationSeconds(string $text): int
    {
        $wordEstimate = max(
            str_word_count(strip_tags($text)),
            (int) ceil(mb_strlen($text) / 5)
        );

        return max(3, (int) ceil($wordEstimate / 2.6));
    }

    private function estimateCost(int $charCount, string $voiceName): float
    {
        // Google Cloud TTS pricing: Standard=$4/1M chars, WaveNet=$16/1M chars, Neural2=$16/1M
        $isWaveNet = str_contains(strtolower($voiceName), '-wavenet-');
        $isNeural = str_contains(strtolower($voiceName), '-neural2-');
        $ratePerMillion = ($isWaveNet || $isNeural) ? 16.0 : 4.0;

        return round(($charCount / 1_000_000) * $ratePerMillion, 6);
    }

    private function getGoogleAccessToken(): string
    {
        $credentials = $this->loadGoogleCredentials();
        $clientEmail = (string) ($credentials['client_email'] ?? '');
        $privateKey = (string) ($credentials['private_key'] ?? '');

        if ($clientEmail === '' || $privateKey === '') {
            throw new \RuntimeException('Missing speech service credentials.');
        }

        $cacheKey = 'listen:google_tts_token:' . sha1($clientEmail);

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($clientEmail, $privateKey) {
            $tokenUri = (string) config('services.google_tts.token_uri', 'https://oauth2.googleapis.com/token');
            $verifySsl = (bool) config('services.google_tts.verify_ssl', true);
            $now = time();

            $header = $this->base64UrlEncode(json_encode([
                'alg' => 'RS256',
                'typ' => 'JWT',
            ], JSON_THROW_ON_ERROR));

            $claims = $this->base64UrlEncode(json_encode([
                'iss' => $clientEmail,
                'scope' => 'https://www.googleapis.com/auth/cloud-platform',
                'aud' => $tokenUri,
                'exp' => $now + 3600,
                'iat' => $now,
            ], JSON_THROW_ON_ERROR));

            $unsigned = $header . '.' . $claims;
            $signature = '';
            $ok = openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
            if (!$ok) {
                throw new \RuntimeException('Unable to sign OAuth JWT assertion.');
            }

            $assertion = $unsigned . '.' . $this->base64UrlEncode($signature);

            $response = $this->sendWithSslFallback(
                fn(bool $verify) => Http::asForm()
                    ->timeout(15)
                    ->withOptions(['verify' => $verify])
                    ->post($tokenUri, [
                        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                        'assertion' => $assertion,
                    ]),
                $verifySsl,
                'OAuth token request',
            );

            if (!$response->ok()) {
                throw new \RuntimeException('Failed fetching OAuth token: ' . $response->body());
            }

            $token = $response->json('access_token');
            if (!is_string($token) || $token === '') {
                throw new \RuntimeException('OAuth token response missing access_token.');
            }

            return $token;
        });
    }

    private function loadGoogleCredentials(): array
    {
        $json = trim((string) config('services.google_tts.credentials_json', ''));
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $path = trim((string) config('services.google_tts.credentials_path', ''));
        if ($path !== '' && is_file($path)) {
            $contents = file_get_contents($path);
            $decoded = is_string($contents) ? json_decode($contents, true) : null;
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new \RuntimeException('Speech service credentials are not configured.');
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function sendWithSslFallback(callable $sender, bool $verifySsl, string $context)
    {
        try {
            return $sender($verifySsl);
        } catch (\Throwable $e) {
            if (!$this->shouldRetryWithoutSsl($e, $verifySsl)) {
                throw $e;
            }

            Log::warning($context . ' failed SSL verification on local environment; retrying once without verification.');

            return $sender(false);
        }
    }

    private function shouldRetryWithoutSsl(\Throwable $e, bool $verifySsl): bool
    {
        if (!$verifySsl || !app()->environment('local')) {
            return false;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'curl error 60')
            || str_contains($message, 'ssl certificate problem')
            || str_contains($message, 'unable to get local issuer certificate');
    }
}
