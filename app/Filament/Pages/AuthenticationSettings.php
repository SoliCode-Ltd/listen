<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use App\Services\Auth\AuthSettingsService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;

class AuthenticationSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Authentication';

    protected static string $view = 'filament.pages.authentication-settings';

    public ?array $data = [];

    public function mount(AuthSettingsService $authSettings): void
    {
        $firebaseConfig = $authSettings->firebaseClientConfig();

        $this->form->fill([
            'auth_email_enabled' => $authSettings->emailEnabled(),
            'auth_google_enabled' => (string) SiteSetting::get(AuthSettingsService::KEY_GOOGLE_ENABLED, '1') === '1',
            'auth_phone_enabled' => $authSettings->phoneToggleEnabled(),
            'firebase_phone_enabled' => $authSettings->firebasePhoneEnabled(),
            'firebase_recaptcha_mode' => $authSettings->recaptchaMode(),
            'firebase_api_key' => $firebaseConfig['apiKey'] ?? '',
            'firebase_auth_domain' => $firebaseConfig['authDomain'] ?? '',
            'firebase_project_id' => $firebaseConfig['projectId'] ?? '',
            'firebase_app_id' => $firebaseConfig['appId'] ?? '',
            'firebase_messaging_sender_id' => $firebaseConfig['messagingSenderId'] ?? '',
            'firebase_storage_bucket' => $firebaseConfig['storageBucket'] ?? '',
            'firebase_measurement_id' => $firebaseConfig['measurementId'] ?? '',
            'google_configured' => $authSettings->googleConfigured(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Sign-in Methods')
                    ->description('Control which authentication methods are visible and allowed in backend routes.')
                    ->schema([
                        Forms\Components\Toggle::make('auth_email_enabled')
                            ->label('Email / Password')
                            ->helperText('Keeps classic email/password login and registration available.')
                            ->default(true),
                        Forms\Components\Toggle::make('auth_google_enabled')
                            ->label('Google Sign-in')
                            ->helperText('Requires GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in environment.')
                            ->default(true),
                        Forms\Components\Toggle::make('auth_phone_enabled')
                            ->label('Phone Number (Firebase)')
                            ->helperText('Enables OTP authentication with Firebase phone verification.')
                            ->default(false),
                        Forms\Components\Hidden::make('google_configured'),
                        Forms\Components\Placeholder::make('google_runtime_status')
                            ->label('Google Runtime Status')
                            ->content(fn (Forms\Get $get): string => (bool) $get('google_configured')
                                ? 'Configured in environment.'
                                : 'Missing GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET in environment.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Firebase Phone Authentication')
                    ->description('These values are safe client configuration keys used for Firebase web SDK initialization.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('firebase_phone_enabled')
                            ->label('Enable Firebase Phone Auth Globally')
                            ->helperText('Must be enabled together with "Phone Number (Firebase)" above for live phone auth.')
                            ->default(false)
                            ->columnSpanFull(),
                        Forms\Components\Select::make('firebase_recaptcha_mode')
                            ->label('reCAPTCHA Mode')
                            ->options([
                                'invisible' => 'Invisible',
                                'normal' => 'Normal (visible widget)',
                            ])
                            ->required()
                            ->native(false)
                            ->default('invisible'),
                        Forms\Components\TextInput::make('firebase_api_key')
                            ->label('Firebase API Key')
                            ->maxLength(255)
                            ->placeholder('AIza...')
                            ->required(fn (Forms\Get $get): bool => (bool) $get('auth_phone_enabled')),
                        Forms\Components\TextInput::make('firebase_auth_domain')
                            ->label('Firebase Auth Domain')
                            ->maxLength(255)
                            ->placeholder('your-project.firebaseapp.com')
                            ->required(fn (Forms\Get $get): bool => (bool) $get('auth_phone_enabled')),
                        Forms\Components\TextInput::make('firebase_project_id')
                            ->label('Firebase Project ID')
                            ->maxLength(255)
                            ->required(fn (Forms\Get $get): bool => (bool) $get('auth_phone_enabled')),
                        Forms\Components\TextInput::make('firebase_app_id')
                            ->label('Firebase App ID')
                            ->maxLength(255)
                            ->required(fn (Forms\Get $get): bool => (bool) $get('auth_phone_enabled')),
                        Forms\Components\TextInput::make('firebase_messaging_sender_id')
                            ->label('Firebase Messaging Sender ID')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('firebase_storage_bucket')
                            ->label('Firebase Storage Bucket')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('firebase_measurement_id')
                            ->label('Firebase Measurement ID')
                            ->maxLength(255),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(AuthSettingsService $authSettings): void
    {
        $data = $this->form->getState();
        $this->validateBeforeSave($data, $authSettings);

        SiteSetting::set(AuthSettingsService::KEY_EMAIL_ENABLED, $this->boolToSetting($data['auth_email_enabled'] ?? false), AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_GOOGLE_ENABLED, $this->boolToSetting($data['auth_google_enabled'] ?? false), AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_PHONE_ENABLED, $this->boolToSetting($data['auth_phone_enabled'] ?? false), AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_PHONE_ENABLED, $this->boolToSetting($data['firebase_phone_enabled'] ?? false), AuthSettingsService::GROUP);

        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_RECAPTCHA_MODE, $this->normalizeRecaptchaMode((string) ($data['firebase_recaptcha_mode'] ?? 'invisible')), AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_API_KEY, trim((string) ($data['firebase_api_key'] ?? '')), AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_AUTH_DOMAIN, trim((string) ($data['firebase_auth_domain'] ?? '')), AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_PROJECT_ID, trim((string) ($data['firebase_project_id'] ?? '')), AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_APP_ID, trim((string) ($data['firebase_app_id'] ?? '')), AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_MESSAGING_SENDER_ID, trim((string) ($data['firebase_messaging_sender_id'] ?? '')), AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_STORAGE_BUCKET, trim((string) ($data['firebase_storage_bucket'] ?? '')), AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_MEASUREMENT_ID, trim((string) ($data['firebase_measurement_id'] ?? '')), AuthSettingsService::GROUP);

        SiteSetting::flushGroupCache(AuthSettingsService::GROUP);

        Notification::make()
            ->title('Authentication settings saved')
            ->success()
            ->send();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateBeforeSave(array $data, AuthSettingsService $authSettings): void
    {
        $emailEnabled = (bool) ($data['auth_email_enabled'] ?? false);
        $googleEnabled = (bool) ($data['auth_google_enabled'] ?? false);
        $phoneEnabled = (bool) ($data['auth_phone_enabled'] ?? false);
        $firebaseEnabled = (bool) ($data['firebase_phone_enabled'] ?? false);

        if (! $emailEnabled && ! $googleEnabled && ! $phoneEnabled) {
            throw ValidationException::withMessages([
                'data.auth_email_enabled' => 'Enable at least one sign-in method.',
            ]);
        }

        if ($phoneEnabled) {
            if (! $firebaseEnabled) {
                throw ValidationException::withMessages([
                    'data.firebase_phone_enabled' => 'Enable Firebase phone auth globally to activate phone sign-in.',
                ]);
            }

            $requiredKeys = [
                'firebase_api_key' => 'Firebase API Key',
                'firebase_auth_domain' => 'Firebase Auth Domain',
                'firebase_project_id' => 'Firebase Project ID',
                'firebase_app_id' => 'Firebase App ID',
            ];

            $missing = [];
            foreach ($requiredKeys as $field => $label) {
                if (trim((string) ($data[$field] ?? '')) === '') {
                    $missing[] = $label;
                }
            }

            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'data.firebase_api_key' => 'Phone auth requires complete Firebase config: '.implode(', ', $missing).'.',
                ]);
            }
        }

        $googleAvailable = $googleEnabled && $authSettings->googleConfigured();
        $phoneAvailable = $phoneEnabled && $firebaseEnabled && $this->firebaseConfigCompleteFromInput($data);
        if (! $emailEnabled && ! $googleAvailable && ! $phoneAvailable) {
            throw ValidationException::withMessages([
                'data.auth_google_enabled' => 'No active sign-in method is usable. Enable Email/Password, or configure Google/Firebase completely.',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function firebaseConfigCompleteFromInput(array $data): bool
    {
        return trim((string) ($data['firebase_api_key'] ?? '')) !== ''
            && trim((string) ($data['firebase_auth_domain'] ?? '')) !== ''
            && trim((string) ($data['firebase_project_id'] ?? '')) !== ''
            && trim((string) ($data['firebase_app_id'] ?? '')) !== '';
    }

    private function normalizeRecaptchaMode(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['invisible', 'normal'], true) ? $value : 'invisible';
    }

    private function boolToSetting(bool $value): string
    {
        return $value ? '1' : '0';
    }
}
