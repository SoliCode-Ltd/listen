<?php

namespace Tests\Feature\InApp;

use App\Models\SiteSetting;
use App\Support\FreeAllowance;
use App\Support\Transcription\TranscriptionSettings;

/**
 * A member on the free plan inside the app: the demo is their studio when the
 * studio has nothing for them to spend, the studio says so truthfully and
 * leads there, and the demo offers the same free tries as on the website
 * (speech to text included), with nothing sold.
 */
class AppFreeMemberTest extends InAppTestCase
{
    public function test_a_free_member_with_nothing_to_spend_has_the_demo_as_the_studio_tab(): void
    {
        $this->seedPlansWithEverythingOpen();
        // The free plan gives no credits each month: the demo is the free try.
        SiteSetting::set(FreeAllowance::NEW_MEMBERS_KEY, '0');
        $free = $this->memberOn('free');
        $paid = $this->memberOn('starter');

        $voices = (string) $this->inApp($free)->page('samples')->assertOk()->getContent();
        $this->assertSame(route('demo.page'), $this->studioTab($voices), 'The Studio tab opens the demo.');

        // Reached all the same (the studio's own tabs), the studio tells the truth and leads to the demo.
        $studio = $this->inApp($free)->page('dashboard')->assertOk();
        $studio->assertSee(__('app.no_credits_on_plan'))
            ->assertDontSee(__('app.no_credits_until', ['date' => '']), false)
            ->assertSee('id="inAppTryDemo" href="'.route('demo.page').'"', false);
        $this->assertDoesNotMatchRegularExpression('#href="[^"]*/(billing|pricing|checkout)#', $studio->getContent());

        // A paying member's Studio tab is the studio, and has no demo button.
        $paidVoices = (string) $this->inApp($paid)->page('samples')->getContent();
        $this->assertSame(route('dashboard'), $this->studioTab($paidVoices));
        $this->inApp($paid)->page('dashboard')->assertOk()->assertDontSee('id="inAppTryDemo"', false);
    }

    public function test_a_free_member_with_monthly_credits_keeps_the_studio(): void
    {
        $this->seedPlansWithEverythingOpen();
        SiteSetting::set(FreeAllowance::NEW_MEMBERS_KEY, '1');
        $free = $this->memberOn('free');

        $voices = (string) $this->inApp($free)->page('samples')->getContent();
        $this->assertSame(route('dashboard'), $this->studioTab($voices));
    }

    public function test_the_demo_in_the_app_offers_the_free_speech_to_text_try_with_nothing_sold(): void
    {
        $this->seedPlansWithEverythingOpen();
        SiteSetting::set('transcription_audience', TranscriptionSettings::AUDIENCE_EVERYONE, TranscriptionSettings::GROUP);
        $free = $this->memberOn('free');

        foreach (['en', 'ar'] as $locale) {
            $demo = $this->inApp($free, $locale)->page('demo.page')->assertOk();
            $html = $demo->getContent();
            $this->assertStringContainsString('data-studio-tab="stt"', $html, "Speech to text is a mode of the demo ($locale).");
            $this->assertStringContainsString('data-tr-studio', $html);
            // Its free try is told plainly: no plan to get, no link to the plans.
            $demo->assertDontSee('class="tr-rule-action"', false);
            $this->assertDoesNotMatchRegularExpression('#href="[^"]*/(billing|pricing|checkout)#', $html, $locale);
        }

        // As on the website, where it was already offered.
        $this->inBrowser($free)->page('demo.page')->assertOk()->assertSee('data-studio-tab="stt"', false);
    }

    public function test_the_demo_out_of_allowance_offers_upgrade_in_the_browser_on_android_only(): void
    {
        $this->seedPlansWithEverythingOpen();
        $free = $this->memberOn('free');

        // Android: the demo's button, as on the website, leads to the app's hand-over, never to billing in the app.
        $html = (string) $this->inApp($free)->page('demo.page')->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<a class="btn btn-primary error-upgrade" id="errorUpgrade" href="([^"]+)" data-app-upgrade data-no-prefetch hidden>#', $html, $m));
        $this->assertStringContainsString('/app/upgrade?plan=', html_entity_decode($m[1]));
        $this->assertDoesNotMatchRegularExpression('#href="[^"]*/(billing|pricing|checkout)#', $html);

        // Tapped: the billing page with that plan, opened in the phone's browser, signed in.
        $plan = (string) parse_url(html_entity_decode($m[1]), PHP_URL_QUERY);
        $handOver = (string) $this->inApp($free)->get($this->localizedRoute('app.upgrade').'?'.$plan)->assertOk()->getContent();
        $this->assertSame(1, preg_match('#/app/continue\?code=[A-Za-z0-9]{64}&(?:amp;)?to=([^"&<\s]+)#', $handOver, $to));
        $this->assertStringEndsWith('/billing?'.$plan, urldecode($to[1]));
        // A plan that is not a plan's name is left out.
        $odd = (string) $this->inApp($free)->get($this->localizedRoute('app.upgrade').'?plan=../x')->getContent();
        preg_match('#to=([^"&<\s]+)#', $odd, $oddTo);
        $this->assertStringEndsWith('/billing', urldecode($oddTo[1]));

        // iPhone: nothing may lead to a place to pay, so the message stands alone.
        $iphone = (string) $this->as($free, 'en', self::IPHONE_APP_UA)->page('demo.page')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-app-upgrade', $iphone);
        $this->assertStringNotContainsString('/app/upgrade', $iphone);
    }

    /** The address the app's Studio tab leads to on this page. */
    private function studioTab(string $html): ?string
    {
        return preg_match('#<a href="([^"]+)" class="app-tab[^"]*" data-app-tab="studio"#', $html, $m) ? html_entity_decode($m[1]) : null;
    }
}
