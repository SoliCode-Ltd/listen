<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Auth\AuthSettingsService;
use App\Services\Auth\PhoneAuthBridgeService;
use App\Services\PlanAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class SignInLandingTest extends TestCase
{
    use RefreshDatabase;

    private Plan $starter;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::create([
            'slug' => 'free', 'name' => 'FREE', 'price_cents' => 0, 'monthly_minutes' => 5,
            'monthly_chars' => 4500, 'monthly_requests' => 50, 'max_chars_per_request' => 600, 'is_active' => true,
        ]);
        $this->starter = Plan::create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_minutes' => 250,
            'monthly_chars' => 225000, 'monthly_requests' => 3000, 'max_chars_per_request' => 3000, 'is_active' => true,
        ]);
    }

    private function landedOn(TestResponse $response): string
    {
        $url = (string) ($response->headers->get('Location') ?: $response->json('redirect'));

        return (string) preg_replace('#^/[a-z]{2}(?=/|$)#', '', (string) parse_url($url, PHP_URL_PATH));
    }

    private function signIn(User $user): TestResponse
    {
        return $this->postLocalizedRoute('login.submit', ['email' => $user->email, 'password' => 'password']);
    }

    public function test_a_new_member_starts_on_the_free_demo(): void
    {
        $response = $this->postLocalizedRoute('register.submit', [
            'name' => 'New Member', 'email' => 'new@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);

        $this->assertAuthenticated();
        $this->assertSame('/demo', $this->landedOn($response));
    }

    public function test_a_member_without_a_paid_plan_signs_in_to_the_demo(): void
    {
        $this->assertSame('/demo', $this->landedOn($this->signIn(User::factory()->create())));
    }

    public function test_a_paying_member_signs_in_to_the_dashboard(): void
    {
        $user = User::factory()->create();
        app(PlanAccessService::class)->assignPlan($user, $this->starter, 'active', now()->addMonth());

        $this->assertSame('/dashboard', $this->landedOn($this->signIn($user)));
    }

    public function test_the_page_a_member_was_heading_to_comes_first(): void
    {
        $user = User::factory()->create();

        $response = $this->withSession(['url.intended' => url('/en/pricing')])->postLocalizedRoute('login.submit', [
            'email' => $user->email, 'password' => 'password',
        ]);

        $this->assertSame('/pricing', $this->landedOn($response));
    }

    public function test_admins_keep_the_dashboard(): void
    {
        $this->assertSame('/dashboard', $this->landedOn($this->signIn(User::factory()->create(['is_admin' => true]))));
    }

    public function test_phone_sign_in_sends_a_member_without_a_paid_plan_to_the_demo(): void
    {
        SiteSetting::set(AuthSettingsService::KEY_PHONE_ENABLED, '1', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_PHONE_ENABLED, '1', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_API_KEY, 'AIzaSyMockKey', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_AUTH_DOMAIN, 'listen.firebaseapp.com', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_PROJECT_ID, 'listen-project', AuthSettingsService::GROUP);
        SiteSetting::set(AuthSettingsService::KEY_FIREBASE_APP_ID, '1:123456789:web:abcdef012345', AuthSettingsService::GROUP);
        $user = User::factory()->create(['phone_number' => '+201234567890', 'phone_verified_at' => now()]);
        $bridge = Mockery::mock(PhoneAuthBridgeService::class);
        $bridge->shouldReceive('resolveUserFromIdToken')->andReturn($user);
        $this->app->instance(PhoneAuthBridgeService::class, $bridge);

        $response = $this->postJsonLocalizedRoute('auth.phone.verify', ['id_token' => 'token', 'intent' => 'login']);

        $response->assertOk();
        $this->assertSame('/demo', $this->landedOn($response));
    }
}
