<?php

namespace Tests\Feature\InApp;

use App\Models\User;
use App\Services\BlockedUserService;
use Illuminate\Support\Facades\Cache;

/**
 * Signing in to the Listen app through the phone's browser (Google refuses to
 * sign anyone in inside an app's web view): the app opens a browser tab with
 * the hash of a secret it keeps, the member signs in there by any means, and
 * the tab hands the app a one-time code that only that secret can use.
 */
class AppSignInTest extends InAppTestCase
{
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    private function challenge(string $verifier = self::VERIFIER): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** The code the hand-back page gives the app. */
    private function codeFor(User $member, ?string $challenge = null): string
    {
        $this->inBrowser(null)->get('/app/signin/start?challenge='.($challenge ?? $this->challenge()).'&locale=en')->assertRedirect();
        $page = $this->actingAs($member)->page('app.signin.done')->assertOk();
        $this->assertSame(1, preg_match('#studio\.listenai\.app://signin\?code=([A-Za-z0-9]{64})#', $page->getContent(), $m));

        return $m[1];
    }

    public function test_the_browser_tab_goes_to_google_and_comes_back_to_the_hand_back_page(): void
    {
        $this->seedPlansWithEverythingOpen();
        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);

        $response = $this->inBrowser(null)->get('/app/signin/start?challenge='.$this->challenge().'&locale=ar');

        $response->assertRedirect();
        $this->assertStringContainsString('/auth/social/redirect', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('/app/signin/done', (string) session('url.intended'));
    }

    public function test_without_google_the_tab_shows_the_sign_in_page(): void
    {
        $this->seedPlansWithEverythingOpen();
        config(['services.google.client_id' => '', 'services.google.client_secret' => '']);

        $response = $this->inBrowser(null)->get('/app/signin/start?challenge='.$this->challenge());

        $this->assertStringEndsWith('/login', (string) $response->headers->get('Location'));
    }

    public function test_a_start_without_a_proper_challenge_is_refused(): void
    {
        $this->inBrowser(null)->get('/app/signin/start')->assertStatus(400);
        $this->inBrowser(null)->get('/app/signin/start?challenge=short')->assertStatus(400);
        $this->inBrowser(null)->get('/app/signin/start?challenge='.str_repeat('*', 43))->assertStatus(400);
    }

    public function test_the_sign_in_tab_shows_no_prices_while_it_is_the_apps(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');

        $this->inBrowser(null)->get('/app/signin/start?challenge='.$this->challenge())->assertRedirect();
        $this->assertStringEndsWith('/app/plan', (string) $this->actingAs($member)->page('pricing')->headers->get('Location'));

        // A quarter of an hour later the same browser is a browser again.
        $this->travel(16)->minutes();
        // (The site itself sends a signed-in member from the plans to the billing page.)
        $this->assertStringEndsWith('/billing', (string) $this->actingAs($member)->page('pricing')->headers->get('Location'));
        $this->actingAs($member)->page('billing.page')->assertOk();
    }

    public function test_the_app_signs_the_member_in_with_the_code_and_its_secret(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $code = $this->codeFor($member);

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $response = $this->inApp(null)->get('/app/signin/complete?code='.$code.'&verifier='.self::VERIFIER);

        $response->assertRedirect();
        $this->assertStringEndsWith('/dashboard', (string) $response->headers->get('Location'));
        $this->assertAuthenticatedAs($member);
        // A sign-in that worked says nothing about failing (it once did, on the studio's first page).
        $response->assertSessionHasNoErrors();
        $this->inApp($member)->page('dashboard')->assertOk()->assertDontSee(__('app.signin_failed'));
        // Signed in to stay: the app is not asked again after a restart.
        $this->assertNotNull($member->fresh()->remember_token);
    }

