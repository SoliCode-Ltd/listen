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
            'meta_title_template' => SiteSetting::get('meta_title_template', '%page_title% - Listen'),
            'meta_description' => SiteSetting::get('meta_description', 'Listen - Convert text to natural speech with AI-powered TTS.'),
            'meta_keywords' => SiteSetting::get('meta_keywords', ''),
            'meta_robots' => SiteSetting::get('meta_robots', 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1'),
            'meta_author' => SiteSetting::get('meta_author', ''),
            'canonical_url' => SiteSetting::get('canonical_url', config('app.url')),

            'og_image_url' => SiteSetting::get('og_image_url', ''),
            'og_site_name' => SiteSetting::get('og_site_name', SiteSetting::get('site_name', config('app.name', 'Listen'))),
            'og_type' => SiteSetting::get('og_type', 'website'),
            'og_locale' => SiteSetting::get('og_locale', 'en_US'),
            'twitter_card_type' => SiteSetting::get('twitter_card_type', 'summary_large_image'),
            'twitter_handle' => SiteSetting::get('twitter_handle', ''),
            'twitter_creator' => SiteSetting::get('twitter_creator', ''),

            'google_analytics_id' => SiteSetting::get('google_analytics_id', ''),
            'enable_structured_data' => (string) SiteSetting::get('enable_structured_data', '1') === '1',

            'google_site_verification' => SiteSetting::get('google_site_verification', ''),
            'bing_site_verification' => SiteSetting::get('bing_site_verification', ''),
            'yandex_site_verification' => SiteSetting::get('yandex_site_verification', ''),
            'baidu_site_verification' => SiteSetting::get('baidu_site_verification', ''),

            'robots_txt' => SiteSetting::get('robots_txt', SeoHelper::defaultRobotsTxt()),
            'sitemap_paths' => SiteSetting::get('sitemap_paths', SeoHelper::defaultSitemapEntriesText()),

            'enable_llms_txt' => (string) SiteSetting::get('enable_llms_txt', '1') === '1',
            'llms_txt' => SiteSetting::get('llms_txt', SeoHelper::defaultLlmsTxt()),
        ];

        foreach (SeoHelper::pageDefinitions() as $page => $meta) {
            $state["seo_{$page}_title"] = SiteSetting::get("seo_{$page}_title", $meta['title']);
            $state["seo_{$page}_description"] = SiteSetting::get("seo_{$page}_description", $meta['description']);
            $state["seo_{$page}_keywords"] = SiteSetting::get("seo_{$page}_keywords", '');
            $state["seo_{$page}_robots"] = SiteSetting::get("seo_{$page}_robots", $meta['robots']);
        }

        $this->form->fill($state);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Global SEO Defaults')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('meta_title_template')
                            ->label('Meta Title Template')
                            ->helperText('Use %page_title% as the page title placeholder.')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('canonical_url')
                            ->label('Canonical Base URL')
                            ->url()
                            ->maxLength(500),
                        Forms\Components\Textarea::make('meta_description')
                            ->label('Default Meta Description')
                            ->rows(3)
                            ->maxLength(320)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('meta_keywords')
                            ->label('Default Meta Keywords')
                            ->rows(2)
                            ->helperText('Comma-separated keywords. Optional.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('meta_robots')
                            ->label('Default Robots Directive')
                            ->placeholder('index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('meta_author')
                            ->label('Meta Author')
                            ->maxLength(255),
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

                Forms\Components\Section::make('Social Media')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('og_image_url')
                            ->label('Open Graph Image URL')
                            ->url()
                            ->maxLength(500),
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
                        Forms\Components\TextInput::make('og_locale')
                            ->label('Open Graph Locale')
                            ->placeholder('en_US')
                            ->maxLength(20),
                        Forms\Components\Select::make('twitter_card_type')
                            ->label('Twitter Card Type')
                            ->options([
                                'summary' => 'Summary',
                                'summary_large_image' => 'Summary with Large Image',
                            ]),
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
                        Forms\Components\Toggle::make('enable_structured_data')
                            ->label('Enable Schema.org Structured Data')
                            ->helperText('Outputs Organization, WebSite, and SoftwareApplication JSON-LD.'),
                    ]),

                Forms\Components\Section::make('Crawl Controls')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Textarea::make('robots_txt')
                            ->label('robots.txt Content')
                            ->helperText('Served at /robots.txt')
                            ->rows(8)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('sitemap_paths')
                            ->label('Sitemap Entries')
                            ->helperText('One line per URL: /path|priority|change_frequency. Example: /pricing|0.9|weekly')
                            ->rows(8)
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('enable_llms_txt')
                            ->label('Enable llms.txt Endpoint')
                            ->helperText('Served at /llms.txt for AI crawler guidance.'),
                        Forms\Components\Placeholder::make('llms_txt_hint')
                            ->label('llms.txt Notes')
                            ->content('Use Markdown or plain text. Keep it concise and point to canonical documentation pages.'),
                        Forms\Components\Textarea::make('llms_txt')
                            ->label('llms.txt Content')
                            ->rows(12)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Per-Page SEO Overrides')
                    ->description('Use these to fully control SEO by page from the dashboard.')
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
            $schema[] = Forms\Components\Fieldset::make($meta['label'])
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make("seo_{$page}_title")
                        ->label('Title')
                        ->maxLength(255),
                    Forms\Components\TextInput::make("seo_{$page}_robots")
                        ->label('Robots')
                        ->placeholder($meta['robots'])
                        ->maxLength(255),
                    Forms\Components\Textarea::make("seo_{$page}_description")
                        ->label('Description')
                        ->rows(2)
                        ->maxLength(320)
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make("seo_{$page}_keywords")
                        ->label('Keywords')
                        ->rows(2)
                        ->helperText('Comma-separated keywords. Optional.')
                        ->columnSpanFull(),
                ]);
        }

        return $schema;
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
