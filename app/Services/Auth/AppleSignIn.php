<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * "Sign in with Apple" for the iPhone app. Apple asks an app that offers
 * another company's sign-in (Google) to offer this one beside it (App Review
 * guideline 4.8).
 *
 * The phone does the signing in and gives the page a token signed by Apple
 * that says who the person is. This class checks that token (Apple's public
 * keys, made for this app, not expired, and answering the question this
 * site asked: the nonce), with no SDK.
 *
 * With a key from the Apple developer account it also keeps what is needed
 * to tell Apple to forget the link when the member deletes their account, as
 * Apple asks. Without the key, signing in still works; only that last step
 * is skipped.
 */
class AppleSignIn
{
    public const ISSUER = 'https://appleid.apple.com';

    /** Session key: the one-time value the sign-in must carry back. */
    public const NONCE_SESSION = 'apple_signin.nonce';

    private const KEYS_URL = 'https://appleid.apple.com/auth/keys';

    private const TOKEN_URL = 'https://appleid.apple.com/auth/token';

    private const REVOKE_URL = 'https://appleid.apple.com/auth/revoke';

    private const KEYS_CACHE = 'listen:apple_signin_keys';

    /** The one-time value for this visitor's sign-in, made on first ask. */
    public static function nonce(Request $request): string
    {
        $nonce = (string) $request->session()->get(self::NONCE_SESSION, '');
        if ($nonce === '') {
            $nonce = Str::random(40);
            $request->session()->put(self::NONCE_SESSION, $nonce);
        }

        return $nonce;
    }

