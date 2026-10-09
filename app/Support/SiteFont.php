<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * The one font the whole public website uses, and how big its letters are,
 * both chosen in Admin → Site Settings. Every option covers Arabic and Latin
 * text, so one font serves both page languages. components/site-fonts loads
 * it and applies it to every element: text, headings, buttons and forms.
 *
 * Fonts draw letters at very different sizes for the same font-size, so each
 * option carries its measured size against the site's original fonts (IBM
 * Plex Sans and IBM Plex Sans Arabic). A text size of 100% makes any font look
 * as big as the original design; the browser scales only the letters
 * (font-size-adjust), not spacing or layout.
 */
final class SiteFont
{
    public const DEFAULT = 'Alexandria';

    public const DEFAULT_SIZE = 100;

    /** Text sizes offered in the admin, in percent of the original design. */
    public const SIZES = [80, 85, 90, 95, 100, 105, 110, 115, 120];

    /**
     * Font name => weights to load (Google Fonts css2 syntax), its x-height as
     * a share of the font size, and how wide it sets Arabic and English text
     * compared with the original fonts (1.0 = the same).
     *
     * @var array<string, array{weights: string, ex: float, ar: float, en: float}>
     */
    public const OPTIONS = [
        'Alexandria' => ['weights' => 'wght@300..800', 'ex' => 0.531, 'ar' => 1.215, 'en' => 1.122],
        'IBM Plex Sans Arabic' => ['weights' => 'wght@300;400;500;600;700', 'ex' => 0.516, 'ar' => 1.0, 'en' => 1.0],
        'Cairo' => ['weights' => 'wght@300..800', 'ex' => 0.500, 'ar' => 1.101, 'en' => 0.946],
        'Tajawal' => ['weights' => 'wght@300;400;500;700;800', 'ex' => 0.454, 'ar' => 1.103, 'en' => 0.942],
        'Readex Pro' => ['weights' => 'wght@300..700', 'ex' => 0.525, 'ar' => 1.203, 'en' => 1.073],
        'Noto Kufi Arabic' => ['weights' => 'wght@300..800', 'ex' => 0.536, 'ar' => 1.193, 'en' => 1.084],
        'Noto Sans Arabic' => ['weights' => 'wght@300..800', 'ex' => 0.536, 'ar' => 1.067, 'en' => 1.057],
        'Almarai' => ['weights' => 'wght@300;400;700;800', 'ex' => 0.514, 'ar' => 1.097, 'en' => 0.948],
        'Rubik' => ['weights' => 'wght@300..800', 'ex' => 0.520, 'ar' => 1.116, 'en' => 1.047],
        'Changa' => ['weights' => 'wght@300..800', 'ex' => 0.500, 'ar' => 1.166, 'en' => 1.001],
        'Vazirmatn' => ['weights' => 'wght@300..800', 'ex' => 0.528, 'ar' => 1.059, 'en' => 0.996],
    ];

    public static function current(): string
    {
        $font = (string) SiteSetting::get('site_font', self::DEFAULT);

        return array_key_exists($font, self::OPTIONS) ? $font : self::DEFAULT;
    }

    /**
     * The chosen text size in percent of the original design.
     */
    public static function size(): int
    {
        $size = (int) SiteSetting::get('site_font_size', self::DEFAULT_SIZE);

        return in_array($size, self::SIZES, true) ? $size : self::DEFAULT_SIZE;
    }

    public static function stylesheetUrl(?string $font = null): string
    {
        $font ??= self::current();

        return 'https://fonts.googleapis.com/css2?family='.str_replace(' ', '+', $font).':'.self::OPTIONS[$font]['weights'].'&display=swap';
    }

    /**
     * The CSS font-family value: the chosen font, then system fallbacks.
     */
    public static function stack(?string $font = null): string
    {
        return "'".($font ?? self::current())."', system-ui, -apple-system, 'Segoe UI', Tahoma, sans-serif";
    }

    /**
     * The font-size-adjust value that draws the font's letters at the chosen
     * size, for Arabic ('ar') or English ('en') pages.
     */
    public static function sizeAdjust(string $script, ?string $font = null, ?int $size = null): float
    {
        $metrics = self::OPTIONS[$font ?? self::current()];
        $width = $script === 'ar' ? $metrics['ar'] : $metrics['en'];

        return round($metrics['ex'] / $width * ($size ?? self::size()) / 100, 3);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(array_keys(self::OPTIONS), array_keys(self::OPTIONS));
    }

    /**
     * @return array<int, string>
     */
    public static function sizeOptions(): array
    {
        $options = [];
        foreach (self::SIZES as $size) {
            $options[$size] = $size.'%'.($size === self::DEFAULT_SIZE ? ' (same as the original design)' : '');
        }

        return $options;
    }
}
