<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * Whether members on the free plan get its monthly characters. Members keep
 * what they had when they joined (users.free_monthly_chars); this setting
 * decides it for people who join from now on.
 */
final class FreeAllowance
{
    public const NEW_MEMBERS_KEY = 'new_members_free_monthly_chars';

    public static function newMembersGetMonthlyChars(): bool
    {
        return (string) SiteSetting::get(self::NEW_MEMBERS_KEY, '1') === '1';
    }
}
