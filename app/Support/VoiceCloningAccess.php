<?php

namespace App\Support;

use App\Models\Plan;
use App\Models\User;

/**
 * Voice cloning is a private preview on the live site.
 * Only the SoliCode admin account can use it.
 * Free accounts can see an upgrade prompt that leads to billing.
 */
class VoiceCloningAccess
{
    public const PREVIEW_EMAIL = 'solicode.co.uk@gmail.com';

    public static function allows(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $email = strtolower(trim((string) $user->email));

        return $email !== '' && $email === strtolower(self::PREVIEW_EMAIL);
    }

    public static function showsUpgrade(?User $user, ?Plan $plan): bool
    {
        if (! $user || self::allows($user)) {
            return false;
        }

        return (int) ($plan?->price_cents ?? 0) <= 0;
    }
}
