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
        private readonly FirebaseTokenVerifier $firebaseTokenVerifier,
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
    public function resolveUserFromIdToken(string $idToken, ?string $ip = null): User
    {
        $identity = $this->firebaseTokenVerifier->verify($idToken, 'phone');
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
                ip: $ip,
            );
        } else {
            $this->syncPhoneIdentity($user, $firebaseUid, $phoneNumber);
        }

        $this->planAccessService->ensureFreePlanForUser($user);

        return $user;
    }

    /**
     * Resolve a Laravel user from a verified Firebase Google identity.
     *
     * Account linking priority:
     * 1. Match by firebase_uid (strongest — same Firebase account)
     * 2. Match by email (links Socialite Google or email/password accounts)
     */
    public function resolveUserFromGoogleIdToken(string $idToken, ?string $ip = null): User
    {
        $identity = $this->firebaseTokenVerifier->verify($idToken, 'google.com');
        $firebaseUid = $identity['uid'];
        $email = $identity['email'];
        $displayName = $identity['display_name'];
        $photoUrl = $identity['photo_url'];

        $user = User::query()->where('firebase_uid', $firebaseUid)->first();

        if (! $user && $email) {
            $user = User::query()->where('email', $email)->first();

            if ($user && filled($user->firebase_uid) && $user->firebase_uid !== $firebaseUid) {
                throw new RuntimeException('google_account_link_conflict');
            }
        }

        if (! $user) {
            $user = $this->createGoogleFirebaseUser(
                firebaseUid: $firebaseUid,
                email: $email,
                displayName: $displayName,
                photoUrl: $photoUrl,
                ip: $ip,
            );
        } else {
            $this->syncGoogleFirebaseIdentity($user, $firebaseUid, $email, $photoUrl);
        }

        $this->planAccessService->ensureFreePlanForUser($user);

        return $user;
    }

    // ── Phone user creation ─────────────────────────────────────────

    private function createPhoneUser(string $firebaseUid, string $phoneNumber, ?string $displayName, ?string $ip = null): User
    {
        return User::query()->create([
            'name' => $this->resolvePhoneDisplayName($displayName, $phoneNumber),
            'email' => $this->makePhonePlaceholderEmail($firebaseUid),
            'password' => Hash::make(Str::password(32)),
            'firebase_uid' => $firebaseUid,
            'phone_number' => $phoneNumber,
            'phone_verified_at' => now(),
            'auth_provider' => 'phone',
            'email_verified_at' => now(),
            'registration_ip' => $ip,
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

    private function resolvePhoneDisplayName(?string $displayName, string $phoneNumber): string
    {
        if ($displayName && trim($displayName) !== '') {
            return trim($displayName);
        }

        return $phoneNumber;
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

    // ── Google Firebase user creation ───────────────────────────────

    private function createGoogleFirebaseUser(string $firebaseUid, string $email, ?string $displayName, ?string $photoUrl, ?string $ip = null): User
    {
        return User::query()->create([
            'name' => ($displayName && trim($displayName) !== '') ? trim($displayName) : explode('@', $email)[0],
            'email' => $email,
            'password' => Hash::make(Str::password(32)),
            'firebase_uid' => $firebaseUid,
            'google_avatar' => ($photoUrl && $photoUrl !== '') ? $photoUrl : null,
            'auth_provider' => 'firebase_google',
            'email_verified_at' => now(),
            'registration_ip' => $ip,
        ]);
    }

    private function syncGoogleFirebaseIdentity(User $user, string $firebaseUid, ?string $email, ?string $photoUrl): void
    {
        $attributes = [];

        if (! filled($user->firebase_uid)) {
            $attributes['firebase_uid'] = $firebaseUid;
        } elseif ($user->firebase_uid !== $firebaseUid) {
            throw new RuntimeException('firebase_uid_mismatch');
        }

        if ($photoUrl && $photoUrl !== '' && $user->google_avatar !== $photoUrl) {
            $attributes['google_avatar'] = $photoUrl;
        }

        if (! $user->email_verified_at) {
            $attributes['email_verified_at'] = now();
        }

        if (! filled($user->auth_provider)) {
            $attributes['auth_provider'] = 'firebase_google';
        }

        if ($attributes !== []) {
            $user->forceFill($attributes)->save();
        }
    }
}
