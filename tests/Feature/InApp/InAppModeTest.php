<?php

namespace Tests\Feature\InApp;

use App\Models\Agent;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Support\InApp\InApp;
use App\Support\PlanFeatures;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Inside the Listen Android app the site is sign-in-and-use only: Google Play
 * does not allow an app to sell plans with anything but its own billing, or to
 * send people somewhere else to pay. So no page a member can reach there shows
 * a price, a plan to buy, an upgrade, a pack, a payment method or a checkout,
 * or links to one. A browser keeps the site as it always was.
 */
class InAppModeTest extends InAppTestCase
{
    /** Where something is sold, in any language. */
    private const PURCHASE_PATHS = '#/(pricing|billing|manage-subscription|premium-pack|free-trial)\b#';

    /** A price, a way to pay, or a call to buy, as a member would read it (English and Arabic). */
    private const SELLING_WORDS = '/[$€£]\s?\d|\d\s?(EGP|USD|SAR|AED)\b|\b(EGP|USD|SAR|AED)\s?\d|\d\s?(ج\.م|جنيه|ريال|درهم|دولار)'
        .'|\bupgrad|\bsubscrib|\bcheckout\b|\bbuy\b|\bpurchase|\bpricing\b|\bper month\b|\/mo\b|choose a plan|see plans|compare plans|higher plan|bigger plan'
        .'|InstaPay|Vodafone|Visa|Mastercard'
        .'|ترقية|رقِّ|اشترِ|اشترك|الأسعار والخطط|اختر (خطة|باقة)|قارن (كل )?(الخطط|الباقات)|إنستا|فودافون|ادفع/iu';

    public static function appUserAgents(): array
    {
        return [
            'the app on a phone' => [self::APP_UA, true],
            'the app, token only' => ['ListenApp/2.3.1', true],
            'the app, token in brackets' => ['Mozilla/5.0 (ListenApp/1.0; Android)', true],
            'a phone browser' => [self::BROWSER_UA, false],
            'a desktop browser' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', false],
            'another app with a similar name' => ['Mozilla/5.0 MyListenApp/1.0', false],
            'the word without a version' => ['Mozilla/5.0 ListenApp', false],
            'no user agent' => ['', false],
        ];
    }

    #[DataProvider('appUserAgents')]
    public function test_only_the_apps_own_user_agent_turns_in_app_mode_on(string $userAgent, bool $expected): void
    {
        $request = Request::create('/', 'GET', server: ['HTTP_USER_AGENT' => $userAgent]);

        $this->assertSame($expected, InApp::active($request));
    }

    public function test_a_browser_keeps_every_purchase_page_and_link(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');

        $this->inBrowser(null)->page('pricing')->assertOk();
        $this->inBrowser($member)->page('billing.page')->assertOk();
        $this->inBrowser($member)->page('manage-subscription.page')->assertOk();

        $dashboard = $this->inBrowser($member)->page('dashboard')->assertOk();
        $dashboard->assertSee($this->localizedRoute('pricing'), false)
            ->assertSee($this->localizedRoute('billing.page'), false)
            ->assertSee($this->localizedRoute('manage-subscription.page'), false)
            ->assertSee(__('ui.dashboard.upgrade_plan'))
            ->assertDontSee('listen-app.js', false)
            ->assertDontSee('listen-in-app', false);

        // And the site's own lines are untouched outside the app.
        $this->assertStringContainsString('upgrade', strtolower(__('ui.backend.monthly_chars_exceeded', [], 'en')));
        $this->assertSame($this->localizedRoute('pricing'), parse_url(route('pricing'), PHP_URL_PATH));
    }

