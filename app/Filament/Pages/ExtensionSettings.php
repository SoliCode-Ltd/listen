<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ExtensionSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Extension Settings';

    protected static string $view = 'filament.pages.extension-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            // Browser availability
            'extension_chrome_enabled' => SiteSetting::get('extension_chrome_enabled', '1'),
            'extension_firefox_enabled' => SiteSetting::get('extension_firefox_enabled', '0'),
            'extension_safari_enabled' => SiteSetting::get('extension_safari_enabled', '0'),

            // Chrome metadata
            'extension_chrome_version' => SiteSetting::get('extension_chrome_version', '1.0.0'),
            'extension_chrome_name' => SiteSetting::get('extension_chrome_name', 'Listen Reader'),
            'extension_chrome_description' => SiteSetting::get('extension_chrome_description', 'Listen to selected text on any page using your Listen account.'),

            // Extension icons
            'extension_icon_16' => SiteSetting::get('extension_icon_16', ''),
            'extension_icon_32' => SiteSetting::get('extension_icon_32', ''),
            'extension_icon_48' => SiteSetting::get('extension_icon_48', ''),
            'extension_icon_128' => SiteSetting::get('extension_icon_128', ''),

            // Extension page content
            'extension_page_title' => SiteSetting::get('extension_page_title', 'Browsers Extension'),
            'extension_page_subtitle' => SiteSetting::get('extension_page_subtitle', 'Turn any text on the web into natural voice. Select, click, and listen across all your favorite websites.'),

            // Chrome card
            'extension_chrome_card_title' => SiteSetting::get('extension_chrome_card_title', 'Chrome'),
            'extension_chrome_card_subtitle' => SiteSetting::get('extension_chrome_card_subtitle', 'Full support with context menu integration'),

            // Firefox card
            'extension_firefox_card_title' => SiteSetting::get('extension_firefox_card_title', 'Mozilla Firefox'),
            'extension_firefox_card_subtitle' => SiteSetting::get('extension_firefox_card_subtitle', 'WebExtension API support'),

            // Safari card
            'extension_safari_card_title' => SiteSetting::get('extension_safari_card_title', 'Apple Safari'),
            'extension_safari_card_subtitle' => SiteSetting::get('extension_safari_card_subtitle', 'Native Safari Web Extension'),

            // Feature cards
            'extension_feature_1_title' => SiteSetting::get('extension_feature_1_title', 'One-Click Listening'),
            'extension_feature_1_desc' => SiteSetting::get('extension_feature_1_desc', 'Select text anywhere and listen instantly with context menu integration.'),
            'extension_feature_2_title' => SiteSetting::get('extension_feature_2_title', 'Secure by Design'),
            'extension_feature_2_desc' => SiteSetting::get('extension_feature_2_desc', 'Your API key is stored locally and tied to your account with instant rotation.'),
            'extension_feature_3_title' => SiteSetting::get('extension_feature_3_title', 'Built for Speed'),
            'extension_feature_3_desc' => SiteSetting::get('extension_feature_3_desc', 'Audio streams immediately with no waiting. Keep your workflow uninterrupted.'),

            // Extension behaviour defaults
            'extension_default_language' => SiteSetting::get('extension_default_language', 'ar'),
            'extension_default_translate' => SiteSetting::get('extension_default_translate', '1'),

            // API key settings
            'extension_api_key_prefix' => SiteSetting::get('extension_api_key_prefix', 'lk_live_'),
            'extension_api_key_length' => SiteSetting::get('extension_api_key_length', '48'),

            // Download settings
            'extension_download_enabled' => SiteSetting::get('extension_download_enabled', '1'),
            'extension_custom_api_base' => SiteSetting::get('extension_custom_api_base', ''),

            // Announcement
            'extension_announcement' => SiteSetting::get('extension_announcement', ''),
            'extension_announcement_type' => SiteSetting::get('extension_announcement_type', 'info'),

            // Installation guide
            'extension_install_step_1' => SiteSetting::get('extension_install_step_1', 'Download the extension package using the button above.'),
            'extension_install_step_2' => SiteSetting::get('extension_install_step_2', 'Open chrome://extensions/ in your browser.'),
            'extension_install_step_3' => SiteSetting::get('extension_install_step_3', 'Enable Developer mode in the top right corner.'),
            'extension_install_step_4' => SiteSetting::get('extension_install_step_4', 'Click Load unpacked and select the extracted folder.'),
            'extension_install_step_5' => SiteSetting::get('extension_install_step_5', 'Pin the extension to your toolbar for quick access.'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('ExtensionTabs')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Browser Availability')
                            ->icon('heroicon-o-globe-alt')
                            ->schema([
                                Forms\Components\Section::make('Browser Toggles')
                                    ->description('Control which browsers are shown as available on the extension page.')
                                    ->columns(3)
                                    ->schema([
                                        Forms\Components\Toggle::make('extension_chrome_enabled')
                                            ->label('Chrome Extension')
                                            ->helperText('Show download button'),
                                        Forms\Components\Toggle::make('extension_firefox_enabled')
                                            ->label('Firefox Extension')
                                            ->helperText('Shows as "coming soon" if disabled'),
                                        Forms\Components\Toggle::make('extension_safari_enabled')
                                            ->label('Safari Extension')
                                            ->helperText('Shows as "coming soon" if disabled'),
                                    ]),
                            ]),
                        Forms\Components\Tabs\Tab::make('Extension Metadata')
                            ->icon('heroicon-o-document-text')
                            ->schema([
                                Forms\Components\Section::make('Chrome Extension Manifest')
                                    ->description('Updates the extension manifest.json on next download.')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('extension_chrome_name')
                                            ->label('Extension Name')
                                            ->required()
                                            ->maxLength(100),
                                        Forms\Components\TextInput::make('extension_chrome_version')
                                            ->label('Version')
                                            ->required()
                                            ->maxLength(20),

                                        Forms\Components\Textarea::make('extension_chrome_description')
                                            ->label('Description')
                                            ->rows(2)
                                            ->maxLength(500)
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Forms\Components\Tabs\Tab::make('Extension Icons')
                            ->icon('heroicon-o-photo')
                            ->schema([
                                Forms\Components\Section::make('Extension Icons')
                                    ->description('Upload custom icons for the extension. Applied to the next downloaded build. Leave empty to use defaults.')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\FileUpload::make('extension_icon_16')
                                            ->label('Icon 16×16')
                                            ->image()
                                            ->directory('extension-icons')
                                            ->disk('public')
                                            ->imageResizeMode('cover')
                                            ->imageResizeTargetWidth('16')
                                            ->imageResizeTargetHeight('16')
                                            ->helperText('Toolbar icon (smallest)'),
                                        Forms\Components\FileUpload::make('extension_icon_32')
                                            ->label('Icon 32×32')
                                            ->image()
                                            ->directory('extension-icons')
                                            ->disk('public')
                                            ->imageResizeMode('cover')
                                            ->imageResizeTargetWidth('32')
                                            ->imageResizeTargetHeight('32')
                                            ->helperText('Windows taskbar'),
                                        Forms\Components\FileUpload::make('extension_icon_48')
                                            ->label('Icon 48×48')
                                            ->image()
                                            ->directory('extension-icons')
                                            ->disk('public')
                                            ->imageResizeMode('cover')
                                            ->imageResizeTargetWidth('48')
                                            ->imageResizeTargetHeight('48')
                                            ->helperText('Extensions management page'),
                                        Forms\Components\FileUpload::make('extension_icon_128')
                                            ->label('Icon 128×128')
                                            ->image()
                                            ->directory('extension-icons')
                                            ->disk('public')
                                            ->imageResizeMode('cover')
                                            ->imageResizeTargetWidth('128')
                                            ->imageResizeTargetHeight('128')
                                            ->helperText('Chrome Web Store / install dialog'),
                                    ]),
                            ]),
                        Forms\Components\Tabs\Tab::make('Page Content')
                            ->icon('heroicon-o-pencil-square')
                            ->schema([
                                Forms\Components\Section::make('Extension Page Hero')
                                    ->columns(1)
                                    ->schema([
                                        Forms\Components\TextInput::make('extension_page_title')
                                            ->label('Page Title')
                                            ->required(),
                                        Forms\Components\Textarea::make('extension_page_subtitle')
                                            ->label('Page Subtitle')
                                            ->rows(2),
                                    ]),
                                Forms\Components\Section::make('Browser Cards')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('extension_chrome_card_title')->label('Chrome Card Title'),
                                        Forms\Components\TextInput::make('extension_chrome_card_subtitle')->label('Chrome Card Subtitle'),
                                        Forms\Components\TextInput::make('extension_firefox_card_title')->label('Firefox Card Title'),
                                        Forms\Components\TextInput::make('extension_firefox_card_subtitle')->label('Firefox Card Subtitle'),
                                        Forms\Components\TextInput::make('extension_safari_card_title')->label('Safari Card Title'),
                                        Forms\Components\TextInput::make('extension_safari_card_subtitle')->label('Safari Card Subtitle'),
                                    ]),
                                Forms\Components\Section::make('Feature Cards')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('extension_feature_1_title')->label('Feature 1 Title'),
                                        Forms\Components\TextInput::make('extension_feature_1_desc')->label('Feature 1 Description'),
                                        Forms\Components\TextInput::make('extension_feature_2_title')->label('Feature 2 Title'),
                                        Forms\Components\TextInput::make('extension_feature_2_desc')->label('Feature 2 Description'),
                                        Forms\Components\TextInput::make('extension_feature_3_title')->label('Feature 3 Title'),
                                        Forms\Components\TextInput::make('extension_feature_3_desc')->label('Feature 3 Description'),
                                    ]),
                                Forms\Components\Section::make('Installation Steps')
                                    ->description('Customize the installation guide shown to users.')
                                    ->schema([
                                        Forms\Components\TextInput::make('extension_install_step_1')->label('Step 1'),
                                        Forms\Components\TextInput::make('extension_install_step_2')->label('Step 2'),
                                        Forms\Components\TextInput::make('extension_install_step_3')->label('Step 3'),
                                        Forms\Components\TextInput::make('extension_install_step_4')->label('Step 4'),
                                        Forms\Components\TextInput::make('extension_install_step_5')->label('Step 5'),
                                    ]),
                            ]),
                        Forms\Components\Tabs\Tab::make('Behaviour')
                            ->icon('heroicon-o-cog-6-tooth')
                            ->schema([
                                Forms\Components\Section::make('Default Extension Behaviour')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Select::make('extension_default_language')
                                            ->label('Default Language')
                                            ->options(['ar' => 'Arabic', 'en' => 'English']),
                                        Forms\Components\Select::make('extension_default_translate')
                                            ->label('Default Translate')
                                            ->options(['1' => 'Yes', '0' => 'No']),
                                        Forms\Components\Grid::make(2)
                                            ->columnSpanFull()
                                            ->schema([
                                                Forms\Components\Placeholder::make('limits_info')
                                                    ->label('Rate Limits & Quotas')
                                                    ->content('Per-request character limits and monthly usage quotas are enforced per-user based on their active Plan. To edit these limits, go to the Plans resource.'),
                                            ]),
                                    ]),
                                Forms\Components\Section::make('API Key Configuration')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('extension_api_key_prefix')
                                            ->label('API Key Prefix'),
                                        Forms\Components\TextInput::make('extension_api_key_length')
                                            ->label('API Key Length')
                                            ->numeric(),
                                    ]),
                            ]),
                        Forms\Components\Tabs\Tab::make('Download & Announcement')
                            ->icon('heroicon-o-arrow-down-tray')
                            ->schema([
                                Forms\Components\Section::make('Download & Distribution')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Toggle::make('extension_download_enabled')
                                            ->label('Allow Extension Download'),
                                        Forms\Components\TextInput::make('extension_custom_api_base')
                                            ->label('Custom API Base URL')
                                            ->url()
                                            ->placeholder(config('app.url')),
                                    ]),
                                Forms\Components\Section::make('Announcement Banner')
                                    ->description('Show a banner on the extension page.')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Textarea::make('extension_announcement')
                                            ->label('Announcement Text')
                                            ->rows(2)
                                            ->columnSpanFull()
                                            ->helperText('Leave empty to hide.'),
                                        Forms\Components\Select::make('extension_announcement_type')
                                            ->label('Style')
                                            ->options([
                                                'info' => '💡 Info',
                                                'warning' => '⚠️ Warning',
                                                'success' => '✅ Success',
                                                'danger' => '🚨 Danger',
                                            ]),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            SiteSetting::set($key, $value ?? '', 'extension');
        }

        Notification::make()
            ->title('Extension settings saved')
            ->success()
            ->send();
    }
}
