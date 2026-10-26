<?php

namespace App\Support;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;

/**
 * What the admin's SEO settings page shows in a field nobody has saved: the
 * site's own text (the language files, the studio's counts, llms.txt, the
 * landing pages' content). A saved value always wins over the site's text, so
 * saving these unchanged would freeze them: a later improvement to the site's
 * own text would never show. The page therefore keeps only what differs from
 * them, and a saved copy that equals them is let go.
 *
 * Only fields that the site reads the same way whether they are missing or
 * saved with this value are listed (the sitemap paths, for one, are not: left
 * unsaved they add no entries).
 */
final class SeoSettingDefaults
{
    /** The SEO landing pages whose content the admin can edit, and their languages. */
    public const LANDING_PAGES = [
        'ai_text_to_speech' => ['en', 'ar'],
        'ai_voice_generator' => ['en', 'ar'],
        'text_to_speech_arabic' => ['en', 'ar'],
        'text_to_speech' => ['en', 'ar'],
    ];

    /**
     * Field => the value the site uses while the field is not saved.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        // Read once from the table, not through the settings cache: a cached
        // fallback would otherwise read as a saved value for a minute.
        $saved = SiteSetting::query()->pluck('value', 'key')->all();
        // As the pages read them (SeoHelper): an empty saved value counts as none,
        // an "_en" field falls back to the older shared one, and an Arabic field
        // takes only Arabic text.
        $shared = function (string $key, bool $arabicOnly, string $fallback) use ($saved): string {
            $value = trim((string) ($saved[$key] ?? ''));

            return $value !== '' && (! $arabicOnly || self::isArabic($value)) ? $value : $fallback;
        };
        $siteDescription = fn (string $locale): string => $shared("site_seo_description_{$locale}", $locale === 'ar', self::siteDescription($saved, $locale));

        $defaults = [
            'meta_description_en' => $shared('meta_description', false, $siteDescription('en')),
            'meta_description_ar' => $shared('meta_description', true, $siteDescription('ar')),
            'site_seo_description_en' => self::siteDescription($saved, 'en'),
            'site_seo_description_ar' => self::siteDescription($saved, 'ar'),
            'robots_txt' => SeoHelper::defaultRobotsTxt(),
            'sitemap_excluded_paths' => SeoHelper::defaultSitemapExcludedPathsText(),
            'llms_txt' => SeoHelper::defaultLlmsTxt(),
        ];

        foreach (SeoHelper::adminPageDefinitions() as $page => $meta) {
            // A page's view can pass its own title (billing shows the pack's name),
            // and only the public pages are known to pass the default one.
            $titles = (bool) ($meta['indexable'] ?? false);
            if ($titles) {
                $defaults["seo_{$page}_title_en"] = $shared("seo_{$page}_title", false, self::pageText($page, 'title', 'en', (string) ($meta['title'] ?? '')));
            }
            $defaults["seo_{$page}_description_en"] = $shared("seo_{$page}_description", false, self::pageText($page, 'description', 'en', (string) ($meta['description'] ?? '')));
            $defaults["seo_{$page}_keywords_en"] = $shared("seo_{$page}_keywords", false, '');
            $defaults["seo_{$page}_robots"] = (string) ($meta['robots'] ?? '');
            if ((bool) ($meta['indexable'] ?? false)) {
                $defaults["seo_{$page}_sitemap_priority"] = (string) ($meta['sitemap_priority'] ?? '0.5');
                $defaults["seo_{$page}_sitemap_change_frequency"] = (string) ($meta['sitemap_change_frequency'] ?? 'weekly');
            }
            if (in_array('ar', SeoHelper::pageLocales($page), true)) {
                if ($titles) {
                    $defaults["seo_{$page}_title_ar"] = $shared("seo_{$page}_title", true, self::pageText($page, 'title', 'ar'));
                }
                $defaults["seo_{$page}_description_ar"] = $shared("seo_{$page}_description", true, self::pageText($page, 'description', 'ar'));
                $defaults["seo_{$page}_keywords_ar"] = $shared("seo_{$page}_keywords", false, '');
            }
        }

        foreach (self::LANDING_PAGES as $page => $locales) {
            foreach ($locales as $locale) {
                $defaults["seo_landing_{$page}_content_{$locale}"] = self::landingJson($page, $locale);
            }
        }

        return $defaults;
    }

    /** Whether a value says the same as the field's default (line endings, spaces at the ends and JSON layout aside). */
    public static function matches(string $key, string $value, string $default): bool
    {
        if (str_starts_with($key, 'seo_landing_')) {
            $saved = json_decode($value, true);
            $own = json_decode($default, true);
            if (is_array($saved) && is_array($own)) {
                return $saved === $own;
            }
        }
        $normal = fn (string $text): string => trim(str_replace(["\r\n", "\r"], "\n", $text));

        return $normal($value) === $normal($default);
    }

    /** Removes a saved value, so the site's own text is used again. */
    public static function forget(string $key): void
    {
        SiteSetting::query()->where('key', $key)->delete();
        Cache::forget("site_setting.{$key}");
    }

    /**
     * Lets go of every saved value that only repeats its default, and returns
     * their keys. Nothing a page shows changes; the site's text can change again.
     *
     * @return array<int, string>
     */
    public static function forgetSavedCopies(): array
    {
        $defaults = self::all();
        $saved = SiteSetting::query()->whereIn('key', array_keys($defaults))->pluck('value', 'key')->all();

        $forgotten = [];
        foreach ($saved as $key => $value) {
            if (self::matches($key, (string) $value, $defaults[$key])) {
                self::forget($key);
                $forgotten[] = $key;
            }
        }
        SiteSetting::flushGroupCache('seo');

        return $forgotten;
    }

    /** A page's title or description from the language files, else the given fallback. */
    public static function pageText(string $page, string $field, string $locale, string $fallback = ''): string
    {
        $definition = SeoHelper::pageDefinitions()[$page] ?? [];
        $translationKey = (string) ($definition["{$field}_key"] ?? '');

        if ($translationKey !== '') {
            $translated = trim((string) __($translationKey, SeoHelper::textCounts(), $locale));
            if ($translated !== '' && $translated !== $translationKey) {
                return $translated;
            }
        }

        return $fallback;
    }

    /** A landing page's own content, as the admin edits it. */
    public static function landingJson(string $page, string $locale): string
    {
        $content = SeoHelper::landingPageContentDefaults()[$page][$locale] ?? null;
        if (! is_array($content) || $content === []) {
            return '';
        }

        return (string) (json_encode(
            $content,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        ) ?: '');
    }

    /**
     * The site description used while site_seo_description_{locale} is not saved:
     * the older shared setting when it has one, else the site's own.
     *
     * @param  array<string, mixed>  $saved
     */
    private static function siteDescription(array $saved, string $locale): string
    {
        $shared = trim((string) ($saved['site_seo_description'] ?? ''));
        if ($shared !== '' && ($locale !== 'ar' || self::isArabic($shared))) {
            return $shared;
        }

        return SeoHelper::defaultSiteDescription($locale);
    }

    private static function isArabic(string $text): bool
    {
        return preg_match('/\p{Arabic}/u', $text) === 1;
    }
}
