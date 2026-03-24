<?php

namespace Tests\Unit;

use App\Models\SiteSetting;
use App\Services\Auth\AuthSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_enabled_requires_toggle_global_switch_and_complete_firebase_config(): void
    {
        $service = app(AuthSettingsService::class);

        $this->assertFalse($service->phoneEnabled());

        SiteSetting::set(AuthSettingsService::KEY_PHONE_ENABLED, '1', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_PHONE_ENABLED, '1', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_API_KEY, 'AIzaMockKey', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_AUTH_DOMAIN, 'listen.firebaseapp.com', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_PROJECT_ID, 'listen-project', AuthSettingsService::GROUP);

        $this->assertFalse($service->phoneEnabled());

        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_APP_ID, '1:123456789:web:abc123', AuthSettingsService::GROUP);

        $this->assertTrue($service->phoneEnabled());
    }

    public function test_auth_ui_payload_includes_recaptcha_site_key(): void
    {
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_RECAPTCHA_SITE_KEY, '6LcMockSiteKey', AuthSettingsService::GROUP);

        $service = app(AuthSettingsService::class);
        $payload = $service->authUiPayload();

        $this->assertSame('6LcMockSiteKey', $payload['firebase']['recaptcha_site_key'] ?? null);
    }
}
