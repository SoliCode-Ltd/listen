<?php

namespace App\Services;

use App\Support\VoiceCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TtsService
{
    /**
     * @return array{audio_path:string,duration_seconds:int|null,provider:string}
     */
    public function synthesize(
        string $text,
        string $targetLanguage,
        ?string $voiceName = null,
        bool $preferFast = false,
    ): array {
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('TTS text is empty.');
        }

        $mode = $preferFast ? 'fast' : 'quality';
        $voice = $this->resolveVoiceName($targetLanguage, $voiceName);
        $hash = sha1($mode.'|'.$targetLanguage.'|'.$voice.'|'.$text);

        if ($cached = $this->findCachedAudio($hash, $targetLanguage)) {
            return [
                'audio_path' => $cached,
                'duration_seconds' => $this->estimateDurationSeconds($text),
                'provider' => 'cache',
            ];
        }

        $generated = $this->synthesizeWithGoogleCloud($text, $targetLanguage, $voice);
        $cachedPath = $this->storeInCache($generated['audio_path'], $hash, $targetLanguage);

        return [
            'audio_path' => $cachedPath,
            'duration_seconds' => $generated['duration_seconds'] ?? $this->estimateDurationSeconds($text),
            'provider' => $generated['provider'] ?? 'google-cloud-tts',
        ];
    }

    private function synthesizeWithGoogleCloud(string $text, string $targetLanguage, string $voice): array
    {
        $endpoint = (string) config('services.google_tts.endpoint');
        $timeout = (int) config('services.google_tts.timeout_seconds', 30);
        $verifySsl = (bool) config('services.google_tts.verify_ssl', true);

        $payload = [
            'input' => [
                'text' => mb_substr($text, 0, 5000),
            ],
            'voice' => [
                'languageCode' => $targetLanguage === 'ar' ? 'ar-XA' : 'en-US',
                'name' => $voice,
                'ssmlGender' => $this->resolveSsmlGender($voice),
            ],
            'audioConfig' => [
                'audioEncoding' => strtoupper((string) config('services.google_tts.audio_encoding', 'MP3')),
                'speakingRate' => (float) config('services.google_tts.speaking_rate', 1.0),
                'pitch' => (float) config('services.google_tts.pitch', 0.0),
            ],
        ];

        $apiKey = trim((string) config('services.google_tts.api_key', ''));
        if ($apiKey !== '') {
            $separator = str_contains($endpoint, '?') ? '&' : '?';
            $response = $this->sendWithSslFallback(
                fn (bool $verify) => Http::timeout($timeout)
                    ->withOptions(['verify' => $verify])
                    ->post($endpoint.$separator.'key='.$apiKey, $payload),
                $verifySsl,
                'Google TTS API request',
            );
        } else {
            $response = $this->sendWithSslFallback(
                fn (bool $verify) => Http::timeout($timeout)
                    ->withOptions(['verify' => $verify])
                    ->withToken($this->getGoogleAccessToken())
                    ->post($endpoint, $payload),
                $verifySsl,
                'Google TTS OAuth request',
            );
        }

        if (! $response->ok()) {
            throw new \RuntimeException('Google TTS request failed: '.$response->body());
        }

        $json = $response->json();
        $audioContent = is_array($json) ? ($json['audioContent'] ?? null) : null;
        if (! is_string($audioContent) || $audioContent === '') {
            throw new \RuntimeException('Google TTS returned empty audio payload.');
        }

        $binary = base64_decode($audioContent, true);
        if ($binary === false || $binary === '') {
            throw new \RuntimeException('Failed to decode Google TTS audio payload.');
        }

        $filename = 'audio/'.now()->format('Ymd_His').'_'.Str::random(12).'.mp3';
        $disk = Storage::disk('public');

        if (! $disk->exists('audio')) {
            $disk->makeDirectory('audio');
        }

        $disk->put($filename, $binary);

        return [
            'audio_path' => $filename,
            'duration_seconds' => $this->estimateDurationSeconds($text),
            'provider' => 'google-cloud-tts',
        ];
    }

    private function getGoogleAccessToken(): string
    {
        $credentials = $this->loadGoogleCredentials();
        $clientEmail = (string) ($credentials['client_email'] ?? '');
        $privateKey = (string) ($credentials['private_key'] ?? '');

        if ($clientEmail === '' || $privateKey === '') {
            throw new \RuntimeException('Missing Google TTS service-account credentials.');
        }

        $cacheKey = 'listen:google_tts_token:'.sha1($clientEmail);

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

            $unsigned = $header.'.'.$claims;
            $signature = '';
            $ok = openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
            if (! $ok) {
                throw new \RuntimeException('Unable to sign Google OAuth JWT assertion.');
            }

            $assertion = $unsigned.'.'.$this->base64UrlEncode($signature);

            $response = $this->sendWithSslFallback(
                fn (bool $verify) => Http::asForm()
                    ->timeout(15)
                    ->withOptions(['verify' => $verify])
                    ->post($tokenUri, [
                        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                        'assertion' => $assertion,
                    ]),
                $verifySsl,
                'Google OAuth token request',
            );

            if (! $response->ok()) {
                throw new \RuntimeException('Failed fetching Google OAuth token: '.$response->body());
            }

            $token = $response->json('access_token');
            if (! is_string($token) || $token === '') {
                throw new \RuntimeException('Google OAuth token response missing access_token.');
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

        throw new \RuntimeException('Google TTS credentials are not configured. Set GOOGLE_TTS_CREDENTIALS_JSON or GOOGLE_TTS_CREDENTIALS_PATH.');
    }

    private function resolveVoiceName(string $targetLanguage, ?string $voiceName): string
    {
        $voiceName = trim((string) $voiceName);

        if (
            $voiceName !== ''
            && VoiceCatalog::isAllowedForLanguage($voiceName, $targetLanguage)
            && (
                ! config('listen.voice.require_wavenet', true)
                || VoiceCatalog::isWaveNetVoiceName($voiceName)
            )
        ) {
            return $voiceName;
        }

        return VoiceCatalog::defaultForLanguage($targetLanguage);
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

    private function findCachedAudio(string $hash, string $targetLanguage): ?string
    {
        $disk = Storage::disk('public');
        $path = "audio/cache/{$targetLanguage}_{$hash}.mp3";

        return $disk->exists($path) ? $path : null;
    }

    private function storeInCache(string $sourcePath, string $hash, string $targetLanguage): string
    {
        $disk = Storage::disk('public');
        $cachePath = "audio/cache/{$targetLanguage}_{$hash}.mp3";

        if (! $disk->exists($cachePath)) {
            $dir = dirname($disk->path($cachePath));
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $disk->copy($sourcePath, $cachePath);
        }

        return $cachePath;
    }

    private function estimateDurationSeconds(string $text): int
    {
        $wordEstimate = max(
            str_word_count(strip_tags($text)),
            (int) ceil(mb_strlen($text) / 5)
        );

        return max(3, (int) ceil($wordEstimate / 2.6));
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
            if (! $this->shouldRetryWithoutSsl($e, $verifySsl)) {
                throw $e;
            }

            Log::warning($context.' failed SSL verification on local environment; retrying once without verification.');

            return $sender(false);
        }
    }

    private function shouldRetryWithoutSsl(\Throwable $e, bool $verifySsl): bool
    {
        if (! $verifySsl || ! app()->environment('local')) {
            return false;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'curl error 60')
            || str_contains($message, 'ssl certificate problem')
            || str_contains($message, 'unable to get local issuer certificate');
    }
}
