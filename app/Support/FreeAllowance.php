<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * Whether the free plan gives monthly characters. Off: everyone on the free
 * plan (admins included) has only the demo, plus any characters gifted to them.
 */
final class FreeAllowance
{
    public const NEW_MEMBERS_KEY = 'new_members_free_monthly_chars';

    public static function freePlanGivesMonthlyChars(): bool
    {
        return self::newMembersGetMonthlyChars();
    }

    /** @deprecated The setting now applies to everyone; use freePlanGivesMonthlyChars(). */
    public static function newMembersGetMonthlyChars(): bool
    {
        return (string) SiteSetting::get(self::NEW_MEMBERS_KEY, '1') === '1';
    }
}
