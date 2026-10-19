<?php

namespace App\Support;

/**
 * The numbers the site quotes: spoken Arabic dialects (Fusha and its styles are
 * named apart, as "Fusha and 140+ Arabic accents") and English accents,
 * rounded down to the ten ("140+") so the claim stays true as more are added.
 * The language files quote them as plain numbers; DialectCountsTest keeps those equal.
 */
final class DialectCounts
{
    public static function arabic(): int
    {
        return self::roundDown(self::spokenArabic());
    }

    public static function english(): int
    {
        return self::roundDown(count(EnglishAccents::ALL));
    }

    /** Every spoken Arabic dialect, exactly, as the dialect hub counts them: Fusha and its styles left out. */
    public static function spokenArabic(): int
    {
        return count(array_filter(ArabicDialects::ALL, fn (array $row): bool => $row['region'] !== 'fusha'));
    }

    private static function roundDown(int $count): int
    {
        return intdiv($count, 10) * 10;
    }
}
