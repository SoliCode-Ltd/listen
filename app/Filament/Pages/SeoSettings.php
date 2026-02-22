<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
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
        $this->form->fill([
            'meta_title_template' => SiteSetting::get('meta_title_template', '%page_title% | Listen'),
            'meta_description' => SiteSetting::get('meta_description', 'Listen — Convert text to natural speech with AI-powered TTS.'),
            'og_image_url' => SiteSetting::get('og_image_url', ''),
            'twitter_card_type' => SiteSetting::get('twitter_card_type', 'summary_large_image'),
            'twitter_handle' => SiteSetting::get('twitter_handle', ''),
            'google_analytics_id' => SiteSetting::get('google_analytics_id', ''),
            'robots_txt' => SiteSetting::get('robots_txt', "User-agent: *\nAllow: /\n\nSitemap: " . url('/sitemap.xml')),
            'enable_structured_data' => SiteSetting::get('enable_structured_data', '1'),
            'canonical_url' => SiteSetting::get('canonical_url', config('app.url')),
            // Per-page SEO
            'seo_home_title' => SiteSetting::get('seo_home_title', ''),
            'seo_home_description' => SiteSetting::get('seo_home_description', ''),
            'seo_pricing_title' => SiteSetting::get('seo_pricing_title', ''),
            'seo_pricing_description' => SiteSetting::get('seo_pricing_description', ''),
            'seo_demo_title' => SiteSetting::get('seo_demo_title', ''),
            'seo_demo_description' => SiteSetting::get('seo_demo_description', ''),
            'seo_login_title' => SiteSetting::get('seo_login_title', ''),
            'seo_login_description' => SiteSetting::get('seo_login_description', ''),
            'seo_register_title' => SiteSetting::get('seo_register_title', ''),
            'seo_register_description' => SiteSetting::get('seo_register_description', ''),
        ]);
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
                            ->helperText('Use %page_title% as placeholder for the page-specific title.')
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
                    ]),
                Forms\Components\Section::make('Social Media / Open Graph')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('og_image_url')
                            ->label('OG Image URL')
                            ->url()
                            ->maxLength(500),
                        Forms\Components\Select::make('twitter_card_type')
                            ->label('Twitter Card Type')
                            ->options([
                                'summary' => 'Summary',
                                'summary_large_image' => 'Summary with Large Image',
                            ]),
                        Forms\Components\TextInput::make('twitter_handle')
                            ->label('Twitter Handle')
                            ->prefix('@')
                            ->maxLength(50),
                    ]),
                Forms\Components\Section::make('Analytics & Structured Data')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('google_analytics_id')
                            ->label('Google Analytics ID')
                            ->placeholder('G-XXXXXXXXXX')
                            ->maxLength(20),
                        Forms\Components\Toggle::make('enable_structured_data')
                            ->label('Enable Schema.org Structured Data')
                            ->helperText('Outputs Organization + WebApplication JSON-LD on every page.'),
                    ]),
                Forms\Components\Section::make('robots.txt')
                    ->collapsible()
                    ->schema([
                        Forms\Components\Textarea::make('robots_txt')
                            ->label('robots.txt Content')
                            ->helperText('Served at /robots.txt. Include your sitemap URL.')
                            ->rows(6)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Per-Page SEO Overrides')
                    ->description('Leave blank to use the global defaults.')
                    ->collapsible()
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('seo_home_title')
                            ->label('Home — Title'),
                        Forms\Components\TextInput::make('seo_home_description')
                            ->label('Home — Description'),
                        Forms\Components\TextInput::make('seo_pricing_title')
                            ->label('Pricing — Title'),
                        Forms\Components\TextInput::make('seo_pricing_description')
                            ->label('Pricing — Description'),
                        Forms\Components\TextInput::make('seo_demo_title')
                            ->label('Demo — Title'),
                        Forms\Components\TextInput::make('seo_demo_description')
                            ->label('Demo — Description'),
                        Forms\Components\TextInput::make('seo_login_title')
                            ->label('Login — Title'),
                        Forms\Components\TextInput::make('seo_login_description')
                            ->label('Login — Description'),
                        Forms\Components\TextInput::make('seo_register_title')
                            ->label('Register — Title'),
                        Forms\Components\TextInput::make('seo_register_description')
                            ->label('Register — Description'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach ($data as $key => $value) {
            SiteSetting::set($key, is_bool($value) ? ($value ? '1' : '0') : $value, 'seo');
        }

        SiteSetting::flushGroupCache('seo');

        Notification::make()
            ->title('SEO settings saved')
            ->success()
            ->send();
    }
}
