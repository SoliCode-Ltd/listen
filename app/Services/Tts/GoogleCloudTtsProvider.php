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
    /** Google rejects input.text over 5,000 bytes; Arabic is ~2 bytes per letter. */
    public const MAX_INPUT_BYTES = 4800;

    /** Google rejects a request when one sentence is too long ("sentences that are too long"). */
    public const MAX_SENTENCE_CHARS = 250;

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
                'text' => '',
            ],
            'voice' => [
                'languageCode' => $languageCode,
                'name' => $voiceName,
                'ssmlGender' => $gender,
            ],
            'audioConfig' => $audioConfig,
        ];

        // MP3 frames can be joined end to end; other encodings carry a header
        // per file, so they keep the single-request behaviour.
        $text = self::breakLongSentences($text);
        $chunks = $audioConfig['audioEncoding'] === 'MP3'
            ? self::splitForByteLimit($text, self::MAX_INPUT_BYTES)
            : [mb_substr($text, 0, 5000)];

        $binary = '';
        foreach ($chunks as $chunk) {
            $payload['input']['text'] = $chunk;
            $binary .= $this->requestAudio($payload, $endpoint, $timeout, $verifySsl);
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

    /**
     * End any sentence longer than the limit, preferring the last comma
     * before the limit, otherwise the last space.
     */
    public static function breakLongSentences(string $text, int $maxChars = self::MAX_SENTENCE_CHARS): string
    {
        $lines = preg_split('/\R/u', $text) ?: [$text];

        foreach ($lines as $i => $line) {
            $words = preg_split('/\s+/u', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $out = [];
            $sentenceStart = 0;
            $sentenceChars = 0;

            foreach ($words as $word) {
                $wordChars = mb_strlen($word) + 1;
                if ($sentenceChars + $wordChars > $maxChars && count($out) > $sentenceStart) {
                    $breakAt = count($out) - 1;
                    for ($j = count($out) - 1; $j > $sentenceStart; $j--) {
                        if (preg_match('/[،,؛;:]$/u', $out[$j])) {
                            $breakAt = $j;
                            break;
                        }
                    }
                    $out[$breakAt] = preg_replace('/[،,؛;:]$/u', '', $out[$breakAt]).'.';
                    $sentenceStart = $breakAt + 1;
                    $sentenceChars = 0;
                    for ($j = $sentenceStart; $j < count($out); $j++) {
                        $sentenceChars += mb_strlen($out[$j]) + 1;
                    }
                }

                $out[] = $word;
                $sentenceChars += $wordChars;
                if (preg_match('/[.!?؟…]["»”)\]]*$/u', $word)) {
                    $sentenceStart = count($out);
                    $sentenceChars = 0;
                }
            }

            $lines[$i] = implode(' ', $out);
        }

        return trim(implode("\n", $lines));
    }

    /**
     * Split text into pieces under the API's per-request byte limit,
     * breaking at sentence ends, then at spaces, then mid-word as a last resort.
     *
     * @return array<int, string>
     */
    public static function splitForByteLimit(string $text, int $maxBytes = self::MAX_INPUT_BYTES): array
    {
        $text = trim($text);
        if (strlen($text) <= $maxBytes) {
            return [$text];
        }

        $pieces = [];
        foreach (preg_split('/(?<=[.!?؟؛…\n])\s+/u', $text) ?: [$text] as $sentence) {
            if (strlen($sentence) <= $maxBytes) {
                $pieces[] = $sentence;
                continue;
            }
            foreach (preg_split('/\s+/u', $sentence) ?: [$sentence] as $word) {
                while (strlen($word) > $maxBytes) {
                    $head = mb_strcut($word, 0, $maxBytes, 'UTF-8');
                    $pieces[] = $head;
                    $word = substr($word, strlen($head));
                }
                if ($word !== '') {
                    $pieces[] = $word;
                }
            }
        }

        $chunks = [];
        $current = '';
        foreach ($pieces as $piece) {
            $candidate = $current === '' ? $piece : $current.' '.$piece;
            if (strlen($candidate) <= $maxBytes) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $chunks[] = $current;
            }
            $current = $piece;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function requestAudio(array $payload, string $endpoint, int $timeout, bool $verifySsl): string
    {
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

        return $binary;
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
        $normalizedTier = VoiceTier::normalize($tier);
        $lookupTier = $this->effectiveGoogleTier($tier);

        $voice = VoiceCatalog::defaultForLanguageByProvider($targetLanguage, 'google_cloud', $lookupTier, $gender);
        if ($voice !== '') {
            $resolved = VoiceCatalog::providerVoiceIdForName($voice) ?: $voice;

            return $normalizedTier === VoiceTier::STANDARD
                ? $this->coerceToWaveNetVoice($resolved, $targetLanguage, $gender)
                : $resolved;
        }

        // Final fallback: any Google voice for language.
        $voice = VoiceCatalog::defaultForLanguageByProvider($targetLanguage, 'google_cloud', null, $gender);
        if ($voice !== '') {
            $resolved = VoiceCatalog::providerVoiceIdForName($voice) ?: $voice;

            return $normalizedTier === VoiceTier::STANDARD
                ? $this->coerceToWaveNetVoice($resolved, $targetLanguage, $gender)
                : $resolved;
        }

        // Let Google auto-pick for non-standard tiers. Standard must stay WaveNet.
        return $normalizedTier === VoiceTier::STANDARD
            ? $this->coerceToWaveNetVoice('', $targetLanguage, $gender)
            : '';
    }

    private function effectiveGoogleTier(string $tier): string
    {
        return VoiceTier::normalize($tier);
    }

    private function enforceWaveNetForStandard(string $voiceName, string $targetLanguage, string $tier, string $gender): string
    {
        if (VoiceTier::normalize($tier) !== VoiceTier::STANDARD) {
            return $voiceName;
        }

        return $this->coerceToWaveNetVoice($voiceName, $targetLanguage, $gender);
    }

    private function coerceToWaveNetVoice(string $voiceName, string $targetLanguage, string $gender): string
    {
        $trimmed = trim($voiceName);
        if ($trimmed !== '' && str_contains(strtolower($trimmed), '-wavenet-')) {
            return $trimmed;
        }

        if ($trimmed !== '') {
            $converted = preg_replace('/-standard-/i', '-Wavenet-', $trimmed);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        $standardVoice = VoiceCatalog::defaultForLanguageByProvider(
            $targetLanguage,
            'google_cloud',
            VoiceTier::STANDARD,
            $gender
        );

        if ($standardVoice !== '') {
            $resolvedStandardVoice = VoiceCatalog::providerVoiceIdForName($standardVoice) ?: $standardVoice;
            $resolvedStandardVoice = trim($resolvedStandardVoice);

            if ($resolvedStandardVoice !== '') {
                if (str_contains(strtolower($resolvedStandardVoice), '-wavenet-')) {
                    return $resolvedStandardVoice;
                }

                $convertedStandardVoice = preg_replace('/-standard-/i', '-Wavenet-', $resolvedStandardVoice);
                if (is_string($convertedStandardVoice) && $convertedStandardVoice !== '') {
                    return $convertedStandardVoice;
                }
            }
        }

        // Stable fallback IDs so standard quality remains explicitly WaveNet.
        return strtolower($targetLanguage) === 'ar'
            ? 'ar-XA-Wavenet-A'
            : 'en-US-Wavenet-C';
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
