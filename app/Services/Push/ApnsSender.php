<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends one notification to one iPhone through Apple's push service (APNs),
 * straight from the site: the iPhone app has no Firebase in it. It signs in
 * with a key from the Apple developer account (config services.apns), the way
 * FcmSender signs in with Firebase's: no SDK.
 *
 * It answers with FcmSender's results (sent, gone, failed, not configured),
 * and like it never throws: a notification that cannot be sent must not fail
 * the voice it announces.
 */
class ApnsSender
{
    private const LIVE_HOST = 'https://api.push.apple.com';

    /** Apple's test service, for an app run straight from Xcode. TestFlight and the store use the live one. */
    private const SANDBOX_HOST = 'https://api.sandbox.push.apple.com';

    /** Apple accepts a sign-in token for an hour and asks not to make a new one more than every 20 minutes. */
    private const TOKEN_MINUTES = 40;

    public function configured(): bool
    {
        return filled(config('services.apns.key_id'))
            && filled(config('services.apns.team_id'))
            && filled(config('services.apns.bundle_id'))
            && $this->privateKey() !== '';
    }

    /**
     * @param  array<string, string>  $data  what the app gets with the tap (strings only)
     */
    public function send(string $token, string $title, string $body, array $data = []): string
    {
        if (! $this->configured()) {
            return FcmSender::NOT_CONFIGURED;
        }
        // Apple's token is hexadecimal; anything else was never one.
        if (preg_match('/^[0-9a-fA-F]{32,200}$/', $token) !== 1) {
            return FcmSender::GONE;
        }

        try {
            $tag = (string) ($data['tag'] ?? '');
            $headers = [
                'apns-topic' => (string) config('services.apns.bundle_id'),
                'apns-push-type' => 'alert',
                'apns-priority' => '10',
                // Still worth showing a day later, not after.
                'apns-expiration' => (string) (time() + 86400),
            ];
            if ($tag !== '') {
                // A second message about the same thing replaces the first.
                $headers['apns-collapse-id'] = substr($tag, 0, 64);
            }

            $response = Http::withToken($this->providerToken())
                ->withHeaders($headers)
                // Apple's service speaks HTTP/2 only.
                ->withOptions(['version' => 2.0])
                ->timeout(6)
                ->connectTimeout(4)
                ->acceptJson()
                ->post($this->host().'/3/device/'.strtolower($token), [
                    'aps' => [
                        'alert' => ['title' => $title, 'body' => $body],
                        'sound' => 'default',
                        'thread-id' => (string) ($data['kind'] ?? 'listen'),
                    ],
                ] + array_map('strval', $data));

            if ($response->successful()) {
                return FcmSender::SENT;
            }

            $reason = (string) $response->json('reason', '');
            // The app was removed from the phone, or the token is not one of this app's.
            if ($response->status() === 410 || in_array($reason, ['Unregistered', 'BadDeviceToken', 'DeviceTokenNotForTopic'], true)) {
                return FcmSender::GONE;
            }
            if ($response->status() === 403) {
                Cache::forget($this->cacheKey());
            }

            Log::warning('App notification was not sent to an iPhone.', ['status' => $response->status(), 'reason' => $reason]);

            return FcmSender::FAILED;
        } catch (\Throwable $e) {
            Log::warning('App notification was not sent to an iPhone.', ['exception' => get_class($e), 'message' => $e->getMessage()]);

            return FcmSender::FAILED;
        }
    }

    private function host(): string
    {
        return config('services.apns.sandbox') ? self::SANDBOX_HOST : self::LIVE_HOST;
    }

    /** The sign-in token Apple asks for: a JWT signed with the account's key (ES256). */
    private function providerToken(): string
    {
        return Cache::remember($this->cacheKey(), now()->addMinutes(self::TOKEN_MINUTES), function (): string {
            $unsigned = $this->base64Url(json_encode(['alg' => 'ES256', 'kid' => (string) config('services.apns.key_id')], JSON_THROW_ON_ERROR))
                .'.'.$this->base64Url(json_encode(['iss' => (string) config('services.apns.team_id'), 'iat' => time()], JSON_THROW_ON_ERROR));

            $der = '';
            if (! openssl_sign($unsigned, $der, $this->privateKey(), OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('The Apple push key could not sign.');
            }

            return $unsigned.'.'.$this->base64Url(self::rawSignature($der));
        });
    }

    /**
     * openssl gives an ECDSA signature in DER (two numbers, each with a header);
     * a JWT wants the two numbers alone, 32 bytes each, one after the other.
     */
    public static function rawSignature(string $der): string
    {
        $offset = 0;
        $read = function () use ($der, &$offset): string {
            if (ord($der[$offset] ?? "\0") !== 0x02) {
                throw new \RuntimeException('The signature is not in the form expected.');
            }
            $length = ord($der[$offset + 1]);
            $number = substr($der, $offset + 2, $length);
            $offset += 2 + $length;

            return str_pad(ltrim($number, "\x00"), 32, "\x00", STR_PAD_LEFT);
        };

        if (ord($der[0] ?? "\0") !== 0x30) {
            throw new \RuntimeException('The signature is not in the form expected.');
        }
        // The sequence's own length takes one byte, or two when it is 128 or more.
        $offset = (ord($der[1]) & 0x80) ? 2 + (ord($der[1]) & 0x7F) : 2;

        return $read().$read();
    }

    /** The key as text (the .p8 file's content): pasted as it is, as base64 of it (one line, safe in .env), or a file's path. */
    private function privateKey(): string
    {
        $key = trim((string) config('services.apns.private_key', ''));
        if ($key !== '') {
            if (! str_contains($key, 'BEGIN')) {
                $decoded = base64_decode($key, true);
                $key = is_string($decoded) ? trim($decoded) : '';
            }

            return str_contains($key, 'BEGIN') ? str_replace('\n', "\n", $key) : '';
        }

        $path = trim((string) config('services.apns.private_key_path', ''));
        if ($path !== '' && is_file($path)) {
            $file = trim((string) file_get_contents($path));

            return str_contains($file, 'BEGIN') ? $file : '';
        }

        return '';
    }

    private function cacheKey(): string
    {
        return 'listen:apns_token:'.sha1((string) config('services.apns.key_id').'|'.(string) config('services.apns.team_id'));
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
