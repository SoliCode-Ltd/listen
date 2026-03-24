<?php

namespace App\Services\Auth;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

class FirebaseTokenVerifier
{
    public function __construct(
        private readonly AuthSettingsService $authSettings,
    ) {
    }

    /**
     * Verify a Firebase ID token and return normalized identity info.
     *
     * @param  string|null  $expectedProvider  e.g. 'phone', 'google.com', or null for any
     * @return array{
     *   uid: string,
     *   phone_number: string|null,
     *   email: string|null,
     *   email_verified: bool,
     *   display_name: string|null,
     *   photo_url: string|null,
     *   provider_ids: array<int, string>
     * }
     */
    public function verify(string $idToken, ?string $expectedProvider = null): array
    {
        $apiKey = trim((string) ($this->authSettings->firebaseClientConfig()['apiKey'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('missing_firebase_api_key');
        }

        $lookupUrl = sprintf('https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=%s', urlencode($apiKey));
        $payload = ['idToken' => $idToken];

        try {
            $response = Http::asJson()
                ->timeout(15)
                ->post($lookupUrl, $payload);
        } catch (ConnectionException $exception) {
            if ($this->shouldRetryWithoutSslVerification($exception)) {
                Log::warning('Retrying Firebase lookup without SSL verification in local/testing.', [
                    'exception' => get_class($exception),
                ]);

                $response = Http::asJson()
                    ->timeout(15)
                    ->withoutVerifying()
                    ->post($lookupUrl, $payload);
            } else {
                throw $exception;
            }
        }

        if (! $response->ok()) {
            throw new RuntimeException('firebase_lookup_failed');
        }

        $user = Arr::first((array) $response->json('users', []));
        if (! is_array($user)) {
            throw new RuntimeException('firebase_user_not_found');
        }

        $uid = trim((string) ($user['localId'] ?? ''));
        if ($uid === '') {
            throw new RuntimeException('firebase_identity_incomplete');
        }

        $phoneNumber = trim((string) ($user['phoneNumber'] ?? ''));
        $email = trim((string) ($user['email'] ?? ''));
        $emailVerified = (bool) ($user['emailVerified'] ?? false);
        $displayName = trim((string) ($user['displayName'] ?? ''));
        $photoUrl = trim((string) ($user['photoUrl'] ?? ''));

        $providerInfo = (array) ($user['providerUserInfo'] ?? []);
        $providerIds = collect($providerInfo)
            ->filter(static fn ($item): bool => is_array($item))
            ->map(static fn (array $item): string => strtolower(trim((string) ($item['providerId'] ?? ''))))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($expectedProvider !== null && ! in_array($expectedProvider, $providerIds, true)) {
            throw new RuntimeException("firebase_token_not_{$expectedProvider}_provider");
        }

        // Provider-specific validation
        if ($expectedProvider === 'phone' && $phoneNumber === '') {
            throw new RuntimeException('firebase_identity_incomplete');
        }

        if ($expectedProvider === 'google.com' && $email === '') {
            throw new RuntimeException('firebase_identity_incomplete');
        }

        return [
            'uid' => $uid,
            'phone_number' => $phoneNumber !== '' ? $phoneNumber : null,
            'email' => $email !== '' ? $email : null,
            'email_verified' => $emailVerified,
            'display_name' => $displayName !== '' ? $displayName : null,
            'photo_url' => $photoUrl !== '' ? $photoUrl : null,
            'provider_ids' => $providerIds,
        ];
    }

    private function shouldRetryWithoutSslVerification(ConnectionException $exception): bool
    {
        if (! app()->environment(['local', 'testing'])) {
            return false;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'ssl certificate')
            || str_contains($message, 'unable to get local issuer certificate')
            || str_contains($message, 'curl error 60');
    }
}
