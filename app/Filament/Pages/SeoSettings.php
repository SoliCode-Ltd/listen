<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use App\Support\SeoHelper;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SeoSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'SEO Settings';

    protected static string $view = 'filament.pages.seo-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $state = [
            'canonical_url' => SiteSetting::get('canonical_url', config('app.url')),
            'meta_author' => SiteSetting::get('meta_author', ''),
            'meta_robots' => SiteSetting::get('meta_robots', 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1'),
            'meta_title_template_en' => SiteSetting::get('meta_title_template_en', SiteSetting::get('meta_title_template', '%page_title% - Listen')),
            'meta_title_template_ar' => SiteSetting::get('meta_title_template_ar', '%page_title% - منصة إسمع'),
            'meta_description_en' => SiteSetting::get('meta_description_en', SiteSetting::get('meta_description', SeoHelper::siteDescription('en'))),
            'meta_description_ar' => SiteSetting::get('meta_description_ar', SeoHelper::siteDescription('ar')),
            'meta_keywords_en' => SiteSetting::get('meta_keywords_en', SiteSetting::get('meta_keywords', '')),
            'meta_keywords_ar' => SiteSetting::get('meta_keywords_ar', ''),
            'brand_name_ar' => SiteSetting::get('brand_name_ar', SeoHelper::siteName('ar')),
            'site_seo_description_en' => SiteSetting::get('site_seo_description_en', SeoHelper::siteDescription('en')),
            'site_seo_description_ar' => SiteSetting::get('site_seo_description_ar', SeoHelper::siteDescription('ar')),

            'seo_favicon_svg_url' => SiteSetting::get('seo_favicon_svg_url', ''),
            'seo_favicon_url' => SiteSetting::get('seo_favicon_url', ''),
            'seo_apple_touch_icon_url' => SiteSetting::get('seo_apple_touch_icon_url', ''),
            'seo_manifest_url' => SiteSetting::get('seo_manifest_url', ''),
            'seo_manifest_short_name' => SiteSetting::get('seo_manifest_short_name', ''),
            'seo_favicon_theme_color' => SiteSetting::get('seo_favicon_theme_color', '#0f172a'),
            'seo_favicon_tile_color' => SiteSetting::get('seo_favicon_tile_color', '#0f172a'),

            'og_image_url' => SiteSetting::get('og_image_url', ''),
            'og_site_name' => SiteSetting::get('og_site_name', SiteSetting::get('site_name', config('app.name', 'Listen'))),
            'og_type' => SiteSetting::get('og_type', 'website'),
            'og_locale_en' => SiteSetting::get('og_locale_en', 'en_US'),
            'og_locale_ar' => SiteSetting::get('og_locale_ar', 'ar_AE'),
            'twitter_card_type' => SiteSetting::get('twitter_card_type', 'summary_large_image'),
            'twitter_handle' => SiteSetting::get('twitter_handle', ''),
            'twitter_creator' => SiteSetting::get('twitter_creator', ''),

            'google_analytics_id' => SiteSetting::get('google_analytics_id', ''),
            'google_tag_id' => SiteSetting::get('google_tag_id', ''),
            'google_ads_conversion_send_to' => SiteSetting::get('google_ads_conversion_send_to', ''),
            'google_tag_head_code' => SiteSetting::get('google_tag_head_code', ''),
            'facebook_pixel_code' => SiteSetting::get('facebook_pixel_code', ''),
            'enable_structured_data' => (string) SiteSetting::get('enable_structured_data', '1') === '1',

            'google_site_verification' => SiteSetting::get('google_site_verification', ''),
            'bing_site_verification' => SiteSetting::get('bing_site_verification', ''),
            'yandex_site_verification' => SiteSetting::get('yandex_site_verification', ''),
            'baidu_site_verification' => SiteSetting::get('baidu_site_verification', ''),

            'robots_txt' => SiteSetting::get('robots_txt', SeoHelper::defaultRobotsTxt()),
            'sitemap_paths' => SiteSetting::get('sitemap_paths', SeoHelper::defaultSitemapEntriesText()),
            'sitemap_excluded_paths' => SiteSetting::get('sitemap_excluded_paths', SeoHelper::defaultSitemapExcludedPathsText()),
            'sitemap_include_non_indexable' => (string) SiteSetting::get('sitemap_include_non_indexable', '1') === '1',

            'enable_llms_txt' => (string) SiteSetting::get('enable_llms_txt', '1') === '1',
            'llms_txt' => SiteSetting::get('llms_txt', SeoHelper::defaultLlmsTxt()),
        ];

        foreach (SeoHelper::pageDefinitions() as $page => $meta) {
            $state["seo_{$page}_title_en"] = SiteSetting::get(
                "seo_{$page}_title_en",
                SiteSetting::get("seo_{$page}_title", $this->pageDefault($page, 'title', 'en', (string) ($meta['title'] ?? '')))
            );
            $state["seo_{$page}_description_en"] = SiteSetting::get(
                "seo_{$page}_description_en",
                SiteSetting::get("seo_{$page}_description", $this->pageDefault($page, 'description', 'en', (string) ($meta['description'] ?? '')))
            );
            $state["seo_{$page}_keywords_en"] = SiteSetting::get(
                "seo_{$page}_keywords_en",
                SiteSetting::get("seo_{$page}_keywords", '')
            );
            $state["seo_{$page}_robots"] = SiteSetting::get("seo_{$page}_robots", (string) ($meta['robots'] ?? ''));
            if ((bool) ($meta['indexable'] ?? false)) {
                $state["seo_{$page}_sitemap_priority"] = SiteSetting::get(
                    "seo_{$page}_sitemap_priority",
                    (string) ($meta['sitemap_priority'] ?? '0.5')
                );
                $state["seo_{$page}_sitemap_change_frequency"] = SiteSetting::get(
                    "seo_{$page}_sitemap_change_frequency",
                    (string) ($meta['sitemap_change_frequency'] ?? 'weekly')
                );
            }

            if (in_array('ar', SeoHelper::pageLocales($page), true)) {
                $state["seo_{$page}_title_ar"] = SiteSetting::get(
                    "seo_{$page}_title_ar",
                    $this->pageDefault($page, 'title', 'ar')
                );
                $state["seo_{$page}_description_ar"] = SiteSetting::get(
                    "seo_{$page}_description_ar",
                    $this->pageDefault($page, 'description', 'ar')
                );
                $state["seo_{$page}_keywords_ar"] = SiteSetting::get("seo_{$page}_keywords_ar", '');
            }
        }

        foreach ($this->landingPageLocales() as $page => $locales) {
            foreach ($locales as $locale) {
                $state["seo_landing_{$page}_content_{$locale}"] = SiteSetting::get(
                    "seo_landing_{$page}_content_{$locale}",
                    $this->landingPageDefaultJson($page, $locale)
                );
            }
        }

        $this->form->fill($state);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Localized SEO Defaults')
                    ->description('Global bilingual metadata defaults and brand fields for pages that do not have page-specific overrides.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('canonical_url')
                            ->label('Canonical Base URL')
                            ->url()
                            ->maxLength(500),
                        Forms\Components\TextInput::make('meta_author')
                            ->label('Meta Author')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('meta_title_template_en')
                            ->label('English Title Template')
                            ->helperText('Use %page_title% and %brand_name%.')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('meta_title_template_ar')
                            ->label('Arabic Title Template')
                            ->helperText('Use %page_title% and %brand_name%.')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('meta_description_en')
                            ->label('English Default Description')
                            ->rows(3)
                            ->maxLength(320),
                        Forms\Components\Textarea::make('meta_description_ar')
                            ->label('Arabic Default Description')
                            ->rows(3)
                            ->maxLength(320),
                        Forms\Components\Textarea::make('meta_keywords_en')
                            ->label('English Default Keywords')
                            ->rows(2),
                        Forms\Components\Textarea::make('meta_keywords_ar')
                            ->label('Arabic Default Keywords')
                            ->rows(2),
                        Forms\Components\TextInput::make('brand_name_ar')
                            ->label('Arabic Brand Name')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('meta_robots')
                            ->label('Default Robots Directive')
                            ->placeholder('index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1')
                            ->maxLength(255),
                        Forms\Components\Textarea::make('site_seo_description_en')
                            ->label('English Site Description')
                            ->helperText('Used in organization and website schema.')
                            ->rows(3),
                        Forms\Components\Textarea::make('site_seo_description_ar')
                            ->label('Arabic Site Description')
                            ->helperText('Used in organization and website schema.')
                            ->rows(3),
                    ]),

                Forms\Components\Section::make('Favicon & Search Appearance')
                    ->description('Keep favicon references explicit here so Google and browsers only see the latest brand icon set.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\FileUpload::make('seo_favicon_svg_url')
                            ->label('Primary SVG Favicon')
                            ->acceptedFileTypes(['image/svg+xml'])
                            ->directory('settings/seo')
                            ->disk('public')
                            ->maxSize(2048)
                            ->helperText('Preferred source for modern browsers and Google Search.'),
                        Forms\Components\FileUpload::make('seo_favicon_url')
                            ->label('Fallback Icon / PNG')
                            ->acceptedFileTypes(['image/png', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/webp'])
                            ->directory('settings/seo')
                            ->disk('public')
                            ->maxSize(2048)
                            ->helperText('Optional fallback for platforms that do not use SVG favicons.'),
                        Forms\Components\FileUpload::make('seo_apple_touch_icon_url')
                            ->label('Apple Touch Icon')
                            ->acceptedFileTypes(['image/png', 'image/webp'])
                            ->directory('settings/seo')
                            ->disk('public')
                            ->maxSize(4096),
                        Forms\Components\TextInput::make('seo_manifest_url')
                            ->label('Custom Manifest URL')
                            ->url()
                            ->maxLength(500)
                            ->helperText('Leave empty to use the auto-generated /site.webmanifest endpoint.'),
                        Forms\Components\TextInput::make('seo_manifest_short_name')
                            ->label('Manifest Short Name')
                            ->maxLength(100),
                        Forms\Components\TextInput::make('seo_favicon_theme_color')
                            ->label('Theme Color')
                            ->placeholder('#0f172a')
                            ->maxLength(20),
                        Forms\Components\TextInput::make('seo_favicon_tile_color')
                            ->label('MS Tile Color')
                            ->placeholder('#0f172a')
                            ->maxLength(20),
                    ]),

                Forms\Components\Section::make('Search Engine Verification')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('google_site_verification')
                            ->label('Google Verification Token')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('bing_site_verification')
                            ->label('Bing Verification Token')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('yandex_site_verification')
                            ->label('Yandex Verification Token')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('baidu_site_verification')
                            ->label('Baidu Verification Token')
                            ->maxLength(255),
                    ]),

                Forms\Components\Section::make('Social Metadata')
                    ->columns(2)
                    ->schema([
                        Forms\Components\FileUpload::make('og_image_url')
                            ->label('Open Graph Image')
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'])
                            ->directory('settings/seo')
                            ->disk('public')
                            ->maxSize(4096),
                        Forms\Components\TextInput::make('og_site_name')
                            ->label('Open Graph Site Name')
                            ->maxLength(255),
                        Forms\Components\Select::make('og_type')
                            ->label('Open Graph Type')
                            ->options([
                                'website' => 'Website',
                                'article' => 'Article',
                                'product' => 'Product',
                                'profile' => 'Profile',
                            ])
                            ->default('website'),
                        Forms\Components\Select::make('twitter_card_type')
                            ->label('Twitter Card Type')
                            ->options([
                                'summary' => 'Summary',
                                'summary_large_image' => 'Summary with Large Image',
                            ]),
                        Forms\Components\TextInput::make('og_locale_en')
                            ->label('OG Locale (English)')
                            ->placeholder('en_US')
                            ->maxLength(20),
                        Forms\Components\TextInput::make('og_locale_ar')
                            ->label('OG Locale (Arabic)')
                            ->placeholder('ar_AE')
                            ->maxLength(20),
                        Forms\Components\TextInput::make('twitter_handle')
                            ->label('Twitter Site Handle')
                            ->prefix('@')
                            ->maxLength(50),
                        Forms\Components\TextInput::make('twitter_creator')
                            ->label('Twitter Creator Handle')
                            ->prefix('@')
                            ->maxLength(50),
                    ]),

                Forms\Components\Section::make('Analytics & Structured Data')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('google_analytics_id')
                            ->label('Google Analytics ID')
                            ->placeholder('G-XXXXXXXXXX')
                            ->maxLength(30),
                        Forms\Components\TextInput::make('google_tag_id')
                            ->label('Google Tag ID')
                            ->placeholder('AW-XXXXXXXXXX')
                            ->maxLength(30),
                        Forms\Components\TextInput::make('google_ads_conversion_send_to')
                            ->label('Google Ads Conversion Send-To')
                            ->placeholder('AW-XXXXXXXXXX/XXXXXXXXXXX')
                            ->helperText('Conversion ID/Label for purchase events (e.g. AW-123456789/AbCdEfGhIjK). Found in Google Ads → Goals → Conversions → Tag setup.')
                            ->maxLength(60),
                        Forms\Components\Textarea::make('google_tag_head_code')
                            ->label('Google Tag Head Code')
                            ->helperText('Optional: paste the full Google tag snippet including script tags. If set, it overrides generated ID-based tags.')
                            ->rows(8)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('facebook_pixel_code')
                            ->label('Facebook Pixel Code')
                            ->helperText('Optional: paste the full Meta Pixel snippet including script and noscript tags.')
                            ->rows(8)
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('enable_structured_data')
                            ->label('Enable Structured Data')
                            ->helperText('Outputs Organization, WebSite, and FAQ schema where relevant.'),
                    ]),

                Forms\Components\Section::make('Crawl Controls')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Textarea::make('robots_txt')
                            ->label('robots.txt Content')
                            ->helperText('Served at /robots.txt. Keep public SEO routes crawlable while private app flows remain protected.')
                            ->rows(8)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('sitemap_paths')
                            ->label('Extra Sitemap Entries')
                            ->helperText('Optional extra public URLs. Format: /path|priority|change_frequency. Core public pages are added automatically.')
                            ->rows(8)
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('sitemap_include_non_indexable')
                            ->label('Include Non-Indexable Pages In Sitemap')
                            ->helperText('When enabled, pages like demo/login/dashboard can appear in sitemap. Turn off to include only indexable public pages.'),
                        Forms\Components\Textarea::make('sitemap_excluded_paths')
                            ->label('Sitemap Excluded Paths')
                            ->helperText('One path per line. Optional manual blocklist for sitemap URLs.')
                            ->rows(6)
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('enable_llms_txt')
                            ->label('Enable llms.txt Endpoint')
                            ->helperText('Served at /llms.txt for AI crawler guidance.'),
                        Forms\Components\Placeholder::make('sitemap_notes')
                            ->label('Sitemap Notes')
                            ->content('The sitemap is generated dynamically from public indexable pages in both languages, then merged with any extra entries you list here.'),
                        Forms\Components\Textarea::make('llms_txt')
                            ->label('llms.txt Content')
                            ->rows(12)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('SEO Landing Page Content')
                    ->description('Optional JSON overrides for the SEO landing pages. Leave blank to keep the safe built-in defaults used by the public site.')
                    ->collapsible()
                    ->collapsed()
                    ->schema($this->landingPageSchema()),

                Forms\Components\Section::make('Per-Page SEO Overrides')
                    ->description('Fine-tune titles, descriptions, keywords, and robots per page and per language without touching templates.')
                    ->collapsible()
                    ->collapsed()
                    ->schema($this->perPageSeoSchema()),
            ])
            ->statePath('data');
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private function perPageSeoSchema(): array
    {
        $schema = [];

        foreach (SeoHelper::pageDefinitions() as $page => $meta) {
            $fields = [
                Forms\Components\TextInput::make("seo_{$page}_title_en")
                    ->label('English Title')
                    ->maxLength(255)
                    ->helperText('Leave blank to use the translation default.'),
                Forms\Components\TextInput::make("seo_{$page}_robots")
                    ->label('Robots')
                    ->placeholder((string) ($meta['robots'] ?? ''))
                    ->maxLength(255),
                Forms\Components\Textarea::make("seo_{$page}_description_en")
                    ->label('English Description')
                    ->rows(2)
                    ->maxLength(320)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make("seo_{$page}_keywords_en")
                    ->label('English Keywords')
                    ->rows(2)
                    ->helperText('Comma-separated keywords. Optional.')
                    ->columnSpanFull(),
            ];

            if ((bool) ($meta['indexable'] ?? false)) {
                $fields[] = Forms\Components\TextInput::make("seo_{$page}_sitemap_priority")
                    ->label('Sitemap Priority')
                    ->rules(['numeric', 'min:0', 'max:1'])
                    ->inputMode('decimal')
                    ->step(0.1);
                $fields[] = Forms\Components\Select::make("seo_{$page}_sitemap_change_frequency")
                    ->label('Sitemap Change Frequency')
                    ->options([
                        'always' => 'always',
                        'hourly' => 'hourly',
                        'daily' => 'daily',
                        'weekly' => 'weekly',
                        'monthly' => 'monthly',
                        'yearly' => 'yearly',
                        'never' => 'never',
                    ]);
            }

            if (in_array('ar', SeoHelper::pageLocales($page), true)) {
                $fields[] = Forms\Components\TextInput::make("seo_{$page}_title_ar")
                    ->label('Arabic Title')
                    ->maxLength(255)
                    ->helperText('Leave blank to use the translation default.');
                $fields[] = Forms\Components\Placeholder::make("seo_{$page}_locale_hint")
                    ->label('Language Notes')
                    ->content('Arabic fields should stay fully localized. Slugs remain in English as requested.');
                $fields[] = Forms\Components\Textarea::make("seo_{$page}_description_ar")
                    ->label('Arabic Description')
                    ->rows(2)
                    ->maxLength(320)
                    ->columnSpanFull();
                $fields[] = Forms\Components\Textarea::make("seo_{$page}_keywords_ar")
                    ->label('Arabic Keywords')
                    ->rows(2)
                    ->helperText('Comma-separated keywords. Optional.')
                    ->columnSpanFull();
            }

            $schema[] = Forms\Components\Fieldset::make((string) ($meta['label'] ?? $page))
                ->columns(2)
                ->schema($fields);
        }

        return $schema;
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private function landingPageSchema(): array
    {
        $schema = [];

        foreach ($this->landingPageLocales() as $page => $locales) {
            $title = (string) (SeoHelper::pageDefinitions()[$page]['label'] ?? $page);
            $fields = [];

            foreach ($locales as $locale) {
                $label = $locale === 'ar' ? 'Arabic JSON' : 'English JSON';
                $fields[] = Forms\Components\Textarea::make("seo_landing_{$page}_content_{$locale}")
                    ->label($label)
                    ->rows(18)
                    ->helperText('Preloaded with production defaults. Supported keys: eyebrow, h1, intro, highlights_title, highlights_intro, highlights, use_cases_title, use_cases_intro, use_cases, faq_title, faqs, cta_title, cta_body, cta_primary.')
                    ->columnSpanFull();
            }

            $schema[] = Forms\Components\Fieldset::make($title)
                ->columns(1)
                ->schema($fields);
        }

        return $schema;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function landingPageLocales(): array
    {
        return [
            'ai_text_to_speech' => ['en', 'ar'],
            'ai_voice_generator' => ['en', 'ar'],
            'text_to_speech_arabic' => ['en'],
        ];
    }

    private function landingPageDefaultJson(string $page, string $locale): string
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

    private function pageDefault(string $page, string $field, string $locale, string $fallback = ''): string
    {
        $definition = SeoHelper::pageDefinitions()[$page] ?? [];
        $translationKey = (string) ($definition["{$field}_key"] ?? '');

        if ($translationKey !== '') {
            $translated = trim((string) __($translationKey, [], $locale));
            if ($translated !== '' && $translated !== $translationKey) {
                return $translated;
            }
        }

        return $fallback;
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach ($data as $key => $value) {
            SiteSetting::set($key, is_bool($value) ? ($value ? '1' : '0') : (string) $value, 'seo');
        }

        SiteSetting::flushGroupCache('seo');

        Notification::make()
            ->title('SEO settings saved')
            ->success()
            ->send();
    }
}
