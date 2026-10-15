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
     */
    public static function label(?string $dialect, bool $arabic): string
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
