<?php

namespace App\Services\Auth;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FirebasePhoneTokenVerifier
{
    public function __construct(
        private readonly AuthSettingsService $authSettings,
    ) {
    }

    /**
     * @return array{
     *   uid: string,
     *   phone_number: string,
     *   provider_ids: array<int, string>,
     *   display_name: string|null
     * }
     */
    public function verify(string $idToken): array
    {
        $apiKey = trim((string) ($this->authSettings->firebaseClientConfig()['apiKey'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('missing_firebase_api_key');
        }

        $response = Http::asJson()
            ->timeout(15)
            ->post(
                sprintf('https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=%s', urlencode($apiKey)),
                ['idToken' => $idToken]
            );

        if (! $response->ok()) {
            throw new RuntimeException('firebase_lookup_failed');
        }

        $user = Arr::first((array) $response->json('users', []));
        if (! is_array($user)) {
            throw new RuntimeException('firebase_user_not_found');
        }

        $uid = trim((string) ($user['localId'] ?? ''));
        $phoneNumber = trim((string) ($user['phoneNumber'] ?? ''));
        if ($uid === '' || $phoneNumber === '') {
            throw new RuntimeException('firebase_identity_incomplete');
        }

        $providerInfo = (array) ($user['providerUserInfo'] ?? []);
        $providerIds = collect($providerInfo)
            ->filter(static fn ($item): bool => is_array($item))
            ->map(static fn (array $item): string => strtolower(trim((string) ($item['providerId'] ?? ''))))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (! in_array('phone', $providerIds, true)) {
            throw new RuntimeException('firebase_token_not_phone_provider');
        }

        $displayName = trim((string) ($user['displayName'] ?? ''));

        return [
            'uid' => $uid,
            'phone_number' => $phoneNumber,
            'provider_ids' => $providerIds,
            'display_name' => $displayName !== '' ? $displayName : null,
        ];
    }
}

