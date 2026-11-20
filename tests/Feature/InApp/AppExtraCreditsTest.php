<?php

namespace Tests\Feature\InApp;

use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Billing\ExtraCredits;
use App\Support\CreditPeriod;

/**
 * Extra credits inside the apps. Nothing is paid in an app: in the Android app a member out of
 * credits gets a small popup that opens the studio's extra-credits window on the website, in the
 * phone's browser, already signed in (AppShellController::extraCredits); the iPhone app offers
 * nothing at all, as Apple asks. The website's own dialog is unchanged.
 */
class AppExtraCreditsTest extends InAppTestCase
{
    /** A price, a way to pay or a call to buy (the same words InAppModeTest keeps out of the app). */
    private const SELLING_WORDS = '/[$€£]\s?\d|\d\s?(EGP|USD|SAR|AED)\b|\b(EGP|USD|SAR|AED)\s?\d|\d\s?(ج\.م|جنيه|ريال|درهم|دولار)'
        .'|\bupgrad|\bsubscrib|\bcheckout\b|\bbuy\b|\bpurchase|\bpricing\b|\bper month\b|\/mo\b'
        .'|InstaPay|Vodafone|Visa|Mastercard|ترقية|اشترِ|اشترك|إنستا|فودافون|ادفع/iu';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlansWithEverythingOpen();
        SiteSetting::set(ExtraCredits::ENABLED_SETTING, '1', 'billing');
    }

    private function memberOutOfCredits(): User
    {
        $member = $this->memberOn('starter');
        $counter = CreditPeriod::counter((int) $member->id);
        $counter->update(['total_chars' => 10_000_000]);

        return $member;
    }

    public function test_the_android_app_offers_extra_credits_through_the_browser_and_sells_nothing_itself(): void
    {
        $member = $this->memberOutOfCredits();

        foreach (['en', 'ar'] as $locale) {
            $html = (string) $this->inApp($member, $locale)->page('dashboard')->assertOk()->getContent();

            // The popup, its buttons and the studio's own "Add credits" buttons; not the website's payment window.
            $this->assertStringContainsString('id="xcaDialog"', $html, $locale);
            $this->assertStringContainsString('data-extra-credits-open', $html, $locale);
            $this->assertStringContainsString('data-auto-open=', $html, 'it opens by itself once, as on the website');
            $this->assertStringNotContainsString('id="xcDialog"', $html, $locale);
            $this->assertStringNotContainsString('/billing/extra-credits', $html, $locale);
            $this->assertStringNotContainsString('js.stripe.com', $html, $locale);

            // What the popup says: no price, no way to pay, no call to buy.
            preg_match('#<dialog class="xca".*?</dialog>#s', $html, $popup);
            $text = $this->visibleText($popup[0] ?? '');
            $this->assertStringContainsString(__('app.extra_credits.text', [], $locale), $text);
            $this->assertStringContainsString(__('app.web.button', [], $locale), $text);
            $this->assertDoesNotMatchRegularExpression(self::SELLING_WORDS, $text, $locale);
        }
    }

    public function test_the_popups_button_opens_the_extra_credits_window_in_the_browser_signed_in(): void
    {
        $member = $this->memberOutOfCredits();

        $reply = $this->inApp($member)->getJson($this->localizedRoute('app.extra-credits'))->assertOk();
        $this->assertStringContainsString('no-store', (string) $reply->headers->get('Cache-Control'));
        $url = (string) $reply->json('url');
        $this->assertStringContainsString('/app/continue?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('/en/dashboard?extra_credits=1', $query['to']);

        // The phone's browser, not signed in yet: the link signs the member in and opens the studio
        // with the extra-credits window, where the card or a transfer pays.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->flushHeaders()->withHeader('User-Agent', self::BROWSER_UA);
        $this->get($url)->assertRedirect(url('/en/dashboard?extra_credits=1'));
        $this->assertAuthenticatedAs($member);
        $this->inBrowser($member)->page('dashboard')->assertOk()->assertSee('id="xcDialog"', false)->assertDontSee('id="xcaDialog"', false);

        // The link works once.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->flushHeaders()->withHeader('User-Agent', self::BROWSER_UA)->get($url);
        $this->assertGuest();

        // Asked without JSON (no script), the app shows its "Continue in your browser" page, which opens it.
        $page = (string) $this->inApp($member)->page('app.extra-credits')->assertOk()->getContent();
        $this->assertStringContainsString('data-app-open-web', $page);
        $this->assertStringContainsString('extra_credits%3D1', $page);
    }

    public function test_the_iphone_app_offers_nothing_about_extra_credits(): void
    {
        $member = $this->memberOutOfCredits();
        $this->flushHeaders();

        $html = (string) $this->as($member, 'en', self::IPHONE_APP_UA)->page('dashboard')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="xcaDialog"', $html);
        $this->assertStringNotContainsString('id="xcDialog"', $html);
        $this->assertStringNotContainsString('data-extra-credits-open', $html);
        $this->assertStringNotContainsString('/app/extra-credits', $html);

        $this->as($member, 'en', self::IPHONE_APP_UA)->getJson($this->localizedRoute('app.extra-credits'))->assertForbidden();
        $this->as($member, 'en', self::IPHONE_APP_UA)->page('app.extra-credits')->assertRedirect(route('app.plan'));
    }

    public function test_a_browser_keeps_the_websites_own_window(): void
    {
        $member = $this->memberOutOfCredits();

        $this->inBrowser($member)->page('dashboard')->assertOk()
            ->assertSee('id="xcDialog"', false)->assertDontSee('id="xcaDialog"', false);
        $this->inBrowser($member)->page('app.extra-credits')->assertRedirect(url('/en/dashboard?extra_credits=1'));
    }

    public function test_a_member_who_cannot_buy_extra_credits_gets_no_popup(): void
    {
        $free = $this->memberOn('free');
        $this->inApp($free)->page('dashboard')->assertOk()->assertDontSee('id="xcaDialog"', false)->assertDontSee('data-extra-credits-open', false);

        // Extra credits switched off: nothing either.
        SiteSetting::set(ExtraCredits::ENABLED_SETTING, '0', 'billing');
        $this->inApp($this->memberOutOfCredits())->page('dashboard')->assertOk()->assertDontSee('id="xcaDialog"', false);
    }
}
