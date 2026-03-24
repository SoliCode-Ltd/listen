<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\PlanAccessService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class PhoneAuthBridgeService
{
    public function __construct(
        private readonly FirebasePhoneTokenVerifier $firebaseTokenVerifier,
        private readonly PlanAccessService $planAccessService,
    ) {
    }

    /**
     * Resolve a Laravel user from a verified Firebase phone identity.
     *
     * Account linking safety:
     * - First match by firebase_uid (strongest identity)
     * - Then match by exact phone_number
     * - Never auto-merge if phone points to a different firebase_uid
     */
    public function resolveUserFromIdToken(string $idToken): User
    {
        $identity = $this->firebaseTokenVerifier->verify($idToken);
        $firebaseUid = $identity['uid'];
        $phoneNumber = $identity['phone_number'];

        $user = User::query()->where('firebase_uid', $firebaseUid)->first();

        if (! $user) {
            $user = User::query()->where('phone_number', $phoneNumber)->first();

            if ($user && filled($user->firebase_uid) && $user->firebase_uid !== $firebaseUid) {
                throw new RuntimeException('phone_account_link_conflict');
            }
        } else {
            $phoneOwner = User::query()
                ->where('phone_number', $phoneNumber)
                ->whereKeyNot($user->id)
                ->first();

            if ($phoneOwner) {
                throw new RuntimeException('phone_number_already_used');
            }
        }

        if (! $user) {
            $user = $this->createPhoneUser(
                firebaseUid: $firebaseUid,
                phoneNumber: $phoneNumber,
                displayName: $identity['display_name'],
            );
        } else {
            $this->syncPhoneIdentity($user, $firebaseUid, $phoneNumber);
        }

        $this->planAccessService->ensureFreePlanForUser($user);

        return $user;
    }

    private function createPhoneUser(string $firebaseUid, string $phoneNumber, ?string $displayName): User
    {
        return User::query()->create([
            'name' => $this->resolveDisplayName($displayName, $phoneNumber),
            'email' => $this->makePhonePlaceholderEmail($firebaseUid),
            'password' => Hash::make(Str::password(32)),
            'firebase_uid' => $firebaseUid,
            'phone_number' => $phoneNumber,
            'phone_verified_at' => now(),
            'auth_provider' => 'phone',
            'email_verified_at' => now(),
        ]);
    }

    private function syncPhoneIdentity(User $user, string $firebaseUid, string $phoneNumber): void
    {
        $attributes = [];

        if (! filled($user->firebase_uid)) {
            $attributes['firebase_uid'] = $firebaseUid;
        } elseif ($user->firebase_uid !== $firebaseUid) {
            throw new RuntimeException('firebase_uid_mismatch');
        }

        if ($user->phone_number !== $phoneNumber) {
            $attributes['phone_number'] = $phoneNumber;
        }

        if (! $user->phone_verified_at) {
            $attributes['phone_verified_at'] = now();
        }

        if (! filled($user->auth_provider)) {
            $attributes['auth_provider'] = 'phone';
        }

        if ($attributes !== []) {
            $user->forceFill($attributes)->save();
        }
    }

    private function resolveDisplayName(?string $displayName, string $phoneNumber): string
    {
        if ($displayName && trim($displayName) !== '') {
            return trim($displayName);
        }

        return 'Phone User '.substr(preg_replace('/\D+/', '', $phoneNumber) ?? '0000', -4);
    }

    private function makePhonePlaceholderEmail(string $firebaseUid): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firebaseUid) ?? '');
        if ($slug === '') {
            $slug = Str::lower(Str::random(24));
        }

        $email = "phone+{$slug}@users.listen.local";

        if (! User::query()->where('email', $email)->exists()) {
            return $email;
        }

        do {
            $fallback = 'phone+'.Str::lower(Str::random(20)).'@users.listen.local';
        } while (User::query()->where('email', $fallback)->exists());

        return $fallback;
    }
}

