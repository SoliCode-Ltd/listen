<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * The one font the whole public website uses, chosen in Admin → Site Settings.
 * Every option covers both Arabic and Latin text, so one font serves both
 * page languages. components/site-fonts loads it and applies it to every
 * element: text, headings, buttons and form fields.
 */
final class SiteFont
{
    public const DEFAULT = 'Alexandria';

    /**
     * Font name => weights to load, in Google Fonts css2 syntax.
     *
     * @var array<string, string>
     */
    public const OPTIONS = [
        'Alexandria' => 'wght@300..800',
        'IBM Plex Sans Arabic' => 'wght@300;400;500;600;700',
        'Cairo' => 'wght@300..800',
        'Tajawal' => 'wght@300;400;500;700;800',
        'Readex Pro' => 'wght@300..700',
        'Noto Kufi Arabic' => 'wght@300..800',
        'Noto Sans Arabic' => 'wght@300..800',
        'Almarai' => 'wght@300;400;700;800',
        'Rubik' => 'wght@300..800',
        'Changa' => 'wght@300..800',
        'Vazirmatn' => 'wght@300..800',
    ];

    public static function current(): string
    {
        $font = (string) SiteSetting::get('site_font', self::DEFAULT);

        return array_key_exists($font, self::OPTIONS) ? $font : self::DEFAULT;
    }

    public static function stylesheetUrl(?string $font = null): string
    {
        $font ??= self::current();

        return 'https://fonts.googleapis.com/css2?family='.str_replace(' ', '+', $font).':'.self::OPTIONS[$font].'&display=swap';
    }

    /**
     * The CSS font-family value: the chosen font, then system fallbacks.
     */
    public static function stack(?string $font = null): string
    {
        return "'".($font ?? self::current())."', system-ui, -apple-system, 'Segoe UI', Tahoma, sans-serif";
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(array_keys(self::OPTIONS), array_keys(self::OPTIONS));
    }
}
