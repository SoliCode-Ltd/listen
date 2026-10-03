<?php

namespace App\Support;

/**
 * Country names and the regions the business reports group members into.
 */
class Countries
{
    /** @var array<string, array<int, string>> */
    public const REGIONS = [
        'Gulf (GCC)' => ['SA', 'AE', 'KW', 'QA', 'BH', 'OM'],
        'Egypt' => ['EG'],
        'Levant & Iraq' => ['JO', 'LB', 'SY', 'PS', 'IQ'],
        'North Africa' => ['MA', 'DZ', 'TN', 'LY', 'SD', 'MR'],
        'Other Arab states' => ['YE', 'SO', 'DJ', 'KM'],
        'Europe' => ['GB', 'IE', 'DE', 'FR', 'NL', 'BE', 'LU', 'IT', 'ES', 'PT', 'CH', 'AT', 'SE', 'NO', 'DK', 'FI', 'IS',
            'PL', 'CZ', 'SK', 'HU', 'RO', 'BG', 'GR', 'HR', 'SI', 'RS', 'BA', 'AL', 'MK', 'ME', 'CY', 'MT', 'EE', 'LV', 'LT', 'UA', 'MD'],
        'North America' => ['US', 'CA'],
    ];

    public const UNKNOWN = 'Unknown';

    /** Shorter names where the official one is long. */
    private const SHORT = [
        'AE' => 'UAE',
        'PS' => 'Palestine',
        'GB' => 'United Kingdom',
        'US' => 'United States',
        'SY' => 'Syria',
        'KR' => 'South Korea',
        'RU' => 'Russia',
        'IR' => 'Iran',
        'BA' => 'Bosnia',
        'CD' => 'DR Congo',
    ];

    public static function name(?string $code): string
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return self::UNKNOWN;
        }

        if (isset(self::SHORT[$code])) {
            return self::SHORT[$code];
        }

        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('-'.$code, 'en');
            if (is_string($name) && $name !== '' && $name !== $code) {
                return $name;
            }
        }

        return $code;
    }

    public static function region(?string $code): string
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return self::UNKNOWN;
        }

        foreach (self::REGIONS as $region => $codes) {
            if (in_array($code, $codes, true)) {
                return $region;
            }
        }

        return 'Rest of world';
    }

    public static function isArabWorld(?string $code): bool
    {
        return in_array(self::region($code), ['Gulf (GCC)', 'Egypt', 'Levant & Iraq', 'North Africa', 'Other Arab states'], true);
    }
}
