<?php

namespace App\Support;

use App\Models\SiteSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SeoHelper
{
    private const DEFAULT_META_ROBOTS = 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1';

    private const ALLOWED_CHANGE_FREQUENCIES = [
        'always',
        'hourly',
        'daily',
        'weekly',
        'monthly',
        'yearly',
        'never',
    ];

    /**
     * Canonical page registry used by SEO settings, sitemap generation, and fallback metadata.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function pageDefinitions(): array
    {
        return [
            'home' => [
                'label' => 'Home',
                'title' => 'Listen AI Text to Speech',
                'title_key' => 'ui.seo.home_title',
                'description' => 'Convert text into natural, multilingual speech with premium Arabic and English AI voices.',
                'description_key' => 'ui.seo.home_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 1.0,
                'sitemap_change_frequency' => 'daily',
            ],
            'pricing' => [
                'label' => 'Pricing',
                'title' => 'AI Text to Speech Pricing',
                'title_key' => 'ui.seo.pricing_title',
                'description' => 'Compare Listen plans and choose the right AI voice generation package for your workflow.',
                'description_key' => 'ui.seo.pricing_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/pricing',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 0.9,
                'sitemap_change_frequency' => 'weekly',
            ],
            'contact' => [
                'label' => 'Contact',
                'title' => 'Contact Listen',
                'title_key' => 'ui.seo.contact_title',
                'description' => 'Contact the Listen team for support, sales questions, and partnership inquiries.',
                'description_key' => 'ui.seo.contact_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/contact-us',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 0.7,
                'sitemap_change_frequency' => 'monthly',
            ],
            'extension' => [
                'label' => 'Browser Extension',
                'title' => 'Text to Speech Browser Extension',
                'title_key' => 'ui.seo.extension_title',
                'description' => 'Turn web pages into natural audio with the Listen browser extension for Arabic and English.',
                'description_key' => 'ui.seo.extension_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/browsers-extension',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 0.8,
                'sitemap_change_frequency' => 'weekly',
            ],
            'privacy' => [
                'label' => 'Privacy Policy',
                'title' => 'Privacy Policy',
                'title_key' => 'ui.seo.privacy_title',
                'description' => 'Read the Listen privacy policy to understand how user and usage data is handled.',
                'description_key' => 'ui.seo.privacy_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/privacy-policy',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 0.3,
                'sitemap_change_frequency' => 'yearly',
            ],
            'terms' => [
                'label' => 'Terms of Service',
                'title' => 'Terms of Service',
                'title_key' => 'ui.seo.terms_title',
                'description' => 'Review the legal terms that govern using Listen services and products.',
                'description_key' => 'ui.seo.terms_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/terms-of-service',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 0.3,
                'sitemap_change_frequency' => 'yearly',
            ],
            'cookies' => [
                'label' => 'Cookie Policy',
                'title' => 'Cookie Policy',
                'title_key' => 'ui.seo.cookies_title',
                'description' => 'Learn how Listen uses cookies and related technologies across the website.',
                'description_key' => 'ui.seo.cookies_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/cookie-policy',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 0.3,
                'sitemap_change_frequency' => 'yearly',
            ],
            'ai_text_to_speech' => [
                'label' => 'AI Text to Speech',
                'title' => 'AI Text to Speech',
                'title_key' => 'ui.seo.ai_text_to_speech_title',
                'description' => 'Convert text to lifelike audio with Arabic and English AI text to speech built for creators and teams.',
                'description_key' => 'ui.seo.ai_text_to_speech_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/ai-text-to-speech',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 0.85,
                'sitemap_change_frequency' => 'weekly',
            ],
            'ai_voice_generator' => [
                'label' => 'AI Voice Generator',
                'title' => 'AI Voice Generator',
                'title_key' => 'ui.seo.ai_voice_generator_title',
                'description' => 'Generate premium AI voices for narration, product videos, explainers, and multilingual content.',
                'description_key' => 'ui.seo.ai_voice_generator_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/ai-voice-generator',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 0.85,
                'sitemap_change_frequency' => 'weekly',
            ],
            'text_to_speech_arabic' => [
                'label' => 'Arabic Text to Speech',
                'title' => 'Arabic Text to Speech',
                'title_key' => 'ui.seo.text_to_speech_arabic_title',
                'description' => 'Create natural Arabic text to speech audio with premium voices for content, products, and accessibility.',
                'description_key' => 'ui.seo.text_to_speech_arabic_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/text-to-speech-arabic',
                'localized' => false,
                'allowed_locales' => ['en'],
                'indexable' => true,
                'sitemap_priority' => 0.82,
                'sitemap_change_frequency' => 'weekly',
            ],
            'demo' => [
                'label' => 'Demo',
                'title' => 'Demo',
                'title_key' => 'ui.seo.demo_title',
                'description' => 'Try Listen text to speech online and hear instant voice previews in multiple languages.',
                'description_key' => 'ui.seo.demo_description',
                'robots' => 'noindex,nofollow,noarchive,nosnippet',
                'path' => '/demo',
                'localized' => true,
                'indexable' => false,
            ],
            'login' => [
                'label' => 'Login',
                'title' => 'Sign In',
                'title_key' => 'ui.seo.login_title',
                'description' => 'Sign in to your Listen account and continue your voice generation workflow.',
                'description_key' => 'ui.seo.login_description',
                'robots' => 'noindex,nofollow,noarchive,nosnippet',
                'path' => '/login',
                'localized' => true,
                'indexable' => false,
            ],
            'register' => [
                'label' => 'Register',
                'title' => 'Create Account',
                'title_key' => 'ui.seo.register_title',
                'description' => 'Create a Listen account and start converting text into high-quality AI speech.',
                'description_key' => 'ui.seo.register_description',
                'robots' => 'noindex,nofollow,noarchive,nosnippet',
                'path' => '/register',
                'localized' => true,
                'indexable' => false,
            ],
            'dashboard' => [
                'label' => 'Dashboard',
                'title' => 'Dashboard',
                'title_key' => 'ui.seo.dashboard_title',
                'description' => 'Manage audio jobs, voices, and account usage from your Listen dashboard.',
                'description_key' => 'ui.seo.dashboard_description',
                'robots' => 'noindex,nofollow,noarchive,nosnippet',
                'path' => '/dashboard',
                'localized' => true,
                'indexable' => false,
            ],
            'billing' => [
                'label' => 'Billing',
                'title' => 'Billing',
                'title_key' => 'ui.seo.billing_title',
                'description' => 'Manage Listen billing details, invoices, and subscription settings.',
                'description_key' => 'ui.seo.billing_description',
                'robots' => 'noindex,nofollow,noarchive,nosnippet',
                'path' => '/billing',
                'localized' => true,
                'indexable' => false,
            ],
            'manage_subscription' => [
                'label' => 'Manage Subscription',
                'title' => 'Manage Subscription',
                'title_key' => 'ui.seo.manage_subscription_title',
                'description' => 'Update or cancel your Listen subscription and payment preferences.',
                'description_key' => 'ui.seo.manage_subscription_description',
                'robots' => 'noindex,nofollow,noarchive,nosnippet',
                'path' => '/manage-subscription',
                'localized' => true,
                'indexable' => false,
            ],
            'upgrade' => [
                'label' => 'Upgrade',
                'title' => 'Upgrade',
                'title_key' => 'ui.seo.upgrade_title',
                'description' => 'Upgrade your Listen plan to unlock more monthly voice generation capacity.',
                'description_key' => 'ui.seo.upgrade_description',
                'robots' => 'noindex,nofollow,noarchive,nosnippet',
                'path' => '/upgrade',
                'localized' => true,
                'indexable' => false,
            ],
        ];
    }

    /**
     * Build default sitemap entries.
     *
     * @return array<int, array{path:string,priority:float,change_frequency:string}>
     */
    public static function defaultSitemapEntries(): array
    {
        return [];
    }

    /**
     * Default sitemap text shown in dashboard.
     */
    public static function defaultSitemapEntriesText(): string
    {
        return implode("\n", array_map(
            fn (array $entry) => "{$entry['path']}|{$entry['priority']}|{$entry['change_frequency']}",
            self::defaultSitemapEntries()
        ));
    }

    /**
     * Parse sitemap entries from dashboard setting.
     *
     * Each line format: /path|priority|change_frequency
     *
     * @return array<int, array{path:string,priority:float,change_frequency:string}>
     */
    public static function sitemapEntries(): array
    {
        $raw = trim((string) SiteSetting::get('sitemap_paths', ''));
        if ($raw === '') {
            return [];
        }

        $entries = [];
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            $path = (string) ($parts[0] ?? '');
            if ($path === '') {
                continue;
            }

            $priority = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : 0.5;
            $priority = max(0.0, min(1.0, $priority));

            $frequency = strtolower((string) ($parts[2] ?? 'weekly'));
            if (!in_array($frequency, self::ALLOWED_CHANGE_FREQUENCIES, true)) {
                $frequency = 'weekly';
            }

            if (!Str::startsWith($path, ['http://', 'https://'])) {
                $path = '/' . ltrim($path, '/');
            }

            $entries[] = [
                'path' => $path,
                'priority' => $priority,
                'change_frequency' => $frequency,
            ];
        }

        return $entries;
    }

    /**
     * @return array<int, array{page:string,locale:string,url:string,priority:float,change_frequency:string,lastmod:Carbon,alternates:array<string,string>}>
     */
    public static function publicSitemapEntries(): array
    {
        $entries = [];

        foreach (self::pageDefinitions() as $page => $definition) {
            if (! ($definition['indexable'] ?? false)) {
                continue;
            }

            foreach (self::pageLocales($page) as $locale) {
                $entries[] = [
                    'page' => $page,
                    'locale' => $locale,
                    'url' => self::pageUrl($page, $locale),
                    'priority' => (float) ($definition['sitemap_priority'] ?? 0.5),
                    'change_frequency' => (string) ($definition['sitemap_change_frequency'] ?? 'weekly'),
                    'lastmod' => self::pageLastModified($page),
                    'alternates' => self::hreflangMap($page),
                ];
            }
        }

        foreach (self::sitemapEntries() as $entry) {
            $entries[] = [
                'page' => '',
                'locale' => '',
                'url' => Str::startsWith($entry['path'], ['http://', 'https://']) ? $entry['path'] : self::canonicalUrl($entry['path']),
                'priority' => (float) $entry['priority'],
                'change_frequency' => (string) $entry['change_frequency'],
                'lastmod' => self::siteLastModified(),
                'alternates' => [],
            ];
        }

        return $entries;
    }

    /**
     * Default robots.txt.
     */
    public static function defaultRobotsTxt(): string
    {
        return implode("\n", [
            'User-agent: *',
            'Allow: /',
            '',
            'Sitemap: ' . url('/sitemap.xml'),
        ]);
    }

    /**
     * Default llms.txt content.
     */
    public static function defaultLlmsTxt(): string
    {
        $base = rtrim((string) SiteSetting::get('canonical_url', config('app.url')), '/');
        $siteName = self::siteName('en');
        $description = self::siteDescription('en');

        return implode("\n", [
            "# {$siteName}",
            '',
            "> {$description}",
            '',
            '## Official URLs',
            "- English Home: {$base}/en",
            "- Arabic Home: {$base}/ar",
            "- Pricing: {$base}/en/pricing",
            "- Contact: {$base}/en/contact-us",
            "- Browser Extension: {$base}/en/browsers-extension",
            '',
            '## Sitemap',
            "- {$base}/sitemap.xml",
            '',
            '## Notes For AI Agents',
            '- Prefer canonical URLs from this domain.',
            '- Respect robots.txt and per-page robots directives.',
            '- Treat English and Arabic localized pages as language alternates, not duplicates.',
        ]);
    }

    /**
     * Whether llms.txt endpoint is enabled.
     */
    public static function llmsTxtEnabled(): bool
    {
        return SiteSetting::get('enable_llms_txt', '1') === '1';
    }

    /**
     * Get llms.txt content from settings or fallback.
     */
    public static function llmsTxtContent(): string
    {
        $content = trim((string) SiteSetting::get('llms_txt', ''));
        if ($content === '') {
            return self::defaultLlmsTxt();
        }

        return $content;
    }

    /**
     * Get the full meta title for a page.
     * If a per-page title override exists in the DB, it takes precedence.
     */
    public static function title(string $pageTitle = '', string $page = '', ?string $locale = null): string
    {
        $locale = self::normalizeLocale($locale);
        $template = self::localizedTitleTemplate($locale);

        if ($page !== '') {
            $override = self::localizedOverride("seo_{$page}_title", $locale, $locale === 'ar');
            if ($override !== '') {
                $pageTitle = $override;
            }
        }

        $pageTitle = trim($pageTitle);
        if ($pageTitle === '') {
            $pageTitle = self::localizedDefault('title', $page, $locale);
        }

        if ($pageTitle === '') {
            $pageTitle = self::siteName($locale);
        }

        return str_replace(
            ['%page_title%', '%brand_name%'],
            [$pageTitle, self::localizedBrandName($locale)],
            $template
        );
    }

    private static function localizedTitleTemplate(string $locale): string
    {
        $template = self::localizedOverride('meta_title_template', $locale, $locale === 'ar');
        if ($template === '') {
            $template = '%page_title% - %brand_name%';
        } elseif ($locale === 'ar' && in_array(trim($template), ['%page_title% - استمع', '%page_title% - إسمع', '%page_title% - Listen'], true)) {
            $template = '%page_title% - منصة إسمع';
        }

        return (string) preg_replace('/\s*\|\s*/u', ' - ', $template);
    }

    private static function localizedBrandName(string $locale): string
    {
        return self::siteName($locale);
    }

    private static function localizedDefault(string $field, string $page, string $locale): string
    {
        $definition = self::pageDefinitions()[$page] ?? null;
        if (! is_array($definition)) {
            return '';
        }

        $translationKey = (string) ($definition["{$field}_key"] ?? '');
        if ($translationKey !== '') {
            $translated = trim((string) __($translationKey, [], $locale));
            if ($translated !== '' && $translated !== $translationKey) {
                return $translated;
            }
        }

        return trim((string) ($definition[$field] ?? ''));
    }

    private static function containsArabic(string $value): bool
    {
        return (bool) preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u', $value);
    }

    /**
     * Get meta description - page-specific override or global default.
     */
    public static function description(string $page = '', ?string $locale = null): string
    {
        $locale = self::normalizeLocale($locale);

        if ($page !== '') {
            $override = self::localizedOverride("seo_{$page}_description", $locale, $locale === 'ar');
            if ($override !== '') {
                return $override;
            }
        }

        $global = self::localizedOverride('meta_description', $locale, $locale === 'ar');
        if ($global !== '') {
            return $global;
        }

        $fallback = self::localizedDefault('description', $page, $locale);

        return $fallback !== '' ? $fallback : self::siteDescription($locale);
    }

    /**
     * Get meta keywords.
     */
    public static function keywords(string $page = '', ?string $locale = null): string
    {
        $locale = self::normalizeLocale($locale);

        if ($page !== '') {
            $override = self::localizedOverride("seo_{$page}_keywords", $locale);
            if ($override !== '') {
                return $override;
            }
        }

        return self::localizedOverride('meta_keywords', $locale);
    }

    /**
     * Get robots directive for page.
     */
    public static function robots(string $page = ''): string
    {
        if ($page) {
            $override = trim((string) SiteSetting::get("seo_{$page}_robots", ''));
            if ($override !== '') {
                return $override;
            }

            $fallback = self::pageDefinitions()[$page]['robots'] ?? '';
            if ($fallback !== '') {
                return $fallback;
            }
        }

        return (string) SiteSetting::get('meta_robots', self::DEFAULT_META_ROBOTS);
    }

    /**
     * Get meta author value.
     */
    public static function author(): string
    {
        return trim((string) SiteSetting::get('meta_author', ''));
    }

    /**
     * Get page-specific title override or return empty string.
     */
    public static function pageTitle(string $page, ?string $locale = null): string
    {
        return self::localizedOverride("seo_{$page}_title", self::normalizeLocale($locale));
    }

    /**
     * Get canonical URL.
     */
    public static function canonicalUrl(string $path = '', ?string $locale = null): string
    {
        $base = rtrim((string) SiteSetting::get('canonical_url', config('app.url')), '/');
        $normalizedPath = self::normalizePath($path, $locale);

        return $normalizedPath === '/' ? $base . $normalizedPath : $base . rtrim($normalizedPath, '/');
    }

    public static function currentCanonicalUrl(?string $locale = null): string
    {
        return self::canonicalUrl(self::currentLocalizedPath($locale), $locale);
    }

    public static function pageUrl(string $page, ?string $locale = null): string
    {
        $definition = self::pageDefinitions()[$page] ?? null;
        if (! is_array($definition)) {
            return '';
        }

        return self::canonicalUrl((string) ($definition['path'] ?? '/'), $locale);
    }

    public static function hreflangMap(string $page = ''): array
    {
        $page = $page !== '' ? $page : self::resolveCurrentPageKey();
        if ($page === '') {
            return [];
        }

        $links = [];
        foreach (self::pageLocales($page) as $locale) {
            $links[$locale] = self::pageUrl($page, $locale);
        }

        if (isset($links['en'])) {
            $links['x-default'] = $links['en'];
        }

        return $links;
    }

    public static function hreflangTags(string $page = ''): string
    {
        $tags = [];

        foreach (self::hreflangMap($page) as $locale => $url) {
            $tags[] = '<link rel="alternate" hreflang="' . e($locale) . '" href="' . e($url) . '">';
        }

        return implode("\n", $tags);
    }

    /**
     * Generate Open Graph meta tags HTML.
     */
    public static function ogTags(string $pageTitle = '', string $page = ''): string
    {
        $locale = self::currentLocale();
        $title = self::title($pageTitle, $page, $locale);
        $description = self::description($page, $locale);
        $image = self::normalizeMediaUrl((string) SiteSetting::get('og_image_url', ''));
        $siteName = self::siteName($locale);
        $type = (string) SiteSetting::get('og_type', 'website');
        $tags = [
            '<meta property="og:type" content="' . e($type) . '">',
            '<meta property="og:site_name" content="' . e($siteName) . '">',
            '<meta property="og:locale" content="' . e(self::ogLocale($locale)) . '">',
            '<meta property="og:title" content="' . e($title) . '">',
            '<meta property="og:description" content="' . e($description) . '">',
            '<meta property="og:url" content="' . e(self::currentCanonicalUrl($locale)) . '">',
        ];

        foreach (self::supportedLocales() as $alternateLocale) {
            if ($alternateLocale === $locale) {
                continue;
            }

            $tags[] = '<meta property="og:locale:alternate" content="' . e(self::ogLocale($alternateLocale)) . '">';
        }

        if ($image !== '') {
            $tags[] = '<meta property="og:image" content="' . e($image) . '">';
        }

        return implode("\n", $tags);
    }

    /**
     * Generate Twitter Card meta tags HTML.
     */
    public static function twitterCard(string $pageTitle = '', string $page = ''): string
    {
        $locale = self::currentLocale();
        $cardType = (string) SiteSetting::get('twitter_card_type', 'summary_large_image');
        $title = self::title($pageTitle, $page, $locale);
        $description = self::description($page, $locale);
        $handle = trim((string) SiteSetting::get('twitter_handle', ''));
        $creator = trim((string) SiteSetting::get('twitter_creator', ''));
        $image = self::normalizeMediaUrl((string) SiteSetting::get('og_image_url', ''));
        $tags = [
            '<meta name="twitter:card" content="' . e($cardType) . '">',
            '<meta name="twitter:title" content="' . e($title) . '">',
            '<meta name="twitter:description" content="' . e($description) . '">',
            '<meta name="twitter:url" content="' . e(self::currentCanonicalUrl($locale)) . '">',
        ];

        if ($handle !== '') {
            $tags[] = '<meta name="twitter:site" content="@' . e(ltrim($handle, '@')) . '">';
        }

        if ($creator !== '') {
            $tags[] = '<meta name="twitter:creator" content="@' . e(ltrim($creator, '@')) . '">';
        }

        if ($image !== '') {
            $tags[] = '<meta name="twitter:image" content="' . e($image) . '">';
        }

        return implode("\n", $tags);
    }

    /**
     * Generate JSON-LD structured data.
     */
    public static function structuredData(array $faqItems = [], string $pageTitle = '', string $page = ''): string
    {
        if (SiteSetting::get('enable_structured_data', '1') !== '1') {
            return '';
        }

        $locale = self::currentLocale();
        $siteName = self::siteName($locale);
        $description = self::description($page, $locale);
        $url = self::currentCanonicalUrl($locale);
        $logo = self::normalizeMediaUrl((string) SiteSetting::get('logo_url', ''));
        $graph = [
            array_filter([
                '@type' => 'Organization',
                'name' => $siteName,
                'url' => self::canonicalUrl('/' . self::defaultHreflangLocale(), self::defaultHreflangLocale()),
                'description' => self::siteDescription($locale),
                'logo' => $logo !== '' ? $logo : null,
            ], static fn ($value) => $value !== null && $value !== ''),
            [
                '@type' => 'WebSite',
                'name' => $siteName,
                'url' => self::canonicalUrl('/' . self::defaultHreflangLocale(), self::defaultHreflangLocale()),
                'description' => self::siteDescription($locale),
                'inLanguage' => self::schemaLanguage($locale),
            ],
            [
                '@type' => 'WebPage',
                'name' => self::title($pageTitle, $page, $locale),
                'url' => $url,
                'description' => $description,
                'inLanguage' => self::schemaLanguage($locale),
            ],
        ];

        if ($faqItems !== []) {
            $questions = [];
            foreach ($faqItems as $item) {
                $question = trim((string) ($item['question'] ?? ''));
                $answer = trim((string) ($item['answer'] ?? ''));
                if ($question === '' || $answer === '') {
                    continue;
                }

                $questions[] = [
                    '@type' => 'Question',
                    'name' => $question,
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $answer,
                    ],
                ];
            }

            if ($questions !== []) {
                $graph[] = [
                    '@type' => 'FAQPage',
                    'mainEntity' => $questions,
                    'inLanguage' => self::schemaLanguage($locale),
                ];
            }
        }

        return '<script type="application/ld+json">' . json_encode([
            '@context' => 'https://schema.org',
            '@graph' => $graph,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
    }

    public static function verificationTags(): string
    {
        $map = [
            'google_site_verification' => 'google-site-verification',
            'bing_site_verification' => 'msvalidate.01',
            'yandex_site_verification' => 'yandex-verification',
            'baidu_site_verification' => 'baidu-site-verification',
        ];

        $tags = [];
        foreach ($map as $key => $metaName) {
            $value = trim((string) SiteSetting::get($key, ''));
            if ($value === '') {
                continue;
            }

            $tags[] = '<meta name="' . e($metaName) . '" content="' . e($value) . '">';
        }

        return implode("\n", $tags);
    }

    public static function llmsDiscoveryTag(): string
    {
        if (! self::llmsTxtEnabled()) {
            return '';
        }

        return '<link rel="alternate" type="text/plain" title="LLMs" href="' . e(url('/llms.txt')) . '">';
    }

    public static function faviconMetaTags(): string
    {
        $svg = self::versionedAssetUrl(self::svgFaviconUrl());
        $icon = self::versionedAssetUrl(self::primaryFaviconUrl());
        $appleTouch = self::versionedAssetUrl(
            self::normalizeMediaUrl((string) SiteSetting::get('seo_apple_touch_icon_url', ''))
            ?: self::publicAssetUrl('apple-touch-icon.png')
            ?: $icon
            ?: $svg
        );
        $manifest = trim((string) SiteSetting::get('seo_manifest_url', ''));
        $manifest = $manifest !== '' ? self::normalizeMediaUrl($manifest) : url('/site.webmanifest');
        $themeColor = trim((string) SiteSetting::get('seo_favicon_theme_color', '#0f172a'));
        $tileColor = trim((string) SiteSetting::get('seo_favicon_tile_color', '#0f172a'));

        $tags = [];
        if ($svg !== '') {
            $tags[] = '<link rel="icon" type="image/svg+xml" sizes="any" href="' . e($svg) . '">';
        }
        if ($icon !== '' && $icon !== $svg) {
            $tags[] = '<link rel="icon" href="' . e($icon) . '">';
            if (! str_ends_with(strtolower(parse_url($icon, PHP_URL_PATH) ?? ''), '.svg')) {
                $tags[] = '<link rel="shortcut icon" href="' . e($icon) . '">';
            }
        }
        if ($appleTouch !== '') {
            $tags[] = '<link rel="apple-touch-icon" href="' . e($appleTouch) . '">';
        }
        if ($manifest !== '') {
            $tags[] = '<link rel="manifest" href="' . e($manifest) . '">';
        }
        $tags[] = '<meta name="msapplication-config" content="none">';
        if ($themeColor !== '') {
            $tags[] = '<meta name="theme-color" content="' . e($themeColor) . '">';
        }
        if ($tileColor !== '') {
            $tags[] = '<meta name="msapplication-TileColor" content="' . e($tileColor) . '">';
        }

        return implode("\n", $tags);
    }

    public static function defaultTheme(): string
    {
        $theme = SiteSetting::get('default_theme', 'dark');
        return match ($theme) {
            'light' => 'light',
            'dark' => 'dark',
            default => 'dark',
        };
    }

    public static function themeClass(): string
    {
        $theme = SiteSetting::get('default_theme', 'system');
        return match ($theme) {
            'dark' => 'dark',
            'light' => '',
            default => '',
        };
    }

    public static function customCss(): string
    {
        return (string) SiteSetting::get('custom_css', '');
    }

    public static function analyticsId(): string
    {
        return trim((string) SiteSetting::get('google_analytics_id', ''));
    }

    public static function googleTagId(): string
    {
        return trim((string) SiteSetting::get('google_tag_id', ''));
    }

    public static function googleTagHeadCode(): string
    {
        return trim((string) SiteSetting::get('google_tag_head_code', ''));
    }

    public static function analyticsScript(): string
    {
        $customCode = self::googleTagHeadCode();
        if ($customCode !== '') {
            return $customCode;
        }

        $ids = array_values(array_unique(array_filter([
            self::analyticsId(),
            self::googleTagId(),
        ], static fn (string $id): bool => $id !== '')));

        if ($ids === []) {
            return '';
        }

        $primaryId = $ids[0];
        $configLines = implode("\n", array_map(
            static fn (string $id): string => "  gtag('config', '{$id}');",
            $ids
        ));

        return <<<HTML
<script async src="https://www.googletagmanager.com/gtag/js?id={$primaryId}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
{$configLines}
</script>
HTML;
    }

    public static function facebookPixelScript(): string
    {
        return trim((string) SiteSetting::get('facebook_pixel_code', ''));
    }

    public static function siteWebmanifest(): array
    {
        $name = self::siteName('en');
        $shortName = trim((string) SiteSetting::get('seo_manifest_short_name', $name));
        $icons = [];

        foreach (array_filter([
            self::svgFaviconUrl(),
            self::primaryFaviconUrl(),
            self::normalizeMediaUrl((string) SiteSetting::get('seo_apple_touch_icon_url', '')),
            self::publicAssetUrl('apple-touch-icon.png'),
        ]) as $icon) {
            if (isset($icons[$icon])) {
                continue;
            }

            $icons[$icon] = [
                'src' => self::versionedAssetUrl($icon),
                'sizes' => str_ends_with(strtolower(parse_url($icon, PHP_URL_PATH) ?? ''), '.svg') ? 'any' : '192x192',
                'type' => self::manifestIconType($icon),
                'purpose' => 'any',
            ];
        }

        return [
            'name' => $name,
            'short_name' => $shortName !== '' ? $shortName : $name,
            'icons' => array_values($icons),
            'theme_color' => trim((string) SiteSetting::get('seo_favicon_theme_color', '#0f172a')),
            'background_color' => trim((string) SiteSetting::get('seo_favicon_tile_color', '#0f172a')),
            'display' => 'standalone',
            'lang' => self::currentLocale(),
            'start_url' => '/' . self::defaultHreflangLocale(),
        ];
    }

    public static function primaryFaviconUrl(): string
    {
        return self::normalizeMediaUrl((string) SiteSetting::get('seo_favicon_url', ''))
            ?: self::publicAssetUrl('favicon.png')
            ?: self::publicAssetUrl('favicon-192.png')
            ?: self::svgFaviconUrl();
    }

    public static function versionedPrimaryFaviconUrl(): string
    {
        return self::versionedAssetUrl(self::primaryFaviconUrl());
    }

    public static function svgFaviconUrl(): string
    {
        return self::normalizeMediaUrl((string) SiteSetting::get('seo_favicon_svg_url', ''))
            ?: self::publicAssetUrl('favicon.svg')
            ?: self::publicAssetUrl('icons/headphones.svg');
    }

    public static function siteName(?string $locale = null): string
    {
        $locale = self::normalizeLocale($locale);

        if ($locale === 'ar') {
            $brand = trim((string) SiteSetting::get('brand_name_ar', 'منصة إسمع'));

            return in_array($brand, ['', 'استمع', 'إسمع', 'Listen'], true) ? 'منصة إسمع' : $brand;
        }

        return trim((string) SiteSetting::get('site_name', config('app.name', 'Listen')));
    }

    public static function siteDescription(?string $locale = null): string
    {
        $locale = self::normalizeLocale($locale);
        $configured = self::localizedOverride('site_seo_description', $locale, $locale === 'ar');
        if ($configured !== '') {
            return $configured;
        }

        return $locale === 'ar'
            ? 'منصة متقدمة لتحويل النص إلى صوت بأصوات عربية وإنجليزية طبيعية تناسب المنتجات والمحتوى وسير العمل الحديث.'
            : 'A premium AI text to speech platform with natural Arabic and English voices for product, content, and workflow automation.';
    }

    public static function currentLocale(): string
    {
        return self::normalizeLocale((string) app()->getLocale());
    }

    /**
     * @return array<int, string>
     */
    public static function supportedLocales(): array
    {
        return ['en', 'ar'];
    }

    public static function defaultHreflangLocale(): string
    {
        return 'en';
    }

    /**
     * @return array<int, string>
     */
    public static function pageLocales(string $page): array
    {
        $definition = self::pageDefinitions()[$page] ?? null;
        if (! is_array($definition)) {
            return [];
        }

        if (isset($definition['allowed_locales']) && is_array($definition['allowed_locales'])) {
            return array_values(array_intersect(self::supportedLocales(), $definition['allowed_locales']));
        }

        return ($definition['localized'] ?? false) ? self::supportedLocales() : [self::defaultHreflangLocale()];
    }

    public static function currentLocalizedPath(?string $locale = null): string
    {
        $locale = self::normalizeLocale($locale);
        $requestPath = '/' . ltrim((string) request()->path(), '/');
        if ($requestPath === '/' || $requestPath === '/index.php') {
            return '/' . $locale;
        }

        return self::normalizePath($requestPath, $locale);
    }

    public static function resolveCurrentPageKey(): string
    {
        $strippedPath = self::stripLocaleFromPath('/' . ltrim((string) request()->path(), '/'));

        foreach (self::pageDefinitions() as $page => $definition) {
            $pagePath = (string) ($definition['path'] ?? '');
            if ($pagePath === $strippedPath || ($pagePath === '/' && $strippedPath === '/')) {
                return $page;
            }
        }

        return '';
    }

    public static function pageLastModified(string $page): Carbon
    {
        $timestamps = [];

        foreach (self::pageFiles($page) as $relativePath) {
            $fullPath = base_path($relativePath);
            if (is_file($fullPath)) {
                $timestamps[] = filemtime($fullPath) ?: null;
            }
        }

        $settingsUpdatedAt = SiteSetting::query()
            ->whereIn('key', self::pageSettingKeys($page))
            ->max('updated_at');

        if ($settingsUpdatedAt !== null) {
            $timestamps[] = Carbon::parse($settingsUpdatedAt)->timestamp;
        }

        $timestamps[] = self::siteLastModified()->timestamp;
        $timestamps = array_values(array_filter($timestamps, static fn ($value) => is_int($value) && $value > 0));

        return Carbon::createFromTimestamp($timestamps !== [] ? max($timestamps) : time());
    }

    public static function siteLastModified(): Carbon
    {
        $updatedAt = SiteSetting::query()
            ->whereIn('group', ['seo', 'general'])
            ->max('updated_at');

        return $updatedAt ? Carbon::parse($updatedAt) : Carbon::now();
    }

    private static function normalizeLocale(?string $locale): string
    {
        $locale = strtolower(trim((string) $locale));

        return in_array($locale, self::supportedLocales(), true) ? $locale : self::defaultHreflangLocale();
    }

    private static function normalizePath(string $path, ?string $locale = null): string
    {
        $locale = self::normalizeLocale($locale);
        $path = trim($path);
        if ($path === '') {
            return '/' . $locale;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        $path = '/' . ltrim($path, '/');
        $stripped = self::stripLocaleFromPath($path);

        return $stripped === '/' ? '/' . $locale : '/' . $locale . $stripped;
    }

    private static function stripLocaleFromPath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn ($value) => $value !== ''));

        if ($segments !== [] && in_array($segments[0], self::supportedLocales(), true)) {
            array_shift($segments);
        }

        return $segments === [] ? '/' : '/' . implode('/', $segments);
    }

    private static function localizedOverride(string $baseKey, string $locale, bool $requireArabic = false): string
    {
        $keys = ["{$baseKey}_{$locale}", $baseKey];

        foreach ($keys as $key) {
            $value = trim((string) SiteSetting::get($key, ''));
            if ($value === '') {
                continue;
            }

            if ($requireArabic && $locale === 'ar' && ! self::containsArabic($value)) {
                continue;
            }

            return $value;
        }

        return '';
    }

    private static function ogLocale(string $locale): string
    {
        $configured = trim((string) SiteSetting::get("og_locale_{$locale}", ''));
        if ($configured !== '') {
            return $configured;
        }

        $supported = (array) config('laravellocalization.supportedLocales', []);
        $regional = trim((string) data_get($supported, "{$locale}.regional", ''));

        return $regional !== '' ? $regional : ($locale === 'ar' ? 'ar_AE' : 'en_GB');
    }

    private static function schemaLanguage(string $locale): string
    {
        return $locale === 'ar' ? 'ar' : 'en';
    }

    /**
     * @return array<int, string>
     */
    private static function pageSettingKeys(string $page): array
    {
        return [
            "seo_{$page}_title",
            "seo_{$page}_title_en",
            "seo_{$page}_title_ar",
            "seo_{$page}_description",
            "seo_{$page}_description_en",
            "seo_{$page}_description_ar",
            "seo_{$page}_keywords",
            "seo_{$page}_keywords_en",
            "seo_{$page}_keywords_ar",
            "seo_{$page}_robots",
            'meta_title_template',
            'meta_title_template_en',
            'meta_title_template_ar',
            'meta_description',
            'meta_description_en',
            'meta_description_ar',
            'meta_keywords',
            'meta_keywords_en',
            'meta_keywords_ar',
            'site_name',
            'brand_name_ar',
            'site_seo_description',
            'site_seo_description_en',
            'site_seo_description_ar',
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function pageFiles(string $page): array
    {
        return match ($page) {
            'home' => ['resources/views/landing.blade.php', 'resources/views/components/header.blade.php', 'resources/views/components/footer.blade.php', 'resources/views/components/seo.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'pricing' => ['resources/views/pricing.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'contact' => ['resources/views/contact.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'extension' => ['resources/views/extension.blade.php', 'app/Http/Controllers/ExtensionController.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'privacy' => ['resources/views/legal/privacy.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'terms' => ['resources/views/legal/terms.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'cookies' => ['resources/views/legal/cookies.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'ai_text_to_speech', 'ai_voice_generator', 'text_to_speech_arabic' => ['resources/views/seo/landing-page.blade.php', 'app/Http/Controllers/HomeController.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'demo' => ['resources/views/demo.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'login' => ['resources/views/auth/login.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'register' => ['resources/views/auth/register.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'dashboard' => ['resources/views/dashboard.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'billing' => ['resources/views/billing.blade.php', 'resources/views/billing-manual.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'manage_subscription' => ['resources/views/manage-subscription.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'upgrade' => ['resources/views/upgrade.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            default => [],
        };
    }

    private static function normalizeMediaUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (Str::startsWith($value, ['http://', 'https://'])) {
            return $value;
        }

        if (Str::startsWith($value, ['/'])) {
            return url($value);
        }

        if (Storage::disk('public')->exists($value)) {
            return Storage::disk('public')->url(ltrim($value, '/'));
        }

        $publicPath = public_path(ltrim($value, '/'));
        if (is_file($publicPath)) {
            return asset(ltrim($value, '/'));
        }

        return '';
    }

    private static function publicAssetUrl(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');
        $fullPath = public_path($relativePath);

        return is_file($fullPath) && (filesize($fullPath) ?: 0) > 0 ? asset($relativePath) : '';
    }

    private static function versionedAssetUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return $url;
        }

        $publicFile = public_path(ltrim($path, '/'));
        if (is_file($publicFile)) {
            $separator = str_contains($url, '?') ? '&' : '?';

            return $url . $separator . 'v=' . filemtime($publicFile);
        }

        $storageCandidate = ltrim($path, '/');
        $storageCandidate = str_starts_with($storageCandidate, 'storage/')
            ? substr($storageCandidate, 8)
            : $storageCandidate;

        if ($storageCandidate !== '' && Storage::disk('public')->exists($storageCandidate)) {
            $separator = str_contains($url, '?') ? '&' : '?';

            return $url . $separator . 'v=' . filemtime(Storage::disk('public')->path($storageCandidate));
        }

        return $url;
    }

    private static function manifestIconType(string $icon): string
    {
        return str_ends_with(strtolower($icon), '.svg') ? 'image/svg+xml' : 'image/x-icon';
    }
}
