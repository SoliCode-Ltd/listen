<?php

namespace Tests\Feature\InApp;

use App\Support\InApp\InApp;
use Illuminate\Testing\TestResponse;

/**
 * Plans shown in the app and bought on the web (ANDROID_APP_PLANS=web; the
 * default is hidden): a member sees prices and plans in the app as on the website, and
 * a page that takes a payment opens in the phone's browser instead, already
 * signed in, with the plan and cycle they chose. Nothing is paid in the app.
 */
class AppPlansOnWebTest extends InAppTestCase
{
    protected function plansMode(): string
    {
        return InApp::PLANS_ON_WEB;
    }

    /** The address the app hands to the phone's browser. */
    private function webUrl(TestResponse $page): string
    {
        $this->assertSame(1, preg_match('#data-app-open-web[^>]*#', $page->getContent()), 'The page offers no way to the browser.');
        $this->assertSame(1, preg_match('#<a[^>]*href="([^"]+)"[^>]*data-app-open-web#', $page->getContent(), $m));

        return html_entity_decode($m[1]);
    }

    public function test_plans_are_hidden_unless_the_setting_says_web(): void
    {
        foreach (['web' => InApp::PLANS_ON_WEB, 'hidden' => InApp::PLANS_HIDDEN, '' => InApp::PLANS_HIDDEN, 'anything' => InApp::PLANS_HIDDEN] as $value => $mode) {
            config(['services.android_app.plans' => $value]);
            $this->assertSame($mode, InApp::plansMode());
        }
        // With nothing in .env at all, the app shows nothing about plans.
        $this->assertSame('hidden', (require config_path('services.php'))['android_app']['plans']);
    }

    public function test_the_app_shows_prices_and_plans_as_the_website_does(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');

        $this->inApp(null)->page('pricing')->assertOk()->assertSee('listen-app.js', false);

        $dashboard = $this->inApp($member)->page('dashboard')->assertOk();
        $dashboard->assertSee($this->localizedRoute('billing.page'), false)
            ->assertSee($this->localizedRoute('pricing'), false)
            ->assertSee(__('ui.dashboard.upgrade_plan'));
        // The site's own lines, not the hidden setting's.
        $this->assertStringContainsString('upgrade', strtolower(__('ui.backend.monthly_chars_exceeded', [], 'en')));
        $this->inApp($member)->page('home')->assertOk();
    }

    public function test_a_page_that_pays_opens_in_the_browser_with_what_was_chosen(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');

        foreach (['en', 'ar'] as $locale) {
            $page = $this->inApp($member, $locale)->get($this->localizedRoute('billing.page').'?plan=creator&billing_cycle=yearly')->assertOk();
            $page->assertSee(__('app.web.title', [], $locale))->assertSee(__('app.web.button', [], $locale))
                ->assertDontSee('name="card', false)
                ->assertHeader('Cache-Control', 'no-store, private');

            $url = $this->webUrl($page);
            $this->assertStringStartsWith(url('/app/continue').'?code=', $url);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $this->assertSame($this->localizedRoute('billing.page').'?plan=creator&billing_cycle=yearly', $query['to']);
        }

        foreach (['billing.pack', 'billing.manual.instructions', 'manage-subscription.page', 'pack.start', 'trial.start'] as $route) {
            $this->inApp($member)->page($route)->assertOk()->assertSee('data-app-open-web', false);
        }
    }

    public function test_the_browser_is_signed_in_as_the_member_and_lands_on_billing_with_the_choice(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $url = $this->webUrl($this->inApp($member)->get($this->localizedRoute('billing.page').'?plan=creator&billing_cycle=yearly'));

        // The phone's browser, where nobody is signed in.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $response = $this->inBrowser(null)->get($url);

        $response->assertRedirect(url($this->localizedRoute('billing.page').'?plan=creator&billing_cycle=yearly'));
        $this->assertAuthenticatedAs($member);
        $this->inBrowser($member)->get($this->localizedRoute('billing.page').'?plan=creator&billing_cycle=yearly')->assertOk()
            ->assertDontSee('data-app-open-web', false);
    }

    public function test_the_link_signs_in_once_and_only_for_a_few_minutes(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $to = $this->localizedRoute('billing.page').'?plan=creator';

        $url = $this->webUrl($this->inApp($member)->get($to));
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->inBrowser(null)->get($url)->assertRedirect(url($to));
        $this->assertAuthenticatedAs($member);

        // Used again by someone else: it leads to the page, which asks them to sign in.
        auth()->logout();
        $this->flushSession();
        $this->inBrowser(null)->get($url)->assertRedirect(url($to));
        $this->assertGuest();
        $this->assertStringEndsWith('/login', (string) $this->inBrowser(null)->get($to)->headers->get('Location'));

        // And one that has waited too long.
        $late = $this->webUrl($this->inApp($member)->get($to));
        $this->travel(6)->minutes();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->inBrowser(null)->get($late)->assertRedirect(url($to));
        $this->assertGuest();
    }

    public function test_a_visitor_goes_to_the_same_page_and_signs_in_on_the_web(): void
    {
        $this->seedPlansWithEverythingOpen();
        $to = $this->localizedRoute('billing.page').'?plan=creator&billing_cycle=monthly';

        $page = $this->inApp(null)->get($to)->assertOk()->assertSee(__('app.web.text_guest'));
        $this->assertSame(url($to), $this->webUrl($page));

        // The site's own sign-in then remembers where they were going.
        $this->flushSession();
        $this->inBrowser(null)->get($to)->assertRedirect();
        $this->assertStringContainsString('plan=creator', (string) session('url.intended'));
    }

    public function test_the_link_only_ever_leads_to_a_page_of_this_site(): void
    {
        $this->seedPlansWithEverythingOpen();

        foreach (['https://evil.example/billing', '//evil.example', '/\\evil.example', 'javascript:alert(1)', '', '/app/continue?to=/x'] as $to) {
            $this->inBrowser(null)->get('/app/continue?to='.urlencode($to))->assertRedirect(url('/'));
        }
    }

    public function test_the_link_does_nothing_inside_the_app(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $url = $this->webUrl($this->inApp($member)->page('billing.page'));

        $this->inApp($member)->get($url)->assertRedirect(url('/app/start'));
    }

    public function test_a_blocked_member_is_not_signed_in_by_the_link(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $url = $this->webUrl($this->inApp($member)->page('billing.page'));
        $member->forceFill(['is_blocked' => true, 'blocked_at' => now(), 'blocked_reason' => 'test'])->save();

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->inBrowser(null)->get($url)->assertRedirect();
        $this->assertGuest();
    }

    public function test_nothing_is_paid_inside_the_app(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');

        foreach (['/billing/subscribe', '/billing/trial/setup', '/billing/pack/card', '/billing/manual/confirm', '/billing/payment-method', '/billing/cancel'] as $path) {
            $this->inApp($member)->post($path, ['plan' => 'creator'])->assertStatus(403);
        }
        $this->assertNotSame(403, $this->inApp($member)->post('/billing/sync')->getStatusCode());
    }

    public function test_a_browser_is_never_sent_anywhere(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');

        $this->inBrowser($member)->page('billing.page')->assertOk()->assertDontSee('data-app-open-web', false);
        $this->inBrowser($member)->page('manage-subscription.page')->assertOk()->assertDontSee('data-app-open-web', false);
    }
}
