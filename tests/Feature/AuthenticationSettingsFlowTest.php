<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Auth\AuthSettingsService;
use App\Services\Auth\PhoneAuthBridgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AuthenticationSettingsFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_login_page_hides_email_form_when_email_auth_is_disabled(): void
    {
        SiteSetting::set(AuthSettingsService::KEY_EMAIL_ENABLED, '0', AuthSettingsService::GROUP);
        $this->enablePhoneSettings();

        $response = $this->getLocalizedRoute('login');

        $response->assertOk();
        $response->assertDontSee('name="email"', false);
        $response->assertSee('id="phone_number"', false);
    }

    public function test_backend_blocks_email_login_when_email_auth_is_disabled(): void
    {
        $user = User::factory()->create();
        SiteSetting::set(AuthSettingsService::KEY_EMAIL_ENABLED, '0', AuthSettingsService::GROUP);

        $response = $this->postLocalizedRoute('login.submit', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect($this->localizedRoute('login'));
        $response->assertSessionHasErrors('auth');
        $this->assertGuest();
    }

    public function test_backend_blocks_google_redirect_when_google_auth_is_disabled(): void
    {
        SiteSetting::set(AuthSettingsService::KEY_GOOGLE_ENABLED, '0', AuthSettingsService::GROUP);

        $response = $this->getLocalizedRoute('auth.social.redirect');

        $response->assertRedirect($this->localizedRoute('login'));
        $response->assertSessionHasErrors('auth');
    }

    public function test_phone_bridge_logs_user_in_when_phone_auth_enabled(): void
    {
        $this->enablePhoneSettings();

        $user = User::factory()->create([
            'firebase_uid' => 'firebase-uid-test',
            'phone_number' => '+201234567890',
            'phone_verified_at' => now(),
        ]);

        $mock = Mockery::mock(PhoneAuthBridgeService::class);
        $mock->shouldReceive('resolveUserFromIdToken')
            ->once()
            ->with('mocked_id_token', Mockery::any())
            ->andReturn($user);

        $this->app->instance(PhoneAuthBridgeService::class, $mock);

        $response = $this->postJsonLocalizedRoute('auth.phone.verify', [
            'id_token' => 'mocked_id_token',
            'intent' => 'login',
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $this->assertAuthenticatedAs($user);
    }

    public function test_phone_bridge_route_is_blocked_when_phone_auth_disabled(): void
    {
        SiteSetting::set(AuthSettingsService::KEY_PHONE_ENABLED, '0', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_PHONE_ENABLED, '0', AuthSettingsService::GROUP);

        $response = $this->postJsonLocalizedRoute('auth.phone.verify', [
            'id_token' => 'any_token',
            'intent' => 'login',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('ok', false);
    }

    private function enablePhoneSettings(): void
    {
        SiteSetting::set(AuthSettingsService::KEY_PHONE_ENABLED, '1', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_PHONE_ENABLED, '1', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_API_KEY, 'AIzaSyMockKey', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_AUTH_DOMAIN, 'listen.firebaseapp.com', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_PROJECT_ID, 'listen-project', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_APP_ID, '1:123456789:web:abcdef012345', AuthSettingsService::GROUP);
    }
}

