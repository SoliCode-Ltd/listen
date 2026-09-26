<?php

namespace App\Support;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\VoiceCloningService;

/**
 * Who may open the voice cloning studio.
 *
 * The default is a private preview: only the SoliCode admin email.
 * An admin setting can open the studio to every account whose plan includes it.
 * Free accounts, and plans without cloning, see an upgrade prompt.
 */
class VoiceCloningAccess
{
    public const PREVIEW_EMAIL = 'solicode.co.uk@gmail.com';

    public const AUDIENCE_PREVIEW = 'preview';

    public const AUDIENCE_EVERYONE = 'everyone';

    public static function audience(): string
    {
        $value = strtolower(trim((string) SiteSetting::get('voice_cloning_audience', self::AUDIENCE_PREVIEW)));

        return $value === self::AUDIENCE_EVERYONE
            ? self::AUDIENCE_EVERYONE
            : self::AUDIENCE_PREVIEW;
    }

    public static function isPreviewOnly(): bool
    {
        return self::audience() === self::AUDIENCE_PREVIEW;
    }

    public static function isPreviewAccount(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $email = strtolower(trim((string) $user->email));

        return $email !== '' && $email === strtolower(self::PREVIEW_EMAIL);
    }

    /**
     * Whether this account may use the studio.
     * Pass the active plan when the studio is open to every customer,
     * so a plan without cloning stays closed.
     */
    public static function allows(?User $user, ?Plan $plan = null): bool
    {
        if (! $user) {
            return false;
        }

        if (self::isPreviewAccount($user)) {
            return true;
        }

        if (self::audience() !== self::AUDIENCE_EVERYONE) {
            return false;
        }

        if ($plan === null) {
            return true;
        }

        return VoiceCloningService::isEnabled()
            && (bool) ($plan->voice_cloning_enabled ?? false);
    }

    public static function showsUpgrade(?User $user, ?Plan $plan): bool
    {
        if (! $user || self::isPreviewAccount($user)) {
            return false;
        }

        if (self::audience() !== self::AUDIENCE_EVERYONE) {
            return (int) ($plan?->price_cents ?? 0) <= 0;
        }

        if (! VoiceCloningService::isEnabled()) {
            return false;
        }

        return ! (bool) ($plan?->voice_cloning_enabled ?? false);
    }
}
