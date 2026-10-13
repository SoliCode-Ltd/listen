<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use App\Services\MultiAccountEnforcementService;
use App\Support\DemoAllowance;
use App\Support\FreeAllowance;
use App\Support\SiteFont;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SiteSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Site Settings';

    protected static string $view = 'filament.pages.site-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'site_name' => SiteSetting::get('site_name', config('app.name')),
            'site_description' => SiteSetting::get('site_description', ''),
            'contact_email' => SiteSetting::get('contact_email', ''),
            'whatsapp_number' => SiteSetting::get('whatsapp_number', '201553212603'),
            'demo_sample_ar' => SiteSetting::get('demo_sample_ar', ''),
            'demo_sample_en' => SiteSetting::get('demo_sample_en', ''),
            'demo_sample_standard_ar' => SiteSetting::get('demo_sample_standard_ar', ''),
            'demo_sample_standard_en' => SiteSetting::get('demo_sample_standard_en', ''),
            'site_main_language' => SiteSetting::get('site_main_language', config('app.locale', 'ar')),
            'default_theme' => SiteSetting::get('default_theme', 'system'),
            'site_font' => SiteFont::current(),
            'site_font_size' => SiteFont::size(),
            'custom_css' => SiteSetting::get('custom_css', ''),
            'favicon_url' => SiteSetting::get('favicon_url', ''),
            'logo_url' => SiteSetting::get('logo_url', ''),
            'footer_social_enabled' => (string) SiteSetting::get('footer_social_enabled', '1') === '1',
            'footer_social_twitter_url' => SiteSetting::get('footer_social_twitter_url', ''),
            'footer_social_github_url' => SiteSetting::get('footer_social_github_url', ''),
            'footer_social_facebook_url' => SiteSetting::get('footer_social_facebook_url', ''),
            'footer_social_linkedin_url' => SiteSetting::get('footer_social_linkedin_url', ''),
            DemoAllowance::SETTING_KEY => DemoAllowance::chars(),
            FreeAllowance::NEW_MEMBERS_KEY => FreeAllowance::freePlanGivesMonthlyChars(),
            'max_accounts_per_ip' => (int) SiteSetting::get('max_accounts_per_ip', 2),
            'multi_account_block_reason_en' => SiteSetting::get(
                MultiAccountEnforcementService::BLOCK_REASON_EN_SETTING_KEY,
                MultiAccountEnforcementService::BLOCK_REASON_EN
            ),
            'multi_account_block_reason_ar' => SiteSetting::get(
                MultiAccountEnforcementService::BLOCK_REASON_AR_SETTING_KEY,
                MultiAccountEnforcementService::BLOCK_REASON_AR
            ),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('General')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('site_name')
                            ->label('Site Name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('contact_email')
                            ->label('Contact Email')
                            ->email()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('whatsapp_number')
                            ->label('WhatsApp Number')
                            ->maxLength(20)
                            ->placeholder('201553212603')
                            ->helperText('Enter the WhatsApp number with country code, no + or spaces (e.g. 201553212603).'),
                        Forms\Components\Select::make('site_main_language')
                            ->label('Main Website Language')
                            ->options([
                                'ar' => 'Arabic (/ar)',
                                'en' => 'English (/en)',
                            ])
                            ->required()
                            ->native(false)
                            ->helperText('Controls the default public website language when visitors open the site without a locale in the URL.'),
                        Forms\Components\Textarea::make('site_description')
                            ->label('Site Description')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Free Demo')
                    ->schema([
                        Forms\Components\TextInput::make(DemoAllowance::SETTING_KEY)
                            ->label('Demo characters per member')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(DemoAllowance::MAX)
                            ->required()
                            ->suffix('characters')
                            ->helperText('How many premium-voice characters each member can try on the demo page, in total. It is also the longest text a member can generate in one go there. Members who already used more keep what they generated; they just cannot generate more.'),
                        Forms\Components\Toggle::make(FreeAllowance::NEW_MEMBERS_KEY)
                            ->label('The free plan gives monthly characters')
                            ->helperText('Off: everyone on the free plan, admins included, gets only the demo above (plus any characters you gift), and their dashboard offers your plans. On: they get the free plan\'s monthly characters set in Plans.'),
                    ]),
                Forms\Components\Section::make('Demo Page Sample Texts')
                    ->description('The sample texts loaded when users click the English/Arabic sample chips on the demo page. HD samples can carry timings and [tags]; Standard samples must be plain text. Leave empty to use the built-in default.')
                    ->columns(1)
                    ->collapsible()
                    ->schema([
                        Forms\Components\Textarea::make('demo_sample_en')
                            ->label('English Sample Text')
                            ->rows(10)
                            ->helperText('Shown when the user clicks the "English sample" chip on the demo page.'),
                        Forms\Components\Textarea::make('demo_sample_ar')
                            ->label('Arabic Sample Text')
                            ->rows(10)
                            ->helperText('Shown when the user clicks the "Arabic sample" chip on the demo page.'),
                        Forms\Components\Textarea::make('demo_sample_standard_en')
                            ->label('English Sample Text (Standard quality)')
                            ->rows(4)
                            ->helperText('Used instead when the demo is set to Standard quality. Standard voices read every character, so use plain sentences only: no timings, [tags] or (stage directions).'),
                        Forms\Components\Textarea::make('demo_sample_standard_ar')
                            ->label('Arabic Sample Text (Standard quality)')
                            ->rows(4)
                            ->helperText('Used instead when the demo is set to Standard quality. Plain sentences only.'),
                    ]),
                Forms\Components\Section::make('Anti-Abuse')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('max_accounts_per_ip')
                            ->label('Max Accounts Per IP')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->default(2)
                            ->required()
                            ->helperText('Maximum number of accounts allowed from the same IP address. Accounts beyond this limit will be automatically blocked. Admins are never auto-blocked.'),
                        Forms\Components\Textarea::make('multi_account_block_reason_en')
                            ->label('Auto-Block Reason (English)')
                            ->rows(3)
                            ->required()
                            ->helperText('Message shown to users blocked automatically for multi-account abuse.'),
                        Forms\Components\Textarea::make('multi_account_block_reason_ar')
                            ->label('Auto-Block Reason (Arabic)')
                            ->rows(3)
                            ->required()
                            ->helperText('الرسالة التي تظهر للمستخدم عند الحظر التلقائي بسبب إساءة استخدام تعدد الحسابات.'),
                    ]),
                Forms\Components\Section::make('Appearance')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('default_theme')
                            ->label('Default Theme')
                            ->options([
                                'light' => 'Light',
                                'dark' => 'Dark',
                                'system' => 'System Default',
                            ])
                            ->required(),
                        Forms\Components\Select::make('site_font')
                            ->label('Website Font')
                            ->options(SiteFont::options())
                            ->default(SiteFont::DEFAULT)
                            ->required()
                            ->native(false)
                            ->helperText('Used for everything on the website (text, headings, buttons and forms) in both Arabic and English.'),
                        Forms\Components\Select::make('site_font_size')
                            ->label('Text Size')
                            ->options(SiteFont::sizeOptions())
                            ->default(SiteFont::DEFAULT_SIZE)
                            ->required()
                            ->native(false)
                            ->helperText('How big the letters look, for any font. 100% matches the original design; spacing and layout stay the same.'),
                        Forms\Components\FileUpload::make('logo_url')
                            ->label('Site Logo')
                            ->acceptedFileTypes([
                                'image/png',
                                'image/jpeg',
                                'image/webp',
                                'image/svg+xml',
                            ])
                            ->maxSize(4096)
                            ->directory('settings')
                            ->disk('public')
                            ->helperText('Upload logo as PNG, JPG, WEBP, or SVG (up to 4 MB).'),
                        Forms\Components\FileUpload::make('favicon_url')
                            ->label('Site Favicon')
                            ->acceptedFileTypes([
                                'image/png',
                                'image/x-icon',
                                'image/vnd.microsoft.icon',
                                'image/svg+xml',
                            ])
                            ->maxSize(2048)
                            ->directory('settings')
                            ->disk('public')
                            ->helperText('Upload favicon as PNG, ICO, or SVG (up to 2 MB).'),
                    ]),
                Forms\Components\Section::make('Custom CSS')
                    ->collapsible()
                    ->schema([
                        Forms\Components\Textarea::make('custom_css')
                            ->label('Custom CSS')
                            ->helperText('Injected into every page <head>. Use for branding overrides.')
                            ->rows(8)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Footer Social Media')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('footer_social_enabled')
                            ->label('Show Social Media In Footer')
                            ->helperText('Disable to hide all social icons in the website footer.')
                            ->default(true)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('footer_social_twitter_url')
                            ->label('Twitter/X URL')
                            ->url()
                            ->maxLength(500)
                            ->placeholder('https://twitter.com/your-account')
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('footer_social_enabled')),
                        Forms\Components\TextInput::make('footer_social_github_url')
                            ->label('GitHub URL')
                            ->url()
                            ->maxLength(500)
                            ->placeholder('https://github.com/your-account')
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('footer_social_enabled')),
                        Forms\Components\TextInput::make('footer_social_facebook_url')
                            ->label('Facebook URL')
                            ->url()
                            ->maxLength(500)
                            ->placeholder('https://facebook.com/your-page')
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('footer_social_enabled')),
                        Forms\Components\TextInput::make('footer_social_linkedin_url')
                            ->label('LinkedIn URL')
                            ->url()
                            ->maxLength(500)
                            ->placeholder('https://www.linkedin.com/company/your-company')
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('footer_social_enabled')),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach ($data as $key => $value) {
            SiteSetting::set($key, is_bool($value) ? ($value ? '1' : '0') : (string) $value, 'general');
        }

        SiteSetting::flushGroupCache('general');

        Notification::make()
            ->title('Site settings saved')
            ->success()
            ->send();
    }
}
