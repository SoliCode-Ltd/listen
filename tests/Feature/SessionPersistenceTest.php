<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function enforceCsrf(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    private function localizationMiddleware(): array
    {
        return [
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ];
    }

    public function test_login_page_is_not_cached_and_remembers_by_default(): void
    {
        $response = $this->getLocalizedRoute('login');

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertSee('name="remember" value="1" checked', false);
    }

    public function test_register_page_is_not_cached(): void
    {
        $response = $this->getLocalizedRoute('register');

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_login_with_remember_sets_a_long_lived_cookie(): void
    {
        $user = User::factory()->create();

        $response = $this->postLocalizedRoute('login.submit', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertCookie(auth()->guard()->getRecallerName());
    }

    public function test_registration_remembers_the_new_user(): void
    {
        $response = $this->postLocalizedRoute('register.submit', [
            'name' => 'New Person',
            'email' => 'new.person@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $this->assertAuthenticated();
        $response->assertCookie(auth()->guard()->getRecallerName());
    }

    public function test_expired_token_on_login_returns_to_form_with_message_instead_of_419(): void
    {
        $this->enforceCsrf();
        $user = User::factory()->create();
        $loginUrl = $this->localizedRoute('login');

        $response = $this->withoutMiddleware($this->localizationMiddleware())
            ->from($loginUrl)
            ->post($this->localizedRoute('login.submit'), [
                '_token' => 'stale-token',
                'email' => $user->email,
                'password' => 'password',
            ]);

        $response->assertRedirect($loginUrl);
        $response->assertSessionHasErrors(['auth' => __('ui.auth.session_expired')]);
        $response->assertSessionHasInput('email', $user->email);
        $response->assertSessionMissing('_old_input.password');
        $this->assertGuest();
    }

    public function test_expired_token_when_already_signed_in_goes_to_dashboard(): void
    {
        $this->enforceCsrf();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withoutMiddleware($this->localizationMiddleware())
            ->post($this->localizedRoute('login.submit'), [
                '_token' => 'stale-token',
                'email' => $user->email,
                'password' => 'password',
            ]);

        $response->assertRedirect(route('dashboard'));
    }

    public function test_expired_token_on_json_request_returns_readable_message(): void
    {
        $this->enforceCsrf();

        $response = $this->withoutMiddleware($this->localizationMiddleware())
            ->postJson($this->localizedRoute('login.submit'), ['email' => 'a@b.c']);

        $response->assertStatus(419);
        $response->assertJson(['ok' => false, 'message' => __('ui.auth.session_expired')]);
    }
}