    public function test_every_purchase_page_shows_the_neutral_plan_page_instead(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $plan = $this->localizedRoute('app.plan');

        foreach (['pricing', 'billing.page', 'billing.pack', 'billing.manual.instructions', 'manage-subscription.page', 'pack.start', 'trial.start'] as $route) {
            $response = $this->inApp($member)->get('/'.ltrim(Route::getRoutes()->getByName($route)->uri(), '/'));
            $response->assertRedirect();
            $this->assertSame($plan, parse_url((string) $response->headers->get('Location'), PHP_URL_PATH), $route);
        }
        $this->inApp($member)->get('/billing/invoices/in_123/view')->assertRedirect();
        $this->inApp($member)->get('/billing/invoices/in_123/download')->assertRedirect();

        foreach (['en', 'ar'] as $locale) {
            $page = $this->inApp($member, $locale)->page('app.plan')->assertOk();
            // The member's own plan and credits, and no word about buying: not even that it cannot be done here.
            $page->assertSee(__('app.plan.plan_heading', [], $locale))
                ->assertSee(__('app.plan.credits_left', [], $locale))
                ->assertDontSee(__('app.not_sold_here', [], $locale))
                ->assertSee(__('app.plan.delete_account', [], $locale))
                ->assertSee(__('app.plan.sign_out', [], $locale));
            $this->assertNoPurchasePath($page->getContent(), "the plan page ($locale)");
        }
    }

    public function test_a_purchase_request_from_the_app_is_refused(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');

        foreach ([
            '/billing/subscribe', '/billing/plan-change/preview', '/billing/trial/setup', '/billing/trial/confirm',
            '/billing/payment-method/intent', '/billing/payment-method', '/billing/pack/card', '/billing/pack/card/confirm',
            '/billing/manual/confirm', '/billing/cancel', '/billing/resume', '/billing/plan-change/keep',
        ] as $path) {
            $this->inApp($member)->post($path, ['plan' => 'creator'])
                ->assertStatus(403)
                ->assertJson(['ok' => false, 'message' => __('app.not_sold_here')]);
        }

        // Reading the plan again from the payment service sells nothing: it stays.
        $this->assertNotSame(403, $this->inApp($member)->post('/billing/sync')->getStatusCode());
    }

    public function test_marketing_pages_lead_to_the_studio_or_to_sign_in(): void
    {
        $this->seedPlansWithEverythingOpen();
        $paid = $this->memberOn('starter');
        $free = $this->memberOn('free');

        foreach (['home', 'seo.ai-text-to-speech', 'seo.arabic-dialects', 'agents-landing', 'browsers-extension.page', 'developers'] as $route) {
            $this->assertStringStartsWith(url('/app/start'), (string) $this->inApp($paid)->page($route)->assertRedirect()->headers->get('Location'));
        }

        $this->inApp($paid)->get('/app/start')->assertRedirect();
        $this->assertStringEndsWith('/dashboard', (string) $this->inApp($paid)->get('/app/start')->headers->get('Location'));
        $this->assertStringEndsWith('/demo', (string) $this->inApp($free)->get('/app/start')->headers->get('Location'));
        $this->assertStringEndsWith('/login', (string) $this->inApp(null)->get('/app/start')->headers->get('Location'));
        // The app opens in English whatever the phone is set to, until the member chooses.
        $this->assertStringContainsString('/en/', (string) $this->inApp(null)->withHeader('Accept-Language', 'ar-EG,ar;q=0.9,en;q=0.5')->get('/app/start')->headers->get('Location'));
        // Their choice is remembered past the session.
        $this->assertStringContainsString('/ar/', (string) $this->inApp(null)->withUnencryptedCookie(InApp::LANGUAGE_COOKIE, 'ar')->get('/app/start')->headers->get('Location'));
        $this->withUnencryptedCookie(InApp::LANGUAGE_COOKIE, '');

        // The language of the page is kept: from an Arabic page the studio opens in Arabic,
        // whatever language this phone used before.
        foreach (['ar', 'en'] as $locale) {
            $other = $locale === 'ar' ? 'en' : 'ar';
            $this->app->instance('request', Request::create('/'.$locale.'/voice-samples'));
            $this->assertSame(url('/app/start').'?locale='.$locale, InApp::homeUrl());
            $this->assertStringContainsString('/'.$locale.'/dashboard', (string) $this->inApp($paid)->withSession(['locale' => $other])->get('/app/start?locale='.$locale)->headers->get('Location'));
        }
    }

