<?php

namespace App\Support;

use App\Models\SiteSetting;

class SeoHelper
{
    /**
     * Get the full meta title for a page.
     * If a per-page title override exists in the DB, it takes precedence.
     */
    public static function title(string $pageTitle = '', string $page = ''): string
    {
        $template = SiteSetting::get('meta_title_template', '%page_title% | Listen');

        // Check for per-page title override from admin dashboard
        if ($page) {
            $override = SiteSetting::get("seo_{$page}_title");
            if (!empty($override)) {
                $pageTitle = $override;
            }
        }

        if (empty($pageTitle)) {
            return str_replace('%page_title% | ', '', $template);
        }

        return str_replace('%page_title%', $pageTitle, $template);
    }

    /**
     * Get meta description — page-specific override or global default.
     */
    public static function description(string $page = ''): string
    {
        if ($page) {
            $override = SiteSetting::get("seo_{$page}_description");
            if (!empty($override)) {
                return $override;
            }
        }

        return SiteSetting::get('meta_description', 'Listen — Convert text to natural speech with AI-powered TTS.');
    }

    /**
     * Get page-specific title override or return empty string.
     */
    public static function pageTitle(string $page): string
    {
        return SiteSetting::get("seo_{$page}_title", '');
    }

    /**
     * Get canonical URL.
     */
    public static function canonicalUrl(string $path = ''): string
    {
        $base = rtrim(SiteSetting::get('canonical_url', config('app.url')), '/');
        return $base . ($path ? '/' . ltrim($path, '/') : '');
    }

    /**
     * Generate Open Graph meta tags HTML.
     */
    public static function ogTags(string $pageTitle = '', string $page = ''): string
    {
        $title = self::title($pageTitle, $page);
        $description = self::description($page);
        $image = SiteSetting::get('og_image_url', '');
        $url = request()->url();

        $tags = '';
        $tags .= '<meta property="og:type" content="website">' . "\n";
        $tags .= '<meta property="og:title" content="' . e($title) . '">' . "\n";
        $tags .= '<meta property="og:description" content="' . e($description) . '">' . "\n";
        $tags .= '<meta property="og:url" content="' . e($url) . '">' . "\n";

        if (!empty($image)) {
            $tags .= '<meta property="og:image" content="' . e($image) . '">' . "\n";
        }

        return $tags;
    }

    /**
     * Generate Twitter Card meta tags HTML.
     */
    public static function twitterCard(string $pageTitle = '', string $page = ''): string
    {
        $cardType = SiteSetting::get('twitter_card_type', 'summary_large_image');
        $title = self::title($pageTitle, $page);
        $description = self::description($page);
        $handle = SiteSetting::get('twitter_handle', '');
        $image = SiteSetting::get('og_image_url', '');

        $tags = '';
        $tags .= '<meta name="twitter:card" content="' . e($cardType) . '">' . "\n";
        $tags .= '<meta name="twitter:title" content="' . e($title) . '">' . "\n";
        $tags .= '<meta name="twitter:description" content="' . e($description) . '">' . "\n";

        if (!empty($handle)) {
            $tags .= '<meta name="twitter:site" content="@' . e(ltrim($handle, '@')) . '">' . "\n";
        }

        if (!empty($image)) {
            $tags .= '<meta name="twitter:image" content="' . e($image) . '">' . "\n";
        }

        return $tags;
    }

    /**
     * Generate JSON-LD structured data.
     */
    public static function structuredData(): string
    {
        $enabled = SiteSetting::get('enable_structured_data', '1');
        if ($enabled !== '1') {
            return '';
        }

        $siteName = SiteSetting::get('site_name', config('app.name'));
        $description = SiteSetting::get('meta_description', '');
        $url = config('app.url');

        $schema = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Organization',
                    'name' => $siteName,
                    'url' => $url,
                    'description' => $description,
                ],
                [
                    '@type' => 'WebApplication',
                    'name' => $siteName,
                    'url' => $url,
                    'applicationCategory' => 'MultimediaApplication',
                    'operatingSystem' => 'Web',
                    'description' => $description,
                    'offers' => [
                        '@type' => 'Offer',
                        'price' => '0',
                        'priceCurrency' => 'USD',
                    ],
                ],
            ],
        ];

        return '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . '</script>';
    }

    /**
     * Get the default theme value for the data-theme attribute.
     */
    public static function defaultTheme(): string
    {
        $theme = SiteSetting::get('default_theme', 'dark');
        return match ($theme) {
            'light' => 'light',
            'dark' => 'dark',
            default => 'dark', // system = default to dark, JS will override if needed
        };
    }

    /**
     * Get the default theme class (for <html> or <body>).
     */
    public static function themeClass(): string
    {
        $theme = SiteSetting::get('default_theme', 'system');
        return match ($theme) {
            'dark' => 'dark',
            'light' => '',
            default => '', // system = no class, let OS decide
        };
    }

    /**
     * Get custom CSS from admin settings.
     */
    public static function customCss(): string
    {
        return SiteSetting::get('custom_css', '');
    }

    /**
     * Get Google Analytics ID.
     */
    public static function analyticsId(): string
    {
        return SiteSetting::get('google_analytics_id', '');
    }

    /**
     * Generate Google Analytics script tag.
     */
    public static function analyticsScript(): string
    {
        $id = self::analyticsId();
        if (empty($id)) {
            return '';
        }

        return <<<HTML
<script async src="https://www.googletagmanager.com/gtag/js?id={$id}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '{$id}');
</script>
HTML;
    }
}