    public function test_a_repeated_request_signs_in_the_same_app_and_shows_no_error(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $code = $this->codeFor($member);
        $url = '/app/signin/complete?code='.$code.'&verifier='.self::VERIFIER;

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->inApp(null)->get($url)->assertRedirect();
        $this->assertAuthenticatedAs($member);

        // Some phones ask for the address a second time. With the session of the first: the studio, no error.
        $again = $this->inApp($member)->get($url);
        $this->assertStringEndsWith('/dashboard', (string) $again->headers->get('Location'));
        $again->assertSessionHasNoErrors();

        // Without it (the two asks crossed): the same member is signed in, no error.
        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $crossed = $this->inApp(null)->get($url);
        $this->assertStringEndsWith('/dashboard', (string) $crossed->headers->get('Location'));
        $crossed->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($member);
    }

    public function test_a_used_code_stops_working_after_a_minute(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $code = $this->codeFor($member);
        $url = '/app/signin/complete?code='.$code.'&verifier='.self::VERIFIER;

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->inApp(null)->get($url)->assertRedirect();
        $this->assertAuthenticated();

        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->travel(61)->seconds();
        $late = $this->inApp(null)->get($url);

        $this->assertStringEndsWith('/login', (string) $late->headers->get('Location'));
        $late->assertSessionHasErrors('auth');
        $this->assertGuest();
    }

    public function test_a_member_already_signed_in_never_sees_a_sign_in_error(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');

        foreach (['/app/signin/complete?code='.str_repeat('a', 64).'&verifier='.self::VERIFIER, '/app/signin/complete?code=bad&verifier=bad'] as $url) {
            $response = $this->inApp($member)->get($url);
            $this->assertStringEndsWith('/dashboard', (string) $response->headers->get('Location'));
            $response->assertSessionHasNoErrors();
        }
    }

    public function test_a_code_is_of_no_use_without_the_apps_secret(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $code = $this->codeFor($member);

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $response = $this->inApp(null)->get('/app/signin/complete?code='.$code.'&verifier='.str_repeat('a', 43));

        $this->assertStringEndsWith('/login', (string) $response->headers->get('Location'));
        $this->assertGuest();
        // And the try used the code up.
        $this->assertNull(Cache::get('app-signin:'.hash('sha256', $code)));
    }

    public function test_a_code_runs_out_after_a_few_minutes(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $code = $this->codeFor($member);

        $this->travel(4)->minutes();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->inApp(null)->get('/app/signin/complete?code='.$code.'&verifier='.self::VERIFIER);

        $this->assertGuest();
    }

    public function test_only_the_app_can_finish_a_sign_in(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $code = $this->codeFor($member);

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->inBrowser(null)->get('/app/signin/complete?code='.$code.'&verifier='.self::VERIFIER)->assertStatus(403);
        $this->assertGuest();
    }

    public function test_a_blocked_member_is_not_signed_in(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $code = $this->codeFor($member);
        $member->forceFill(['is_blocked' => true, 'blocked_at' => now(), 'blocked_reason' => 'test'])->save();
        $this->assertTrue(app(BlockedUserService::class)->isBlocked($member->fresh()));

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $response = $this->inApp(null)->get('/app/signin/complete?code='.$code.'&verifier='.self::VERIFIER);

        $this->assertStringEndsWith('/login', (string) $response->headers->get('Location'));
        $this->assertGuest();
    }

    public function test_the_hand_back_page_without_a_sign_in_under_way_is_just_the_studio(): void
    {
        $this->seedPlansWithEverythingOpen();

        $this->inBrowser($this->memberOn('starter'))->page('app.signin.done')->assertRedirect(route('dashboard'));
    }

    public function test_in_the_app_the_google_button_hands_over_to_the_browser(): void
    {
        $this->seedPlansWithEverythingOpen();
        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);

        foreach (['login', 'register'] as $route) {
            // The same button as on the website, with the page's own words ("Sign up with Google" when signing up).
            $this->inApp(null)->page($route)->assertOk()
                ->assertSee(__($route === 'register' ? 'ui.auth.signup_google' : 'ui.auth.continue_google'))
                ->assertSee('data-app-signin', false)
                ->assertDontSee('/auth/social/redirect', false)
                ->assertDontSee('firebaseGoogleSignInBtn', false);
            $this->inBrowser(null)->page($route)->assertOk()
                ->assertDontSee('data-app-signin', false)
                ->assertSee('/auth/social/redirect', false);
        }
    }
}
