<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
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
            'default_theme' => SiteSetting::get('default_theme', 'system'),
            'custom_css' => SiteSetting::get('custom_css', ''),
            'favicon_url' => SiteSetting::get('favicon_url', ''),
            'logo_url' => SiteSetting::get('logo_url', ''),
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
                        Forms\Components\Textarea::make('site_description')
                            ->label('Site Description')
                            ->rows(3)
                            ->columnSpanFull(),
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
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach ($data as $key => $value) {
            SiteSetting::set($key, $value, 'general');
        }

        SiteSetting::flushGroupCache('general');

        Notification::make()
            ->title('Site settings saved')
            ->success()
            ->send();
    }
}
