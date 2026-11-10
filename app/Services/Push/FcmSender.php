<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends one notification to one phone through Firebase Cloud Messaging (the
 * HTTP v1 API, which is free). It signs in with the Firebase project's service
 * account (config services.fcm), the way the speech service does: no SDK.
 *
 * It never throws: a notification that cannot be sent must not fail the voice
 * it announces.
 */
class FcmSender
{
    public const SENT = 'sent';

    /** The phone no longer has the app, or the token was replaced: forget it. */
    public const GONE = 'gone';

    public const FAILED = 'failed';

    public const NOT_CONFIGURED = 'not_configured';

    public function configured(): bool
    {
        $credentials = $this->credentials();

        return filled($credentials['client_email'] ?? null)
            && filled($credentials['private_key'] ?? null)
            && filled($credentials['project_id'] ?? null);
    }

    /**
     * @param  array<string, string>  $data  what the app gets with the tap (strings only)
     */
    public function send(string $token, string $title, string $body, array $data = [], string $channel = 'voice_ready'): string
    {
        if (! $this->configured()) {
            return self::NOT_CONFIGURED;
        }

        try {
            $credentials = $this->credentials();
            $response = Http::withToken($this->accessToken($credentials))
                ->timeout(6)
                ->connectTimeout(4)
                ->acceptJson()
                ->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode((string) $credentials['project_id']).'/messages:send', [
                    'message' => [
                        'token' => $token,
                        'notification' => ['title' => $title, 'body' => $body],
                        'data' => array_map('strval', $data),
                        'android' => [
                            'priority' => 'HIGH',
                            // Still worth showing a day later, not after.
                            'ttl' => '86400s',
                            'notification' => ['channel_id' => $channel, 'tag' => (string) ($data['tag'] ?? '')],
                        ],
                    ],
                ]);

            if ($response->successful()) {
                return self::SENT;
            }

            $status = (string) $response->json('error.status', '');
            $errorCode = (string) data_get($response->json('error.details', []), '0.errorCode', '');
            if ($response->status() === 404 || $status === 'NOT_FOUND' || $errorCode === 'UNREGISTERED') {
                return self::GONE;
            }
            // A token that was never one (INVALID_ARGUMENT on the token) is gone too.
            if ($response->status() === 400 && str_contains(strtolower((string) $response->json('error.message', '')), 'registration token')) {
                return self::GONE;
            }
            if ($response->status() === 401) {
                Cache::forget($this->cacheKey($credentials));
            }

            Log::warning('App notification was not sent.', ['status' => $response->status(), 'reason' => $status ?: $errorCode]);

            return self::FAILED;
        } catch (\Throwable $e) {
            Log::warning('App notification was not sent.', ['exception' => get_class($e), 'message' => $e->getMessage()]);

            return self::FAILED;
        }
    }

    /** @param array<string, mixed> $credentials */
    private function accessToken(array $credentials): string
    {
        return Cache::remember($this->cacheKey($credentials), now()->addMinutes(50), function () use ($credentials): string {
            $tokenUri = (string) ($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token');
            $now = time();
            $unsigned = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR))
                .'.'.$this->base64Url(json_encode([
                    'iss' => (string) $credentials['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud' => $tokenUri,
                    'exp' => $now + 3600,
                    'iat' => $now,
                ], JSON_THROW_ON_ERROR));

            $signature = '';
            if (! openssl_sign($unsigned, $signature, (string) $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('The messaging service account key could not sign.');
            }

            $response = Http::asForm()->timeout(8)->connectTimeout(4)->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $unsigned.'.'.$this->base64Url($signature),
            ]);
            $token = $response->json('access_token');
            if (! $response->successful() || ! is_string($token) || $token === '') {
                throw new \RuntimeException('The messaging service gave no access token ('.$response->status().').');
            }

            return $token;
        });
    }

    /** @param array<string, mixed> $credentials */
    private function cacheKey(array $credentials): string
    {
        return 'listen:fcm_token:'.sha1((string) ($credentials['client_email'] ?? ''));
    }

    /** @return array<string, mixed> */
    private function credentials(): array
    {
        $json = trim((string) config('services.fcm.credentials_json', ''));
        if ($json !== '') {
            // Pasted as it is, or base64 (one line, safe in .env).
            $decoded = json_decode($json, true);
            if (! is_array($decoded)) {
                $decoded = json_decode((string) base64_decode($json, true), true);
            }
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $path = trim((string) config('services.fcm.credentials_path', ''));
        if ($path !== '' && is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
