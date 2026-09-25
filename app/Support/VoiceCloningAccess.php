<?php

namespace App\Support;

use App\Models\User;

/**
 * Voice cloning is a private preview on the live site.
 * Only the SoliCode admin account can see or use it.
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
}
