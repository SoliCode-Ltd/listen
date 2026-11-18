<?php

namespace App\Filament\Pages;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Fieldset;
use App\Models\SiteSetting;
use App\Support\SeoHelper;
use App\Support\SeoSettingDefaults;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SeoSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'SEO Settings';

    protected string $view = 'filament.pages.seo-settings';

    public ?array $data = [];

    public function mount(): void
    {
        // The fields that follow the site's own text show it while nothing is saved.
        $defaults = SeoSettingDefaults::all();
        $saved = SiteSetting::query()->whereIn('key', array_keys($defaults))->pluck('value', 'key')->all();

        $state = [
            'canonical_url' => SiteSetting::get('canonical_url', config('app.url')),
            'meta_author' => SiteSetting::get('meta_author', ''),
            'meta_robots' => SiteSetting::get('meta_robots', 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1'),
            'meta_title_template_en' => SiteSetting::get('meta_title_template_en', SiteSetting::get('meta_title_template', '%page_title% - Listen')),
            'meta_title_template_ar' => SiteSetting::get('meta_title_template_ar', '%page_title% - منصة إسمع'),
            'meta_keywords_en' => SiteSetting::get('meta_keywords_en', SiteSetting::get('meta_keywords', '')),
            'meta_keywords_ar' => SiteSetting::get('meta_keywords_ar', ''),
            'brand_name_ar' => SiteSetting::get('brand_name_ar', SeoHelper::siteName('ar')),

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
            'google_ads_trial_conversion_send_to' => SiteSetting::get('google_ads_trial_conversion_send_to', ''),
            'google_ads_trial_paid_conversion_name' => SiteSetting::get('google_ads_trial_paid_conversion_name', 'Trial converted'),
            'google_ads_manual_purchase_conversion_name' => SiteSetting::get('google_ads_manual_purchase_conversion_name', 'Manual purchase'),
            'google_ads_card_purchase_conversion_name' => SiteSetting::get('google_ads_card_purchase_conversion_name', 'Card purchase (server)'),
            'google_tag_head_code' => SiteSetting::get('google_tag_head_code', ''),
            'facebook_pixel_code' => SiteSetting::get('facebook_pixel_code', ''),
            'custom_head_code' => SiteSetting::get('custom_head_code', ''),
            'purchase_event_code' => SiteSetting::get('purchase_event_code', ''),
            'enable_structured_data' => (string) SiteSetting::get('enable_structured_data', '1') === '1',

            'google_site_verification' => SiteSetting::get('google_site_verification', ''),
            'bing_site_verification' => SiteSetting::get('bing_site_verification', ''),
            'yandex_site_verification' => SiteSetting::get('yandex_site_verification', ''),
            'baidu_site_verification' => SiteSetting::get('baidu_site_verification', ''),
            'naver_site_verification' => SiteSetting::get('naver_site_verification', ''),

            'sitemap_paths' => SiteSetting::get('sitemap_paths', SeoHelper::defaultSitemapEntriesText()),
            'sitemap_include_non_indexable' => (string) SiteSetting::get('sitemap_include_non_indexable', '1') === '1',

            'enable_llms_txt' => (string) SiteSetting::get('enable_llms_txt', '1') === '1',
        ];

        foreach ($defaults as $key => $default) {
            $state[$key] = array_key_exists($key, $saved) ? $saved[$key] : $default;
        }
        // The titles of the pages kept out of search are saved as they are.
        foreach (SeoHelper::adminPageDefinitions() as $page => $meta) {
            foreach (in_array('ar', SeoHelper::pageLocales($page), true) ? ['en', 'ar'] : ['en'] as $locale) {
                $key = "seo_{$page}_title_{$locale}";
                if (! array_key_exists($key, $state)) {
                    $state[$key] = SiteSetting::get($key, $locale === 'en'
                        ? SiteSetting::get("seo_{$page}_title", SeoSettingDefaults::pageText($page, 'title', 'en', (string) ($meta['title'] ?? '')))
                        : SeoSettingDefaults::pageText($page, 'title', 'ar'));
                }
            }
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Localized SEO Defaults')
                    ->description('Global bilingual metadata defaults and brand fields for pages that do not have page-specific overrides.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('canonical_url')
                            ->label('Canonical Base URL')
                            ->url()
                            ->maxLength(500),
                        TextInput::make('meta_author')
                            ->label('Meta Author')
                            ->maxLength(255),
                        TextInput::make('meta_title_template_en')
                            ->label('English Title Template')
                            ->helperText('Use %page_title% and %brand_name%.')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('meta_title_template_ar')
                            ->label('Arabic Title Template')
                            ->helperText('Use %page_title% and %brand_name%.')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('meta_description_en')
                            ->label('English Default Description')
                            ->rows(3)
                            ->maxLength(320),
                        Textarea::make('meta_description_ar')
                            ->label('Arabic Default Description')
                            ->rows(3)
                            ->maxLength(320),
                        Textarea::make('meta_keywords_en')
                            ->label('English Default Keywords')
                            ->rows(2),
                        Textarea::make('meta_keywords_ar')
                            ->label('Arabic Default Keywords')
                            ->rows(2),
                        TextInput::make('brand_name_ar')
                            ->label('Arabic Brand Name')
                            ->maxLength(255),
                        TextInput::make('meta_robots')
                            ->label('Default Robots Directive')
                            ->placeholder('index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1')
                            ->maxLength(255),
                        Textarea::make('site_seo_description_en')
                            ->label('English Site Description')
                            ->helperText('Used in organization and website schema.')
                            ->rows(3),
                        Textarea::make('site_seo_description_ar')
                            ->label('Arabic Site Description')
                            ->helperText('Used in organization and website schema.')
                            ->rows(3),
                    ]),

                Section::make('Favicon & Search Appearance')
                    ->description('Keep favicon references explicit here so Google and browsers only see the latest brand icon set.')
                    ->columns(2)
                    ->schema([
                        FileUpload::make('seo_favicon_svg_url')
                            ->label('Primary SVG Favicon')
                            ->acceptedFileTypes(['image/svg+xml'])
                            ->directory('settings/seo')
                            ->disk('public')
                            ->maxSize(2048)
                            ->helperText('Preferred source for modern browsers and Google Search.'),
                        FileUpload::make('seo_favicon_url')
                            ->label('Fallback Icon / PNG')
                            ->acceptedFileTypes(['image/png', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/webp'])
                            ->directory('settings/seo')
                            ->disk('public')
                            ->maxSize(2048)
                            ->helperText('Optional fallback for platforms that do not use SVG favicons.'),
                        FileUpload::make('seo_apple_touch_icon_url')
                            ->label('Apple Touch Icon')
                            ->acceptedFileTypes(['image/png', 'image/webp'])
                            ->directory('settings/seo')
                            ->disk('public')
                            ->maxSize(4096),
                        TextInput::make('seo_manifest_url')
                            ->label('Custom Manifest URL')
                            ->url()
                            ->maxLength(500)
                            ->helperText('Leave empty to use the auto-generated /site.webmanifest endpoint.'),
                        TextInput::make('seo_manifest_short_name')
                            ->label('Manifest Short Name')
                            ->maxLength(100),
                        TextInput::make('seo_favicon_theme_color')
                            ->label('Theme Color')
                            ->placeholder('#0f172a')
                            ->maxLength(20),
                        TextInput::make('seo_favicon_tile_color')
                            ->label('MS Tile Color')
                            ->placeholder('#0f172a')
                            ->maxLength(20),
                    ]),

                Section::make('Search Engine Verification')
                    ->columns(2)
                    ->schema([
                        TextInput::make('google_site_verification')
                            ->label('Google Verification Token')
                            ->maxLength(255),
                        TextInput::make('bing_site_verification')
                            ->label('Bing Verification Token')
                            ->maxLength(255),
                        TextInput::make('yandex_site_verification')
                            ->label('Yandex Verification Token')
                            ->maxLength(255),
                        TextInput::make('baidu_site_verification')
                            ->label('Baidu Verification Token')
                            ->maxLength(255),
                        TextInput::make('naver_site_verification')
                            ->label('Naver Verification Token (Korea)')
                            ->maxLength(255),
                    ]),

                Section::make('Social Metadata')
                    ->columns(2)
                    ->schema([
                        FileUpload::make('og_image_url')
                            ->label('Open Graph Image')
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'])
                            ->directory('settings/seo')
                            ->disk('public')
                            ->maxSize(4096),
                        TextInput::make('og_site_name')
                            ->label('Open Graph Site Name')
                            ->maxLength(255),
                        Select::make('og_type')
                            ->label('Open Graph Type')
                            ->options([
                                'website' => 'Website',
                                'article' => 'Article',
                                'product' => 'Product',
                                'profile' => 'Profile',
                            ])
                            ->default('website'),
                        Select::make('twitter_card_type')
                            ->label('Twitter Card Type')
                            ->options([
                                'summary' => 'Summary',
                                'summary_large_image' => 'Summary with Large Image',
                            ]),
                        TextInput::make('og_locale_en')
                            ->label('OG Locale (English)')
                            ->placeholder('en_US')
                            ->maxLength(20),
                        TextInput::make('og_locale_ar')
                            ->label('OG Locale (Arabic)')
                            ->placeholder('ar_AE')
                            ->maxLength(20),
                        TextInput::make('twitter_handle')
                            ->label('Twitter Site Handle')
                            ->prefix('@')
                            ->maxLength(50),
                        TextInput::make('twitter_creator')
                            ->label('Twitter Creator Handle')
                            ->prefix('@')
                            ->maxLength(50),
                    ]),

                Section::make('Analytics & Structured Data')
                    ->columns(2)
                    ->schema([
                        TextInput::make('google_analytics_id')
                            ->label('Google Analytics ID')
                            ->placeholder('G-XXXXXXXXXX')
                            ->maxLength(30),
                        TextInput::make('google_tag_id')
                            ->label('Google Tag ID')
                            ->placeholder('AW-XXXXXXXXXX')
                            ->maxLength(30),
                        TextInput::make('google_ads_conversion_send_to')
                            ->label('Google Ads Conversion Send-To')
                            ->placeholder('AW-XXXXXXXXXX/XXXXXXXXXXX')
                            ->helperText('Conversion ID/Label for purchase events (e.g. AW-123456789/AbCdEfGhIjK). Found in Google Ads → Goals → Conversions → Tag setup. Sent with the paid amount and currency.')
                            ->maxLength(60),
                        TextInput::make('google_ads_trial_conversion_send_to')
                            ->label('Google Ads Trial Started Send-To')
                            ->placeholder('AW-XXXXXXXXXX/XXXXXXXXXXX')
                            ->helperText('Conversion ID/Label of a separate "Start trial" conversion action. Fires once when a card trial starts, with the plan price as value and the setup ID as transaction ID. Leave empty to send nothing.')
                            ->maxLength(60),
                        TextInput::make('google_ads_trial_paid_conversion_name')
                            ->label('Google Ads Trial Paid Conversion Name')
                            ->placeholder('Trial converted')
                            ->helperText('Must match the name of the "Import → Conversions from clicks" action in Google Ads. Used by Billing → Trials → Export paid trials for Google Ads.')
                            ->maxLength(100),
                        TextInput::make('google_ads_manual_purchase_conversion_name')
                            ->label('Google Ads Manual Purchase Conversion Name')
                            ->placeholder('Manual purchase')
                            ->helperText('InstaPay / Vodafone Cash payments from an ad click, counted once approved. Must match an "Import → Conversions from clicks" action (Primary). Used by Manual payments → Export purchases for Google Ads.')
                            ->maxLength(100),
                        TextInput::make('google_ads_card_purchase_conversion_name')
                            ->label('Google Ads Card Purchase (Server) Conversion Name')
                            ->placeholder('Card purchase (server)')
                            ->helperText('Card, Apple Pay and Google Pay payments from an ad click, as Stripe records them. The page already reports these, so keep this action Secondary in Google Ads. Used by Manual payments → Export purchases for Google Ads.')
                            ->maxLength(100),
                        Textarea::make('google_tag_head_code')
                            ->label('Google Tag Head Code')
                            ->helperText('Optional: paste the full Google tag snippet including script tags. If set, it overrides generated ID-based tags.')
                            ->rows(8)
                            ->columnSpanFull(),
                        Textarea::make('facebook_pixel_code')
                            ->label('Facebook Pixel Code')
                            ->helperText('Optional: paste the full Meta Pixel snippet including script and noscript tags.')
                            ->rows(8)
                            ->columnSpanFull(),
                        Toggle::make('enable_structured_data')
                            ->label('Enable Structured Data')
                            ->helperText('Outputs Organization, WebSite, and FAQ schema where relevant.'),
                    ]),

                Section::make('Ad pixels and tracking code')
                    ->description('For any ad network: paste its code here as it gives it to you. Nothing here runs inside the Android app, which declares no trackers to its store.')
                    ->schema([
                        Textarea::make('custom_head_code')
                            ->label('Page head code')
                            ->helperText('Printed in the <head> of every page of the site (public pages, studio, billing), once per page. Paste the full snippet including its <script> tags, one network after another. Example: the ChatGPT Ads pixel setup code.')
                            ->rows(8)
                            ->columnSpanFull(),
                        Textarea::make('purchase_event_code')
                            ->label('Code when a paid plan starts')
                            ->helperText('JavaScript run once in the member\'s browser the moment a card payment for a plan succeeds (the same moment Google Ads is told). Script tags are optional. It can read purchase.plan_id (for example "pro_monthly"), purchase.plan, purchase.cycle, purchase.amount (whole minor units, for example 4900 for 49.00) and purchase.currency (for example "USD"). Example: the ChatGPT Ads "subscription_created" event call.')
                            ->rows(8)
                            ->columnSpanFull(),
                    ]),

                Section::make('Crawl Controls')
                    ->columns(2)
                    ->schema([
                        Textarea::make('robots_txt')
                            ->label('robots.txt Content')
                            ->helperText('Served at /robots.txt. Keep public SEO routes crawlable while private app flows remain protected.')
                            ->rows(8)
                            ->columnSpanFull(),
                        Textarea::make('sitemap_paths')
                            ->label('Extra Sitemap Entries')
                            ->helperText('Optional extra public URLs. Format: /path|priority|change_frequency. Core public pages are added automatically.')
                            ->rows(8)
                            ->columnSpanFull(),
                        Toggle::make('sitemap_include_non_indexable')
                            ->label('Include Non-Indexable Pages In Sitemap')
                            ->helperText('When enabled, pages like demo/login/dashboard can appear in sitemap. Turn off to include only indexable public pages.'),
                        Textarea::make('sitemap_excluded_paths')
                            ->label('Sitemap Excluded Paths')
                            ->helperText('One path per line. Optional manual blocklist for sitemap URLs.')
                            ->rows(6)
                            ->columnSpanFull(),
                        Toggle::make('enable_llms_txt')
                            ->label('Enable llms.txt Endpoint')
                            ->helperText('Served at /llms.txt for AI crawler guidance.'),
                        Placeholder::make('sitemap_notes')
                            ->label('Sitemap Notes')
                            ->content('The sitemap is generated dynamically from public indexable pages in both languages, then merged with any extra entries you list here.'),
                        Textarea::make('llms_txt')
                            ->label('llms.txt Content')
                            ->rows(12)
                            ->columnSpanFull(),
                    ]),

                Section::make('SEO Landing Page Content')
                    ->description('Optional JSON overrides for the SEO landing pages. Leave blank to keep the safe built-in defaults used by the public site.')
                    ->collapsible()
                    ->collapsed()
                    ->schema($this->landingPageSchema()),

                Section::make('Per-Page SEO Overrides')
                    ->description('Fine-tune titles, descriptions, keywords, and robots per page and per language without touching templates.')
                    ->collapsible()
                    ->collapsed()
                    ->schema($this->perPageSeoSchema()),
            ])
            ->statePath('data');
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function perPageSeoSchema(): array
    {
        $schema = [];

        foreach (SeoHelper::adminPageDefinitions() as $page => $meta) {
            $fields = [
                TextInput::make("seo_{$page}_title_en")
                    ->label('English Title')
                    ->maxLength(255)
                    ->helperText('Leave blank to use the translation default.'),
                TextInput::make("seo_{$page}_robots")
                    ->label('Robots')
                    ->placeholder((string) ($meta['robots'] ?? ''))
                    ->maxLength(255),
                Textarea::make("seo_{$page}_description_en")
                    ->label('English Description')
                    ->rows(2)
                    ->maxLength(320)
                    ->columnSpanFull(),
                Textarea::make("seo_{$page}_keywords_en")
                    ->label('English Keywords')
                    ->rows(2)
                    ->helperText('Comma-separated keywords. Optional.')
                    ->columnSpanFull(),
            ];

            if ((bool) ($meta['indexable'] ?? false)) {
                $fields[] = TextInput::make("seo_{$page}_sitemap_priority")
                    ->label('Sitemap Priority')
                    ->rules(['numeric', 'min:0', 'max:1'])
                    ->inputMode('decimal')
                    ->step(0.1);
                $fields[] = Select::make("seo_{$page}_sitemap_change_frequency")
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
                $fields[] = TextInput::make("seo_{$page}_title_ar")
                    ->label('Arabic Title')
                    ->maxLength(255)
                    ->helperText('Leave blank to use the translation default.');
                $fields[] = Placeholder::make("seo_{$page}_locale_hint")
                    ->label('Language Notes')
                    ->content('Arabic fields should stay fully localized. Slugs remain in English as requested.');
                $fields[] = Textarea::make("seo_{$page}_description_ar")
                    ->label('Arabic Description')
                    ->rows(2)
                    ->maxLength(320)
                    ->columnSpanFull();
                $fields[] = Textarea::make("seo_{$page}_keywords_ar")
                    ->label('Arabic Keywords')
                    ->rows(2)
                    ->helperText('Comma-separated keywords. Optional.')
                    ->columnSpanFull();
            }

            $schema[] = Fieldset::make((string) ($meta['label'] ?? $page))
                ->columns(2)
                ->schema($fields);
        }

        return $schema;
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function landingPageSchema(): array
    {
        $schema = [];

        foreach (SeoSettingDefaults::LANDING_PAGES as $page => $locales) {
            $title = (string) (SeoHelper::pageDefinitions()[$page]['label'] ?? $page);
            $fields = [];

            foreach ($locales as $locale) {
                $label = $locale === 'ar' ? 'Arabic JSON' : 'English JSON';
                $fields[] = Textarea::make("seo_landing_{$page}_content_{$locale}")
                    ->label($label)
                    ->rows(18)
                    ->helperText('Preloaded with production defaults. Supported keys: eyebrow, h1, intro, highlights_title, highlights_intro, highlights, use_cases_title, use_cases_intro, use_cases, faq_title, faqs, cta_title, cta_body, cta_primary.')
                    ->columnSpanFull();
            }

            $schema[] = Fieldset::make($title)
                ->columns(1)
                ->schema($fields);
        }

        return $schema;
    }

    public function save(): void
    {
        $data = $this->form->getState();
        // A field left as the site's own text is not saved (and a saved copy of
        // it is let go), so the page keeps following that text when it improves.
        $defaults = SeoSettingDefaults::all();

        foreach ($data as $key => $value) {
            $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            if (array_key_exists($key, $defaults) && SeoSettingDefaults::matches($key, $value, $defaults[$key])) {
                SeoSettingDefaults::forget($key);

                continue;
            }
            SiteSetting::set($key, $value, 'seo');
        }

        SiteSetting::flushGroupCache('seo');

        Notification::make()
            ->title('SEO settings saved')
            ->success()
            ->send();
    }
}
