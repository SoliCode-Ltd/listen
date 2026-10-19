<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A studio page left open long holds an expired form token, and every
 * Generate or delete then failed with 419, "Please try again" never working.
 * The page now gets a fresh token from the server and sends the request once
 * more; when that fails too (signed out meanwhile) it offers a reload, and the
 * text being written is kept for the reloaded page.
 */
class StudioExpiredPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_a_fresh_token_is_the_sessions_own_and_never_cached(): void
    {
        $response = $this->actingAs($this->member())->withoutMiddleware($this->localization())
            ->getJson($this->localizedRoute('dashboard.csrf-token'))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(session()->token(), $response->json('token'));
        $this->assertNotSame('', (string) $response->json('token'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $middleware = Route::getRoutes()->getByName('dashboard.csrf-token')?->gatherMiddleware() ?? [];
        $this->assertContains('auth', $middleware);
        $this->assertNotContains('subscription.sync', $middleware);
    }

    public function test_a_guest_gets_no_token(): void
    {
        $this->withoutMiddleware($this->localization())->getJson($this->localizedRoute('dashboard.csrf-token'))->assertUnauthorized();
    }

    public function test_a_request_refused_for_its_expired_token_goes_through_with_the_fresh_one(): void
    {
        $this->enforceCsrf();
        $user = $this->member();
        $queue = $this->localizedRoute('dashboard.queue');

        // What the page sends after a long idle: its old token.
        $this->actingAs($user)->withoutMiddleware($this->localization())
            ->postJson($queue, ['_token' => 'expired-token'])
            ->assertStatus(419)
            ->assertJsonPath('ok', false);

        $fresh = (string) $this->actingAs($user)->withoutMiddleware($this->localization())
            ->getJson($this->localizedRoute('dashboard.csrf-token'))->json('token');

        // Sent once more with the fresh token: past the token check (here to the form check).
        $this->actingAs($user)->withoutMiddleware($this->localization())
            ->postJson($queue, ['_token' => $fresh])
            ->assertStatus(422)
            ->assertJsonValidationErrors('content');
    }

    public function test_the_studio_renews_the_token_once_and_then_offers_a_reload(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $html = $this->actingAs($this->member())->getLocalizedRoute('dashboard')->assertOk()->getContent();

            $this->assertStringContainsString("const csrfTokenUrl = '".route('dashboard.csrf-token')."';", $html);
            $this->assertStringContainsString('window.listenFetchWithFreshCsrf = listenFetchWithFreshCsrf;', $html);
            $this->assertStringContainsString('if (response.status !== 419 || !(await renewCsrfToken())) return response;', $html);
            // The form's own _token is renewed with the header, as the server reads it first.
            $this->assertStringContainsString("if (options.body instanceof FormData && options.body.has('_token')) options.body.set('_token', csrfToken);", $html);
            $this->assertStringContainsString("document.querySelectorAll('input[name=\"_token\"]').forEach((input) => { input.value = token; });", $html);
            // Generate and delete both go through it, and both offer the reload.
            $this->assertStringContainsString('response = await listenFetchWithFreshCsrf(queueUrl, {', $html);
            $this->assertStringContainsString('const response = await listenFetchWithFreshCsrf(`${baseUrl}/${parsedJobId}`, {', $html);
            $this->assertStringContainsString('showPageExpired(formMessage);', $html);
            $this->assertStringContainsString('showPageExpired(jobsMessage);', $html);
            $this->assertStringContainsString('data-action="reload-page"', $html);
            // The text being written survives the reload.
            $this->assertStringContainsString('sessionStorage.setItem(studioDraftKey,', $html);
            $this->assertStringContainsString('(function restoreStudioDraft() {', $html);
            foreach (['page_expired', 'reload_page'] as $key) {
                $this->assertStringContainsString($this->jsonText(trans("ui.dashboard.{$key}", [], $locale)), $html, "{$key} in {$locale}");
            }
        }

        $this->assertNotSame(trans('ui.dashboard.page_expired', [], 'en'), trans('ui.dashboard.page_expired', [], 'ar'));
        $this->assertNotSame(trans('ui.dashboard.reload_page', [], 'en'), trans('ui.dashboard.reload_page', [], 'ar'));
    }

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

    /** @return array<int, class-string> */
    private function localization(): array
    {
        return [
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ];
    }

    /** How the page's @json writes a text. */
    private function jsonText(string $text): string
    {
        return substr(json_encode($text, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), 1, -1);
    }

    private function member(): User
    {
        $plan = Plan::factory()->create(['voice_tier' => 'standard', 'is_active' => true, 'monthly_chars' => 100000, 'max_chars_per_request' => 5000, 'monthly_requests' => 0]);
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }
}