    public function test_no_page_a_member_reaches_in_the_app_sells_anything(): void
    {
        $this->seedPlansWithEverythingOpen();
        $members = [
            'a free member' => $this->memberOn('free'),
            'a member on the first paid plan' => $this->memberOn('starter'),
            'a member on the top plan' => $this->memberOn('studio_max'),
        ];
        $agent = Agent::create([
            'user_id' => $members['a member on the top plan']->id, 'name' => 'Fatima', 'business_name' => 'Riyadh Dental',
            'business_type' => 'healthcare', 'jobs' => ['answer'], 'language' => 'ar', 'dialect' => 'ar-SA', 'live_voice' => 'Achernar',
            'first_message' => 'أهلاً', 'script' => ['identity' => 'A calm receptionist.', 'never' => [], 'steps' => ['Ask what they need.'], 'handoff' => ''],
            'knowledge' => 'Open 9am to 9pm', 'choices' => [],
        ]);

        $pages = [
            ['dashboard', []], ['demo.page', []], ['dub.page', []], ['voice-agents.index', []], ['voice-agents.create', []], ['onboarding.start', []],
            ['voice-agents.show', ['agent' => $agent->public_id ?? $agent->getRouteKey()]],
            ['voice-agents.edit', ['agent' => $agent->public_id ?? $agent->getRouteKey()]],
            ['samples', []], ['contact', []], ['app.plan', []], ['legal.privacy', []],
        ];

        $seen = 0;
        foreach ($members as $who => $member) {
            foreach ($pages as [$route, $parameters]) {
                foreach (['en', 'ar'] as $locale) {
                    $response = $this->inApp($member, $locale)->page($route, $parameters);
                    if ($response->getStatusCode() !== 200) {
                        // Not this member's page (the demo for a paying member, someone else's agent): never a purchase page.
                        $this->assertContains($response->getStatusCode(), [302, 403, 404], "$route for $who");
                        $this->assertDoesNotMatchRegularExpression(self::PURCHASE_PATHS, (string) $response->headers->get('Location'), "$route for $who");

                        continue;
                    }
                    $seen++;
                    $where = "$route for $who ($locale)";
                    $this->assertNoPurchasePath($response->getContent(), $where);
                    if ($route !== 'legal.privacy') {
                        $this->assertNothingSold($response->getContent(), $where);
                    }
                    // Never kept by a shared cache for a browser; kept by the app itself, so going back is instant.
                    $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'), $where);
                    $this->assertStringContainsString('User-Agent', implode(',', $response->headers->all('vary')), $where);
                    $response->assertSee('listen-app.js', false);
                }
            }
        }
        // The check is of real pages: the studio, the demo, dubbing, agents and the rest all rendered.
        $this->assertGreaterThanOrEqual(50, $seen);
    }

    public function test_signed_out_pages_in_the_app_sell_nothing(): void
    {
        $this->seedPlansWithEverythingOpen();

        foreach (['login', 'register', 'samples', 'contact', 'legal.terms', 'legal.privacy'] as $route) {
            foreach (['en', 'ar'] as $locale) {
                $response = $this->inApp(null, $locale)->page($route)->assertOk();
                $this->assertNoPurchasePath($response->getContent(), "$route ($locale)");
                if (! str_starts_with($route, 'legal.')) {
                    $this->assertNothingSold($response->getContent(), "$route ($locale)");
                }
            }
        }
    }

