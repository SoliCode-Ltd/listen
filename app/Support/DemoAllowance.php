<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * How many premium demo characters each member gets in total, which is also
 * the longest single demo text. Set in Admin → Site Settings → Free demo;
 * falls back to listen.demo.guest_char_allowance (DEMO_GUEST_CHAR_ALLOWANCE).
 */
final class DemoAllowance
{
    public const SETTING_KEY = 'demo_char_allowance';

    public const MAX = 100000;

    public static function chars(): int
    {
        $setting = (int) SiteSetting::get(self::SETTING_KEY, 0);
        $chars = $setting > 0 ? $setting : (int) config('listen.demo.guest_char_allowance', 300);

        return max(1, min(self::MAX, $chars));
    }
}
