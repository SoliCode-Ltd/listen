<?php

namespace Tests\Feature\InApp;

use App\Models\Agent;
use App\Models\AppDevice;
use App\Support\InApp\InApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * The site as the Listen iPhone app shows it. Every test of the Android app
 * (InAppModeTest) runs again here with the iPhone app's name, and Apple's
 * stricter rules are checked on top (mobile/ios/APPLE-RULES.md):
 *
 * - plans are hidden whatever ANDROID_APP_PLANS says, and nothing leads to the
 *   billing page, not even "Upgrade" through the browser;
 * - no "Continue with Google" until the app can offer Apple's sign-in beside it;
 * - no page names another phone platform or its store.
 */
class InAppIphoneTest extends InAppModeTest
{
    /** Another platform or its store, as a member would read it (English and Arabic). */
    private const OTHER_PLATFORMS = '/android|google\s?play|play\s?store|أندرويد|اندرويد|جوجل بلاي|غوغل بلاي/iu';

    protected function appUserAgent(): string
    {
        return self::IPHONE_APP_UA;
    }

    public function test_the_iphone_app_is_told_from_the_android_app_and_from_a_browser(): void
    {
        $platform = fn (string $userAgent, array $cookies = []): ?string => InApp::platform(
            Request::create('/', 'GET', cookies: $cookies, server: ['HTTP_USER_AGENT' => $userAgent])
        );

        $this->assertSame(InApp::PLATFORM_IOS, $platform(self::IPHONE_APP_UA));
        $this->assertSame(InApp::PLATFORM_ANDROID, $platform(self::APP_UA));
        // An app from before the platform was part of the name is the Android one.
        $this->assertSame(InApp::PLATFORM_ANDROID, $platform('ListenApp/1.0.1'));
        $this->assertNull($platform(self::IPHONE_BROWSER_UA));
        $this->assertNull($platform(self::BROWSER_UA));

        // A request without the app's name is known by the app's cookie.
        $this->assertSame(InApp::PLATFORM_IOS, $platform(self::IPHONE_BROWSER_UA, [InApp::COOKIE => InApp::COOKIE_IOS]));
        $this->assertSame(InApp::PLATFORM_ANDROID, $platform(self::BROWSER_UA, [InApp::COOKIE => InApp::COOKIE_ANDROID]));
        $this->assertNull($platform(self::IPHONE_BROWSER_UA, [InApp::COOKIE => 'something-else']));

        $this->assertTrue(InApp::fromApp(Request::create('/', 'GET', cookies: [InApp::COOKIE => InApp::COOKIE_IOS], server: ['HTTP_USER_AGENT' => self::IPHONE_BROWSER_UA])));
    }

    public function test_plans_stay_hidden_on_iphone_even_when_android_shows_them(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        config(['services.android_app.plans' => InApp::PLANS_ON_WEB]);

        // The Android app follows the setting; the iPhone app does not have one.
        $android = Request::create('/', 'GET', server: ['HTTP_USER_AGENT' => self::APP_UA]);
        $iphone = Request::create('/', 'GET', server: ['HTTP_USER_AGENT' => self::IPHONE_APP_UA]);
        $this->assertSame(InApp::PLANS_ON_WEB, InApp::plansMode($android));
        $this->assertSame(InApp::PLANS_HIDDEN, InApp::plansMode($iphone));
        $this->assertTrue(InApp::hidesPlans($iphone));
        $this->assertFalse(InApp::buysOnWeb($iphone));

        // Asked for by their own addresses (inside the app the site's links to them already lead to the account page).
        foreach (['pricing', 'billing.page', 'billing.pack', 'manage-subscription.page', 'pack.start', 'trial.start'] as $route) {
            $response = $this->inApp($member)->get('/'.ltrim(Route::getRoutes()->getByName($route)->uri(), '/'));
            $response->assertRedirect();
            $this->assertStringEndsWith('/app/plan', (string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH), $route);
        }
        // A request that would pay is refused.
        $this->inApp($member)->post('/billing/subscribe', ['plan' => 'creator'])->assertStatus(403)->assertJson(['ok' => false, 'message' => __('app.not_sold_here')]);
        // Nothing hands over to the browser to pay.
        $account = (string) $this->inApp($member)->page('app.plan')->assertOk()->getContent();
        $this->assertStringNotContainsString('/app/continue', $account);
        $this->assertStringNotContainsString('data-app-open-web', $account);
    }

