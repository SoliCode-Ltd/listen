<?php

namespace App\Support;

/**
 * The round flag shown next to a voice sample, from public/images/flags
 * (flag-icons, MIT). Modern Standard Arabic and the Arabic-accent English
 * voice have no single country, so they get the Arabic letter badge instead.
 */
final class VoiceFlag
{
    private const SUBDIVISIONS = [
        'EN-GB-SC' => 'gb-sct',
        'EN-GB-WL' => 'gb-wls',
        'EN-GB-NI' => 'gb-nir',
    ];

    private const NO_COUNTRY = ['AR-XA', 'EN-AR'];

    public static function url(?string $dialect, ?string $country = null): ?string
    {
        $code = self::code($dialect, $country);

        return $code !== null ? asset('images/flags/'.$code.'.svg') : null;
    }

    public static function code(?string $dialect, ?string $country = null): ?string
    {
        $dialect = strtoupper(trim(str_replace('_', '-', (string) $dialect)));
        if (in_array($dialect, self::NO_COUNTRY, true)) {
            return null;
        }
        if (isset(self::SUBDIVISIONS[$dialect])) {
            return self::SUBDIVISIONS[$dialect];
        }

        $country = strtolower(trim((string) $country));
        if ($country === '') {
            $country = strtolower((string) (explode('-', $dialect)[1] ?? ''));
        }

        return preg_match('/^[a-z]{2}$/', $country) && is_file(public_path('images/flags/'.$country.'.svg'))
            ? $country
            : null;
    }

    /**
     * Region a dialect or accent belongs to, for grouping voices:
     * fusha, gulf, levant, nile, iraq_yemen, maghreb for Arabic;
     * americas, uk_ie, africa, asia_pacific, other for English.
     */
    public static function region(?string $dialect): string
    {
        $code = trim((string) $dialect);
        if (isset(ArabicDialects::ALL[$code])) {
            return (string) ArabicDialects::ALL[$code]['region'];
        }

        return match (EnglishAccents::countryOf($code)) {
            'US', 'CA', 'JM', 'TT' => 'americas',
            'GB', 'IE' => 'uk_ie',
            'ZA', 'NG', 'GH', 'KE', 'UG', 'ZW' => 'africa',
            'AU', 'NZ', 'IN', 'SG', 'PK', 'PH', 'BD', 'LK', 'MY' => 'asia_pacific',
            default => 'other',
        };
    }

    /**
     * Localised name of a dialect or accent code, e.g. "Saudi (Najdi)" / "سعودي (نجدي)".
     * For a woman speaking, the Arabic name is feminine: "سعودية (نجدية)".
     */
    public static function label(?string $dialect, bool $arabic, bool $female = false): string
    {
        $label = self::baseLabel($dialect, $arabic);

        return $arabic && $female ? self::feminine($label) : $label;
    }

    /** Words that stay as they are: places, and words that already agree with فصحى or لكنة. */
    private const KEEP = ['هاواي', 'هاتاي', 'شوا', 'بحارنة', 'جوبا', 'مصر', 'جنوب', 'شرق'];

    /** Describing words that do not end in ي. */
    private const FEMININE = ['عام' => 'عامة', 'قديم' => 'قديمة', 'عريض' => 'عريضة', 'معاصر' => 'معاصرة'];

    /**
     * "سعودي (نجدي)" to "سعودية (نجدية)": each relative adjective takes ة.
     * Place names (with ال, or listed in KEEP) and words already feminine stay.
     */
    public static function feminine(string $label): string
    {
        // Arabic letters, marks and tatweel only: the Arabic comma ends a word.
        return (string) preg_replace_callback('/[\x{0621}-\x{0652}\x{0671}-\x{06D3}]+/u', function (array $m): string {
            $word = $m[0];
            if (str_starts_with($word, 'ال') || in_array($word, self::KEEP, true)) {
                return $word;
            }
            if (isset(self::FEMININE[$word])) {
                return self::FEMININE[$word];
            }

            return preg_match('/[\x{0621}-\x{064A}][\x{064B}-\x{0652}]*ي$/u', $word) === 1 ? $word.'ة' : $word;
        }, $label);
    }

    private static function baseLabel(?string $dialect, bool $arabic): string
    {
        $code = trim((string) $dialect);
        $row = ArabicDialects::ALL[$code] ?? EnglishAccents::ALL[$code] ?? null;
        if ($row === null) {
            foreach (ArabicDialects::ALL + EnglishAccents::ALL as $known => $candidate) {
                if (strcasecmp($known, $code) === 0) {
                    $row = $candidate;
                    break;
                }
            }
        }

        return (string) ($row[$arabic ? 'ar' : 'en'] ?? $code);
    }
}