    /**
     * Who Apple says this is. Throws when the token is not Apple's, not for this
     * app, expired, or not the answer to this site's question.
     *
     * @return array{sub:string,email:?string,email_verified:bool}
     */
    public function verify(string $identityToken, string $nonce): array
    {
        $parts = explode('.', $identityToken);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException('The token is not a signed token.');
        }
        [$headerPart, $claimsPart, $signaturePart] = $parts;
        $header = json_decode($this->base64UrlDecode($headerPart), true);
        $claims = json_decode($this->base64UrlDecode($claimsPart), true);
        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? '') !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            throw new InvalidArgumentException('The token is not in the form Apple signs.');
        }

        $pem = $this->publicKey($header['kid']);
        if (openssl_verify($headerPart.'.'.$claimsPart, $this->base64UrlDecode($signaturePart), $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new InvalidArgumentException('The token was not signed by Apple.');
        }

        if (($claims['iss'] ?? '') !== self::ISSUER) {
            throw new InvalidArgumentException('The token does not come from Apple.');
        }
        $audience = (array) ($claims['aud'] ?? []);
        if (! in_array($this->clientId(), $audience, true)) {
            throw new InvalidArgumentException('The token was made for another app.');
        }
        if ((int) ($claims['exp'] ?? 0) < time() - 60) {
            throw new InvalidArgumentException('The token has expired.');
        }
        if ($nonce === '' || ! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new InvalidArgumentException('The token does not answer this sign-in.');
        }
        $subject = trim((string) ($claims['sub'] ?? ''));
        if ($subject === '' || strlen($subject) > 190) {
            throw new InvalidArgumentException('The token names nobody.');
        }

        $email = trim((string) ($claims['email'] ?? ''));

        return [
            'sub' => $subject,
            'email' => $email !== '' ? strtolower($email) : null,
            // Apple sends this as true or as the word "true".
            'email_verified' => filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /** Whether the Apple account's key is set, so the site can talk to Apple about a member's link. */
    public function canTalkToApple(): bool
    {
        return filled(config('services.apple_sign_in.team_id'))
            && filled(config('services.apple_sign_in.key_id'))
            && $this->privateKey() !== '';
    }

    /**
     * The token that lets the site end the link later, from the one-time code the
     * phone got. Null when it could not be had: signing in goes on without it.
     */
    public function refreshTokenFor(string $authorizationCode): ?string
    {
        if ($authorizationCode === '' || ! $this->canTalkToApple()) {
            return null;
        }

        try {
            $response = Http::asForm()->timeout(8)->connectTimeout(4)->post(self::TOKEN_URL, [
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'code' => $authorizationCode,
                'grant_type' => 'authorization_code',
            ]);
            $token = $response->json('refresh_token');

            return $response->successful() && is_string($token) && $token !== '' ? $token : null;
        } catch (\Throwable $e) {
            Log::warning('Apple did not give a token for a sign-in; the member is signed in without it.', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /** Tells Apple to forget this member's link to Listen (their account is being deleted). */
    public function revoke(string $refreshToken): bool
    {
        if ($refreshToken === '' || ! $this->canTalkToApple()) {
            return false;
        }

        try {
            return Http::asForm()->timeout(8)->connectTimeout(4)->post(self::REVOKE_URL, [
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'token' => $refreshToken,
                'token_type_hint' => 'refresh_token',
            ])->successful();
        } catch (\Throwable $e) {
            Log::warning('Apple could not be told to forget a deleted account\'s link.', ['message' => $e->getMessage()]);

            return false;
        }
    }

    private function clientId(): string
    {
        return (string) config('services.apple_sign_in.client_id', 'studio.listenai.app');
    }

    /** Apple's public key with this id, as PEM. The keys are kept for a day and fetched again when an id is new. */
    private function publicKey(string $keyId): string
    {
        foreach ([false, true] as $fresh) {
            if ($fresh) {
                Cache::forget(self::KEYS_CACHE);
            }
            $keys = Cache::remember(self::KEYS_CACHE, now()->addDay(), function (): array {
                $response = Http::timeout(8)->connectTimeout(4)->acceptJson()->get(self::KEYS_URL);
                if (! $response->successful() || ! is_array($response->json('keys'))) {
                    throw new InvalidArgumentException('Apple\'s keys could not be read.');
                }

                return $response->json('keys');
            });
            foreach ($keys as $key) {
                if (($key['kid'] ?? null) === $keyId && ($key['kty'] ?? '') === 'RSA' && isset($key['n'], $key['e'])) {
                    return self::pemFromJwk((string) $key['n'], (string) $key['e']);
                }
            }
        }

        throw new InvalidArgumentException('The token was signed with a key Apple does not list.');
    }

    /** An RSA public key given as two numbers (a JWK's n and e), written as PEM for openssl. */
    public static function pemFromJwk(string $n, string $e): string
    {
        $decode = fn (string $value): string => (string) base64_decode(strtr($value, '-_', '+/'));
        $length = function (int $bytes): string {
            if ($bytes < 0x80) {
                return chr($bytes);
            }
            $hex = ltrim(pack('N', $bytes), "\x00");

            return chr(0x80 | strlen($hex)).$hex;
        };
        $integer = function (string $bytes) use ($length): string {
            $bytes = ltrim($bytes, "\x00");
            if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
                $bytes = "\x00".$bytes;
            }

            return "\x02".$length(strlen($bytes)).$bytes;
        };

        $numbers = $integer($decode($n)).$integer($decode($e));
        $rsaKey = "\x30".$length(strlen($numbers)).$numbers;
        // rsaEncryption (1.2.840.113549.1.1.1) with no parameters, then the key as a bit string.
        $algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $bitString = "\x03".$length(strlen($rsaKey) + 1)."\x00".$rsaKey;
        $der = "\x30".$length(strlen($algorithm.$bitString)).$algorithm.$bitString;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /** How the site proves to Apple that it is Listen: a short-lived token signed with the account's key (ES256). */
    private function clientSecret(): string
    {
        $now = time();
        $unsigned = $this->base64Url(json_encode(['alg' => 'ES256', 'kid' => (string) config('services.apple_sign_in.key_id')], JSON_THROW_ON_ERROR))
            .'.'.$this->base64Url(json_encode([
                'iss' => (string) config('services.apple_sign_in.team_id'),
                'iat' => $now,
                'exp' => $now + 300,
                'aud' => self::ISSUER,
                'sub' => $this->clientId(),
            ], JSON_THROW_ON_ERROR));

        $der = '';
        if (! openssl_sign($unsigned, $der, $this->privateKey(), OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('The Apple key could not sign.');
        }

        return $unsigned.'.'.$this->base64Url(self::rawSignature($der));
    }

    /** openssl's ECDSA signature (DER) as the two 32-byte numbers side by side that a JWT wants. */
    public static function rawSignature(string $der): string
    {
        if (ord($der[0] ?? "\0") !== 0x30) {
            throw new \RuntimeException('The signature is not in the form expected.');
        }
        $offset = (ord($der[1]) & 0x80) ? 2 + (ord($der[1]) & 0x7F) : 2;
        $raw = '';
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$offset] ?? "\0") !== 0x02) {
                throw new \RuntimeException('The signature is not in the form expected.');
            }
            $length = ord($der[$offset + 1]);
            $raw .= str_pad(ltrim(substr($der, $offset + 2, $length), "\x00"), 32, "\x00", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $raw;
    }

    /** The key file's content (.p8): pasted as it is, or as base64 on one line. */
    private function privateKey(): string
    {
        $key = trim((string) config('services.apple_sign_in.private_key', ''));
        if ($key === '') {
            $path = trim((string) config('services.apple_sign_in.private_key_path', ''));
            $key = $path !== '' && is_file($path) ? trim((string) file_get_contents($path)) : '';
        }
        if ($key !== '' && ! str_contains($key, 'BEGIN')) {
            $decoded = base64_decode($key, true);
            $key = is_string($decoded) ? trim($decoded) : '';
        }

        return str_contains($key, 'BEGIN') ? str_replace('\n', "\n", $key) : '';
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