    /** On Android this button opens billing in the browser; on iPhone it does not exist. */
    public function test_upgrade_on_the_account_page_opens_billing_in_the_browser_signed_in(): void
    {
        $this->seedPlansWithEverythingOpen();

        foreach (['free', 'starter', 'studio_max'] as $plan) {
            $member = $this->memberOn($plan);
            foreach (['en', 'ar'] as $locale) {
                $account = (string) $this->inApp($member, $locale)->page('app.plan')->assertOk()->getContent();
                $this->assertStringNotContainsString('data-app-upgrade', $account, "$plan ($locale)");
                $this->assertStringNotContainsString(route('app.upgrade'), $account, "$plan ($locale)");
                $this->assertStringNotContainsString(__('app.plan.upgrade', [], $locale), $this->visibleText($account), "$plan ($locale)");
            }
            // The address itself, typed or kept from elsewhere: the account page, never billing or the browser.
            $asked = $this->inApp($member)->page('app.upgrade');
            $asked->assertRedirect();
            $this->assertStringEndsWith('/app/plan', (string) $asked->headers->get('Location'));
        }

        // The Android app keeps its button.
        $member = $this->memberOn('starter');
        $android = (string) $this->as($member, 'en', self::APP_UA)->page('app.plan')->assertOk()->getContent();
        $this->assertStringContainsString('data-app-upgrade', $android);
    }

    public function test_the_app_is_known_by_its_own_cookie_when_a_request_comes_without_its_name(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $cookie = InApp::COOKIE;

        // The iPhone app's first page leaves the iPhone mark; a browser never gets one.
        $this->inApp($member)->page('dashboard')->assertPlainCookie($cookie, InApp::COOKIE_IOS);
        $this->as($member, 'en', self::IPHONE_BROWSER_UA)->page('samples')->assertCookieMissing($cookie);

        // A request of the app's without its name is still the iPhone app's: no Upgrade button either.
        $unnamed = fn () => $this->actingAs($member)->withHeader('User-Agent', self::IPHONE_BROWSER_UA)->withUnencryptedCookie($cookie, InApp::COOKIE_IOS);
        $account = (string) $unnamed()->get($this->localizedRoute('app.plan'))->assertOk()->assertSee('data-app-tab="account"', false)->getContent();
        $this->assertStringNotContainsString('data-app-upgrade', $account);
        $this->assertStringEndsWith('/app/plan', (string) $unnamed()->get('/billing')->assertRedirect()->headers->get('Location'));
    }

    public function test_the_iphone_app_offers_apples_sign_in_beside_googles_or_neither(): void
    {
        $this->seedPlansWithEverythingOpen();
        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);

        // Apple's guideline 4.8: where another company's sign-in is offered, Apple's is offered beside it.
        foreach (['login' => 'continue', 'register' => 'sign-up'] as $route => $kind) {
            foreach (['en', 'ar'] as $locale) {
                $page = $this->inApp(null, $locale)->page($route)->assertOk();
                $page->assertSee('data-app-signin', false)
                    ->assertSee('data-app-signin-apple', false)
                    // Apple's own button picture, in the page's language, for a light and a dark page.
                    ->assertSee('images/partners/apple-'.$kind.'-'.$locale.'-black.png', false)
                    ->assertSee('images/partners/apple-'.$kind.'-'.$locale.'-white.png', false)
                    ->assertSee('name="email"', false);
                $this->assertFileExists(public_path('images/partners/apple-'.$kind.'-'.$locale.'-black.png'));
                $this->assertFileExists(public_path('images/partners/apple-'.$kind.'-'.$locale.'-white.png'));
            }
        }