    public function test_a_member_without_credits_is_told_so_and_nothing_else(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('free');
        Plan::query()->where('slug', 'free')->update(['monthly_chars' => 0]);

        $page = $this->inApp($member)->page('dashboard')->assertOk();

        $page->assertDontSee('id="packOffer"', false)->assertDontSee('class="plan-offer-price"', false)
            ->assertDontSee(__('app.not_sold_here'));

        // What they are told: that the credits are used, and when they come back when that is known.
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $line = InApp::noCreditsLine($member->id);
            $this->assertContains($line, [__('app.no_credits'), __('app.no_credits_until', ['date' => InApp::renewsOn($member->id)])]);
            $this->assertDoesNotMatchRegularExpression(self::SELLING_WORDS, $line);
        }
        $this->assertNothingSold($page->getContent(), 'the studio without credits');
    }

    public function test_the_messages_a_member_gets_in_the_app_do_not_point_to_buying(): void
    {
        $this->seedPlansWithEverythingOpen();
        // Any in-app request puts the app's lines over the site's (InAppWording).
        $this->inApp($this->memberOn('starter'))->page('app.plan')->assertOk();

        foreach (['en', 'ar'] as $locale) {
            foreach (['ui.backend.monthly_chars_exceeded', 'ui.backend.monthly_chars_not_enough', 'ui.backend.no_chars_yet', 'ui.backend.pack_ended',
                'ui.voice_cloning.upgrade_required', 'ui.voice_cloning.limit_reached', 'ui.dub.not_on_plan', 'voice_design.errors.total_used', 'agents.error_plan_test'] as $key) {
                $line = __($key, ['remaining' => 10, 'needed' => 50, 'max' => 3], $locale);
                $this->assertDoesNotMatchRegularExpression(self::SELLING_WORDS, $line, "$key ($locale)");
                $this->assertNotSame($key, $line);
            }
        }

        // A studio option the plan does not have is left out, not offered.
        $this->assertTrue(PlanFeatures::lock('tags', false, false)['none'] ?? false);
    }

    public function test_the_apps_lines_replace_real_lines_in_both_languages(): void
    {
        $english = require lang_path('en/in_app.php');
        $arabic = require lang_path('ar/in_app.php');

        $this->assertSame(array_keys($english), array_keys($arabic), 'Both languages replace the same lines.');
        $this->assertGreaterThan(60, count($english));

        foreach (['en' => $english, 'ar' => $arabic] as $locale => $lines) {
            foreach ($lines as $key => $line) {
                [$group, $item] = explode('.', $key, 2);
                $original = data_get(require lang_path("$locale/$group.php"), $item);
                $this->assertIsString($original, "$key is not a line of lang/$locale/$group.php");
                $this->assertDoesNotMatchRegularExpression(self::SELLING_WORDS, $line, "$key ($locale)");
                // A line never needs a value the original was not given.
                preg_match_all('/:[a-z_]+/', $line, $used);
                foreach ($used[0] as $placeholder) {
                    $this->assertStringContainsString($placeholder, $original, "$key ($locale)");
                }
            }
        }

        // The app's own lines (lang/*/app.php) sell nothing either, except the one that says so.
        foreach (['en', 'ar'] as $locale) {
            $own = Arr::dot(require lang_path("$locale/app.php"));
            unset($own['not_sold_here'], $own['plan.upgrade']);
            // The lines of the other setting, where plans are bought in the browser.
            $own = array_filter($own, fn (string $key): bool => ! str_starts_with($key, 'web.') && $key !== 'shell.web_failed', ARRAY_FILTER_USE_KEY);
            foreach ($own as $key => $line) {
                $this->assertDoesNotMatchRegularExpression(self::SELLING_WORDS, $line, "app.$key ($locale)");
            }
        }
    }

    public function test_a_link_made_in_the_app_and_opened_in_a_browser_goes_to_the_real_page(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');

        $this->inBrowser($member)->get($this->localizedRoute('app.plan').'?plan=creator')
            ->assertRedirect(route('billing.page', ['plan' => 'creator']));
    }

    public function test_there_is_no_footer_inside_the_app_with_either_setting(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');

        foreach ([InApp::PLANS_HIDDEN, InApp::PLANS_ON_WEB] as $setting) {
            config(['services.android_app.plans' => $setting]);
            foreach (['samples', 'contact', 'legal.privacy'] as $route) {
                $this->inApp($member)->page($route)->assertOk()->assertDontSee('class="app-footer', false);
                $this->inApp(null, 'ar')->page($route)->assertOk()->assertDontSee('class="app-footer', false);
            }
        }
        // A browser keeps it.
        $this->inBrowser(null)->page('samples')->assertOk()->assertSee('<footer class="app-footer"', false);
        // The legal pages stay one tap away in the app.
        config(['services.android_app.plans' => InApp::PLANS_HIDDEN]);
        $this->inApp($member)->page('app.plan')->assertSee($this->localizedRoute('legal.privacy'), false)->assertSee($this->localizedRoute('legal.terms'), false);
    }

    public function test_the_app_gets_no_trackers_and_no_price_data_in_the_page_head(): void
    {
        $this->seedPlansWithEverythingOpen();
        SiteSetting::set('google_analytics_id', 'G-TEST12345');

        $browser = $this->inBrowser(null)->page('samples')->assertOk()->getContent();
        $app = $this->inApp(null)->page('samples')->assertOk()->getContent();

        $this->assertStringContainsString('application/ld+json', $browser);
        $this->assertStringNotContainsString('application/ld+json', $app);
        $this->assertStringNotContainsString('googletagmanager', $app);
        $this->assertStringNotContainsString('fbq(', $app);
    }

    private function assertNoPurchasePath(string $html, string $where): void
    {
        // The page's own last-resort style rule names the paths it hides.
        $html = (string) preg_replace('#<style id="listen-in-app-style">.*?</style>#s', '', $html);
        // And the list of pages the app must never load ahead of a tap names them too.
        $html = (string) preg_replace('#<script type="speculationrules">.*?</script>#s', '', $html);

        $this->assertDoesNotMatchRegularExpression(self::PURCHASE_PATHS, $html, "A purchase address is in $where.");

        // Only the menu's "Your plan" leads to the plan page: an upgrade button would too, were one left.
        preg_match_all('#<a\b[^>]*href="[^"]*/app/plan[^"]*"[^>]*>#i', $html, $links);
        foreach ($links[0] as $link) {
            $this->assertStringContainsString('data-in-app-plan', $link, "A purchase button is left in $where: $link");
        }
        $this->assertDoesNotMatchRegularExpression('#<form\b[^>]*action="[^"]*/app/plan#i', $html, "A purchase form is left in $where.");
    }

    private function assertNothingSold(string $html, string $where): void
    {
        // What a voice reads out in a sample (a story, a dialect line) is not the site selling.
        $html = (string) preg_replace('#<(textarea|audio)\b.*?</\1>#si', ' ', $html);
        $html = (string) preg_replace('#<(article|div|section)\b[^>]*class="[^"]*\b(vs-read|vs-card|long-sample|sample-text)[^"]*"[^>]*>.*?</\1>#si', ' ', $html);
        $html = (string) preg_replace('#<p\b[^>]*\blang="[a-z-]+"[^>]*>.*?</p>#si', ' ', $html);
        // The one way to a bigger plan: the account page's button, which opens billing in the browser.
        $html = (string) preg_replace('#<a\b[^>]*data-app-upgrade[^>]*>.*?</a>#si', ' ', $html);
        $text = $this->visibleText($html);
        // The example of what a member's own business may tell its agent ("Teeth whitening: 1,500 EGP").
        $text = (string) preg_replace('/(Teeth whitening|تبييض الأسنان)[^.]*\./u', ' ', $text);
        // Putting the call button on a member's own shop: "every page of your store, except checkout".
        if (str_starts_with($where, 'voice-agents.')) {
            $text = (string) preg_replace('/except checkout|عدا صفحة الدفع|ما عدا الدفع/iu', ' ', $text);
        }
        if (str_starts_with($where, 'samples')) {
            $text = (string) preg_replace('/\b(buy|plan)\b|اشترك|قارن/iu', ' ', $text);
        }

        if (preg_match(self::SELLING_WORDS, $text, $match, PREG_OFFSET_CAPTURE) === 1) {
            $this->fail("Something is sold in $where: \"".mb_strcut($text, max(0, $match[0][1] - 80), 220).'"');
        }
        $this->assertTrue(true);
    }

    public function test_a_member_in_the_app_gets_tabs_for_the_tools_and_no_menu_of_pages(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');

        foreach (['dashboard', 'samples', 'app.plan', 'contact'] as $route) {
            $html = (string) $this->inApp($member)->page($route)->assertOk()->getContent();
            foreach (['studio', 'voices', 'account'] as $tab) {
                $this->assertStringContainsString('data-app-tab="'.$tab.'"', $html, $route);
            }
            $this->assertStringNotContainsString('id="mobileNav"', $html, $route);
            $this->assertStringNotContainsString('class="desktop-nav"', $html, $route);
            $this->assertStringNotContainsString('id="userMenu"', $html, $route);
            $this->assertSame(1, preg_match_all('/class="app-tab is-on"/', $html), $route);
        }

        // The Account page holds what the menu used to: language, help and signing out.
        $account = (string) $this->inApp($member)->page('app.plan')->getContent();
        $this->assertStringContainsString('data-app-language="ar"', $account);
        $this->assertStringContainsString('data-app-language="en"', $account);
        $this->assertStringContainsString(route('logout'), $account);

        // The website keeps its own menu, and a guest in the app has no tabs to tools they cannot open.
        $this->assertStringNotContainsString('data-app-tab=', (string) $this->inBrowser($member)->page('samples')->getContent());
        $this->assertStringNotContainsString('data-app-tab=', (string) $this->inApp(null)->page('samples')->getContent());
    }

    public function test_upgrade_on_the_account_page_opens_billing_in_the_browser_signed_in(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');

        $this->assertStringContainsString(route('app.upgrade'), (string) $this->inApp($member)->page('app.plan')->getContent());

        $page = (string) $this->inApp($member)->page('app.upgrade')->assertOk()->getContent();
        $this->assertSame(1, preg_match('#/app/continue\?code=([A-Za-z0-9]{64})&(?:amp;)?to=([^"&<\s]+)#', $page, $m));
        $this->assertStringContainsString('billing', urldecode($m[2]));

        // The browser: signed in by the link and on the real billing page, even straight after
        // signing in through the app's browser tab.
        auth()->logout();
        $this->flushSession();
        $landed = $this->inBrowser(null)->withSession([InApp::SIGN_IN_SESSION => now()->getTimestamp()])
            ->get('/app/continue?code='.$m[1].'&to='.$m[2]);
        $landed->assertRedirect();
        $this->assertStringEndsWith('/billing', (string) $landed->headers->get('Location'));
        $this->assertAuthenticatedAs($member);
        $landed->assertSessionMissing(InApp::SIGN_IN_SESSION);

        // The same link opened by a browser leads to billing itself.
        $this->inBrowser($member)->page('app.upgrade')->assertRedirect(route('billing.page'));
    }

    public function test_the_app_is_known_by_its_own_cookie_when_a_request_comes_without_its_name(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $cookie = InApp::COOKIE;

        // The app's first page leaves its mark; a browser never gets one.
        $this->inApp($member)->page('dashboard')->assertPlainCookie($cookie, '1');
        $this->inBrowser($member)->page('samples')->assertCookieMissing($cookie);
        // Without the mark a browser gets the website.
        $this->inBrowser($member)->get('/app/plan')->assertRedirect();

        // A request of the app's that does not carry its name (a page fetched ahead of a tap)
        // gets the app's page all the same: no billing page, no website menu, no footer.
        $unnamed = fn () => $this->actingAs($member)->withHeader('User-Agent', self::BROWSER_UA)->withUnencryptedCookie($cookie, '1');
        $unnamed()->get($this->localizedRoute('app.plan'))->assertOk()->assertSee('data-app-tab="account"', false);
        $this->assertStringEndsWith('/app/plan', (string) $unnamed()->get('/billing')->assertRedirect()->headers->get('Location'));
        $html = (string) $unnamed()->get($this->localizedRoute('samples'))->assertOk()->getContent();
        $this->assertStringContainsString('data-app-tab="studio"', $html);
        $this->assertStringNotContainsString('class="app-footer', $html);
        $this->assertStringNotContainsString('id="mobileNav"', $html);

    }

    public function test_agents_in_the_app_have_no_plan_tab(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');

        // The plan is on the Account tab: no tab of the agents' own row leads to it.
        $planTabs = fn (string $html): int => (int) preg_match_all('#<a class="ag-tab" href="[^"]*/(app/plan|billing)"#', $html);
        $this->assertSame(0, $planTabs((string) $this->inApp($member)->page('voice-agents.index')->assertOk()->getContent()));
        $this->assertStringContainsString('agents.menu_plan', (string) file_get_contents(resource_path('views/agents/index.blade.php')));
    }
}
