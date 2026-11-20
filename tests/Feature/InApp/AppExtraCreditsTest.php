<?php

namespace Tests\Feature\InApp;

use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Billing\ExtraCredits;
use App\Services\Newsletter\CreditsEndedEmail;
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
        $this->assertSame('/en/billing?extra_credits=1', $query['to']);

        // The phone's browser, not signed in yet: the link signs the member in and opens the billing
        // page with the extra-credits window, where the card or a transfer pays. Not the studio: the
        // app opens /dashboard links itself, so the browser would hand the studio straight back.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->flushHeaders()->withHeader('User-Agent', self::BROWSER_UA);
        $this->get($url)->assertRedirect(url('/en/billing?extra_credits=1'));
        $this->assertAuthenticatedAs($member);
        $this->inBrowser($member)->page('billing.page')->assertOk()->assertSee('id="xcDialog"', false)->assertDontSee('id="xcaDialog"', false);
        $this->assertNotAppLink('/en/billing');

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

    public function test_a_tap_outside_the_popup_never_closes_it(): void
    {
        $this->inApp($this->memberOutOfCredits())->page('dashboard')->assertOk()
            ->assertSee("if (event.target.closest('[data-xca-close]')) close();", false)
            ->assertDontSee('event.target === dialog ||', false);
    }

    public function test_a_browser_keeps_the_websites_own_window(): void
    {
        $member = $this->memberOutOfCredits();

        $this->inBrowser($member)->page('dashboard')->assertOk()
            ->assertSee('id="xcDialog"', false)->assertDontSee('id="xcaDialog"', false);
        $this->inBrowser($member)->page('app.extra-credits')->assertRedirect(url('/en/billing?extra_credits=1'));
    }

    /**
     * Where the browser is sent must be a page neither app opens itself (App Links on Android,
     * Universal Links on iPhone): the studio is one, so the browser handed it back to the app and
     * the app asked again, round and round.
     */
    public function test_the_browser_is_sent_to_a_page_no_app_opens_itself(): void
    {
        // The check itself sees the studio as the apps' own (that was the loop).
        $this->assertSame('android', $this->appThatOpens('/ar/dashboard'));
        $this->assertNotAppLink('/en/billing');
        $this->assertNotAppLink('/ar/billing');
        $this->assertStringContainsString('/billing?extra_credits=1', CreditsEndedEmail::addCreditsUrl('ar'));
        $this->assertNotAppLink((string) parse_url(CreditsEndedEmail::addCreditsUrl('en'), PHP_URL_PATH));
    }

    /** A path that neither the Android app's manifest nor the iPhone app's association file claims. */
    private function assertNotAppLink(string $path): void
    {
        $this->assertNull($this->appThatOpens($path), "An app opens $path itself.");
    }

    /** Which app opens this path itself ("android" or "iphone"), or null. */
    private function appThatOpens(string $path): ?string
    {
        $manifest = (string) file_get_contents(base_path('mobile/android/app/src/main/AndroidManifest.xml'));
        preg_match_all('#android:(path|pathPrefix)="([^"]+)"#', $manifest, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);
        foreach ($m as [, $kind, $claimed]) {
            if ($kind === 'path' ? $path === $claimed : str_starts_with($path, $claimed)) {
                return 'android';
            }
        }

        config(['services.apns.team_id' => 'ABCDE12345', 'services.apns.bundle_id' => 'studio.listenai.app']);
        $components = (array) $this->get('/.well-known/apple-app-site-association')->json('applinks.details.0.components');
        $this->assertNotEmpty($components);
        foreach ($components as $component) {
            if (preg_match('#^'.str_replace('\\*', '.*', preg_quote((string) $component['/'], '#')).'$#', $path) === 1) {
                return 'iphone';
            }
        }

        return null;
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