        // Switched off, the iPhone app has neither button: email only.
        config(['services.ios_app.social_sign_in' => false]);
        foreach (['login', 'register'] as $route) {
            $page = $this->inApp(null)->page($route)->assertOk();
            $page->assertDontSee('data-app-signin', false)
                ->assertDontSee('firebaseGoogleSignInBtn', false)
                ->assertDontSee(route('auth.social.redirect'), false)
                ->assertSee('name="email"', false)
                ->assertSee('name="password"', false);
            $this->assertStringNotContainsStringIgnoringCase('google', $this->visibleText($page->getContent()), $route);
        }

        // Apple's button is the iPhone app's alone: never in the Android app, a browser, or the sign-in sheet.
        config(['services.ios_app.social_sign_in' => true]);
        foreach ([self::APP_UA, self::BROWSER_UA, self::IPHONE_BROWSER_UA] as $userAgent) {
            $this->as(null, 'en', $userAgent)->page('login')->assertOk()->assertDontSee('data-app-signin-apple', false)->assertDontSee('apple-continue', false);
        }
        $this->as(null, 'en', self::APP_UA)->page('login')->assertOk()->assertSee('data-app-signin', false);
    }

    public function test_the_sign_in_sheet_the_iphone_app_opens_follows_the_iphone_rules(): void
    {
        $this->seedPlansWithEverythingOpen();
        config(['services.android_app.plans' => InApp::PLANS_ON_WEB]);
        config(['services.google.client_id' => '', 'services.google.client_secret' => '']);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 43), true)), '+/', '-_'), '=');

        // The sheet is the phone's browser: it carries no app name, only what the app said when it opened it.
        $this->as(null, 'en', self::IPHONE_BROWSER_UA)->get('/app/signin/start?challenge='.$challenge.'&locale=en&platform=ios')
            ->assertRedirect()
            ->assertSessionHas(InApp::SIGN_IN_PLATFORM, InApp::PLATFORM_IOS);

        $sheet = Request::create('/', 'GET', server: ['HTTP_USER_AGENT' => self::IPHONE_BROWSER_UA]);
        $sheet->setLaravelSession($this->app['session.store']);
        $this->app['session.store']->put([InApp::SIGN_IN_SESSION => now()->getTimestamp(), InApp::SIGN_IN_PLATFORM => InApp::PLATFORM_IOS]);
        $this->assertSame(InApp::PLATFORM_IOS, InApp::platform($sheet));
        $this->assertSame(InApp::PLANS_HIDDEN, InApp::plansMode($sheet));

        // The Android app's tab says nothing, and is the Android one.
        $this->app['session.store']->put([InApp::SIGN_IN_PLATFORM => InApp::PLATFORM_ANDROID]);
        $this->assertSame(InApp::PLATFORM_ANDROID, InApp::platform($sheet));
    }

    public function test_an_iphone_is_registered_for_notifications_as_an_iphone(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        // The token Apple gives: 64 hexadecimal characters.
        $token = str_repeat('0a1b2c3d', 8);

        $this->inApp($member)->postJson('/app/devices', ['token' => $token, 'locale' => 'ar'])->assertOk();

        $device = AppDevice::query()->where('user_id', $member->id)->firstOrFail();
        $this->assertSame(InApp::PLATFORM_IOS, $device->platform);
        $this->assertSame('1.0.0', $device->app_version);

        // The Android app's phones are still Android phones.
        $other = $this->memberOn('starter');
        $this->as($other, 'en', self::APP_UA)->postJson('/app/devices', ['token' => str_repeat('fcm-token:', 8), 'locale' => 'en'])->assertOk();
        $this->assertSame(InApp::PLATFORM_ANDROID, AppDevice::query()->where('user_id', $other->id)->value('platform'));
    }

    public function test_the_page_script_is_told_it_is_on_an_iphone(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');

        $iphone = (string) $this->inApp($member)->page('app.plan')->assertOk()->getContent();
        $this->assertStringContainsString('"platform":"ios"', $iphone);
        // The member picks where a file goes, so the line names no folder.
        $this->assertStringContainsString('"saved":'.json_encode(__('app.shell.saved_plain')), $iphone);

        $android = (string) $this->as($member, 'en', self::APP_UA)->page('app.plan')->assertOk()->getContent();
        $this->assertStringContainsString('"platform":"android"', $android);
        $this->assertStringContainsString('"saved":'.json_encode(__('app.shell.saved')), $android);
    }

    public function test_a_shortcut_on_the_apps_icon_opens_its_place_in_the_members_language(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');

        foreach (['voices' => 'voice-samples', 'agents' => 'agents'] as $place => $path) {
            foreach (['ar', 'en'] as $locale) {
                $to = (string) $this->inApp($member, $locale)->get('/app/start?to='.$place.'&locale='.$locale)->assertRedirect()->headers->get('Location');
                $this->assertStringEndsWith('/'.$locale.'/'.$path, $to, "$place ($locale)");
            }
        }
        // A place the app does not know, and a guest: the usual first page, never an address from outside.
        $this->assertStringContainsString('/dashboard', (string) $this->inApp($member)->get('/app/start?to=https://example.com')->assertRedirect()->headers->get('Location'));
        $this->assertStringContainsString('/login', (string) $this->inApp(null)->get('/app/start?to=voices')->assertRedirect()->headers->get('Location'));
    }

    /** Apple's guideline 2.3.10: no name of another phone platform or its store in the app. */
    public function test_no_page_in_the_iphone_app_names_another_platform_or_its_store(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('studio_max');
        $agent = Agent::create([
            'user_id' => $member->id, 'name' => 'Fatima', 'business_name' => 'Riyadh Dental',
            'business_type' => 'healthcare', 'jobs' => ['answer'], 'language' => 'ar', 'dialect' => 'ar-SA', 'live_voice' => 'Achernar',
            'first_message' => 'أهلاً', 'script' => ['identity' => 'A calm receptionist.', 'never' => [], 'steps' => ['Ask what they need.'], 'handoff' => ''],
            'knowledge' => 'Open 9am to 9pm', 'choices' => [],
        ]);
        $agentKey = ['agent' => $agent->public_id ?? $agent->getRouteKey()];

        $memberPages = [
            ['dashboard', []], ['dub.page', []], ['voice-agents.index', []], ['voice-agents.create', []],
            ['voice-agents.show', $agentKey], ['voice-agents.edit', $agentKey],
            ['samples', []], ['contact', []], ['app.plan', []], ['legal.privacy', []], ['legal.terms', []],
        ];
        $guestPages = [['login', []], ['register', []], ['samples', []], ['contact', []], ['legal.privacy', []], ['legal.terms', []]];

        $seen = 0;
        foreach ([[$member, $memberPages], [null, $guestPages]] as [$who, $pages]) {
            foreach ($pages as [$route, $parameters]) {
                foreach (['en', 'ar'] as $locale) {
                    $response = $this->inApp($who, $locale)->page($route, $parameters);
                    if ($response->getStatusCode() !== 200) {
                        continue;
                    }
                    $seen++;
                    $html = (string) $response->getContent();
                    $this->assertDoesNotMatchRegularExpression(self::OTHER_PLATFORMS, $this->visibleText($html), "$route ($locale)");
                    // Not in a picture's words or a link either (the website's footer badge, say).
                    $this->assertStringNotContainsString('play.google.com', $html, "$route ($locale)");
                    $this->assertStringNotContainsString('google-play-badge', $html, "$route ($locale)");
                }
            }
        }
        $this->assertGreaterThanOrEqual(28, $seen);
    }
}
