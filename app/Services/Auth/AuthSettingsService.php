<?php

namespace App\Services\Auth;

use App\Models\SiteSetting;

class AuthSettingsService
{
    public const GROUP = 'authentication';

    public const KEY_EMAIL_ENABLED = 'auth_email_enabled';
    public const KEY_GOOGLE_ENABLED = 'auth_google_enabled';
    public const KEY_PHONE_ENABLED = 'auth_phone_enabled';
    public const KEY_FIREBASE_PHONE_ENABLED = 'firebase_phone_enabled';
    public const KEY_FIREBASE_GOOGLE_ENABLED = 'firebase_google_enabled';
    public const KEY_FIREBASE_API_KEY = 'firebase_api_key';
    public const KEY_FIREBASE_AUTH_DOMAIN = 'firebase_auth_domain';
    public const KEY_FIREBASE_PROJECT_ID = 'firebase_project_id';
    public const KEY_FIREBASE_APP_ID = 'firebase_app_id';
    public const KEY_FIREBASE_MESSAGING_SENDER_ID = 'firebase_messaging_sender_id';
    public const KEY_FIREBASE_STORAGE_BUCKET = 'firebase_storage_bucket';
    public const KEY_FIREBASE_MEASUREMENT_ID = 'firebase_measurement_id';
    public const KEY_FIREBASE_RECAPTCHA_MODE = 'firebase_recaptcha_mode';
    public const KEY_FIREBASE_RECAPTCHA_SITE_KEY = 'firebase_recaptcha_site_key';
    public const KEY_FIREBASE_PHONE_TESTING_MODE = 'firebase_phone_testing_mode';

    public function emailEnabled(): bool
    {
        return $this->settingEnabled(self::KEY_EMAIL_ENABLED, true);
    }

    public function googleEnabled(): bool
    {
        return $this->settingEnabled(self::KEY_GOOGLE_ENABLED, true) && $this->googleConfigured();
    }

    public function googleConfigured(): bool
    {
        return filled((string) config('services.google.client_id'))
            && filled((string) config('services.google.client_secret'));
    }

    public function phoneToggleEnabled(): bool
    {
        return $this->settingEnabled(self::KEY_PHONE_ENABLED, false);
    }

    public function firebasePhoneEnabled(): bool
    {
        return $this->settingEnabled(self::KEY_FIREBASE_PHONE_ENABLED, false);
    }

    public function phoneEnabled(): bool
    {
        return $this->phoneToggleEnabled()
            && $this->firebasePhoneEnabled()
            && $this->firebaseConfigComplete();
    }

    public function firebaseGoogleEnabled(): bool
    {
        return $this->settingEnabled(self::KEY_FIREBASE_GOOGLE_ENABLED, false)
            && $this->firebaseConfigComplete();
    }

    public function hasAnyEnabledMethod(): bool
    {
        return $this->emailEnabled() || $this->googleEnabled() || $this->phoneEnabled() || $this->firebaseGoogleEnabled();
    }

    public function methodEnabled(string $method): bool
    {
        return match (strtolower(trim($method))) {
            'email' => $this->emailEnabled(),
            'google' => $this->googleEnabled(),
            'phone' => $this->phoneEnabled(),
            'firebase_google' => $this->firebaseGoogleEnabled(),
            default => false,
        };
    }

    /**
     * @return array<string, string>
     */
    public function firebaseClientConfig(): array
    {
        return [
            'apiKey' => trim((string) SiteSetting::get(self::KEY_FIREBASE_API_KEY, '')),
            'authDomain' => trim((string) SiteSetting::get(self::KEY_FIREBASE_AUTH_DOMAIN, '')),
            'projectId' => trim((string) SiteSetting::get(self::KEY_FIREBASE_PROJECT_ID, '')),
            'appId' => trim((string) SiteSetting::get(self::KEY_FIREBASE_APP_ID, '')),
            'messagingSenderId' => trim((string) SiteSetting::get(self::KEY_FIREBASE_MESSAGING_SENDER_ID, '')),
            'storageBucket' => trim((string) SiteSetting::get(self::KEY_FIREBASE_STORAGE_BUCKET, '')),
            'measurementId' => trim((string) SiteSetting::get(self::KEY_FIREBASE_MEASUREMENT_ID, '')),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function firebasePublicConfigForFrontend(): array
    {
        return array_filter(
            $this->firebaseClientConfig(),
            static fn (string $value): bool => $value !== ''
        );
    }

    public function firebaseConfigComplete(): bool
    {
        $config = $this->firebaseClientConfig();

        return $config['apiKey'] !== ''
            && $config['authDomain'] !== ''
            && $config['projectId'] !== ''
            && $config['appId'] !== '';
    }

    public function recaptchaMode(): string
    {
        $value = strtolower(trim((string) SiteSetting::get(self::KEY_FIREBASE_RECAPTCHA_MODE, 'invisible')));

        return in_array($value, ['invisible', 'normal'], true) ? $value : 'invisible';
    }

    public function recaptchaSiteKey(): string
    {
        return trim((string) SiteSetting::get(self::KEY_FIREBASE_RECAPTCHA_SITE_KEY, ''));
    }

    public function firebasePhoneTestingModeEnabled(): bool
    {
        return $this->settingEnabled(self::KEY_FIREBASE_PHONE_TESTING_MODE, false);
    }

    public function firebasePhoneTestingModeActive(): bool
    {
        return $this->firebasePhoneTestingModeEnabled() && app()->environment(['local', 'testing']);
    }

    /**
     * @return array{
     *   email_enabled: bool,
     *   google_enabled: bool,
     *   phone_enabled: bool,
     *   firebase_google_enabled: bool,
     *   firebase: array{enabled: bool, recaptcha_mode: string, recaptcha_site_key: string, testing_mode: bool, config: array<string, string>}
     * }
     */
    public function authUiPayload(): array
    {
        $anyFirebase = $this->phoneEnabled() || $this->firebaseGoogleEnabled();

        return [
            'email_enabled' => $this->emailEnabled(),
            'google_enabled' => $this->googleEnabled(),
            'phone_enabled' => $this->phoneEnabled(),
            'firebase_google_enabled' => $this->firebaseGoogleEnabled(),
            'firebase' => [
                'enabled' => $anyFirebase,
                'recaptcha_mode' => $this->recaptchaMode(),
                'recaptcha_site_key' => $this->recaptchaSiteKey(),
                'testing_mode' => $this->firebasePhoneTestingModeActive(),
                'config' => $anyFirebase ? $this->firebasePublicConfigForFrontend() : [],
            ],
        ];
    }

    private function settingEnabled(string $key, bool $default): bool
    {
        $fallback = $default ? '1' : '0';

        return (string) SiteSetting::get($key, $fallback) === '1';
    }
}
