<?php

namespace Tests\Feature\InApp;

use App\Models\User;
use App\Support\InApp\AiConsent;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The iPhone app asks a member once, before their first voice, whether their
 * text and voice may be sent to the AI provider, and says who that is
 * (Apple's guideline 5.1.2(i)). Until they agree, every tool that sends
 * something is closed in the iPhone app; the account page, help, the legal
 * pages and signing out stay open. The website and the Android app never ask.
 */
class IphoneAiConsentTest extends InAppTestCase
{
    /**
     * Routes that send nothing of a member's to the AI provider, by name. Together with
     * AiConsent::TOOLS this must cover every named route: a new one fails the test below
     * until someone says which it is.
     */
    private const OPEN = [
        'account.*', 'admin.*', 'agent-talk', 'agents-landing', 'agents.*', 'api.reader.*', 'app.*', 'audio.download',
        'auth.*', 'billing.*', 'browsers-extension.*', 'contact', 'contact.submit', 'default-livewire.update',
        'developers', 'developers.*', 'favicon', 'filament.*', 'home', 'indexnow.key', 'landed', 'legal.*', 'livewire.*', 'llms',
        'login', 'login.submit', 'logout', 'manage-subscription.page', 'mcp', 'mcp.*', 'media.file',
        'newsletter.unsubscribe', 'pack.start', 'passport.*', 'password.*', 'pricing', 'referral.*', 'register',
        'register.submit', 'robots', 'samples', 'seo.*', 'site.webmanifest', 'sitemap', 'storage.*', 'trial.start',
        'v1.*', 'verification.*',
    ];

    protected function appUserAgent(): string
    {
        return self::IPHONE_APP_UA;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlansWithEverythingOpen();
    }

    /** A member the iPhone app has not asked yet. */
    private function memberNotAsked(string $plan = 'starter'): User
    {
        $member = $this->memberOn($plan);
        $member->forceFill([AiConsent::COLUMN => null])->save();

        return $member;
    }

    public function test_the_tools_are_closed_in_the_iphone_app_until_the_member_agrees(): void
    {
        $member = $this->memberNotAsked('studio_max');
        $ask = $this->localizedRoute('app.consent');

        foreach (['dashboard', 'dub.page', 'voice-agents.index', 'voice-agents.create', 'transcribe.index', 'onboarding.start'] as $route) {
            $response = $this->inApp($member)->page($route);
            $response->assertRedirect();
            $this->assertSame($ask, parse_url((string) $response->headers->get('Location'), PHP_URL_PATH), $route);
        }

        // A request that would send something is refused, and says where to agree.
        foreach (['dashboard.generate', 'dashboard.queue', 'voice-cloning.store', 'voice-design.design'] as $route) {
            $this->inApp($member)->postJson($this->localizedRoute($route), ['text' => 'مرحبا'])
                ->assertStatus(403)
                ->assertJson(['ok' => false, 'message' => __('app.consent.needed')])
                ->assertJsonPath('consent_url', route('app.consent'));
        }
        $this->assertNull($member->fresh()->getAttribute(AiConsent::COLUMN));
    }

    public function test_the_account_help_and_legal_pages_stay_open_without_agreeing(): void
    {
        $member = $this->memberNotAsked();

        foreach (['app.plan', 'contact', 'legal.privacy', 'legal.terms', 'samples'] as $route) {
            $this->inApp($member)->page($route)->assertOk();
        }
        // Signing out needs no agreement either.
        $this->inApp($member)->post($this->localizedRoute('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_the_question_says_what_is_sent_to_whom_and_for_what_in_both_languages(): void
    {
        $member = $this->memberNotAsked();

        foreach (['en', 'ar'] as $locale) {
            $page = $this->inApp($member, $locale)->page('app.consent')->assertOk();
            $page->assertSee(__('app.consent.title', [], $locale))
                ->assertSee(__('app.consent.who', [], $locale))
                ->assertSee(__('app.consent.why', [], $locale))
                ->assertSee(__('app.consent.agree', [], $locale))
                ->assertSee(__('app.consent.not_now', [], $locale))
                ->assertSee($this->localizedRoute('legal.privacy'), false)
                ->assertDontSee(__('app.consent.withdraw', [], $locale));
            foreach ((array) __('app.consent.what', [], $locale) as $line) {
                $page->assertSee($line);
            }
            // The provider is named, as Apple asks.
            $this->assertStringContainsString('Google', $this->visibleText($page->getContent()));
            // "Not now" leads to the account page, which is one of the links the app shows.
            $page->assertSee('data-in-app-plan', false);
        }
    }

    public function test_agreeing_opens_the_tools_and_leads_on_to_the_page_the_member_wanted(): void
    {
        $member = $this->memberNotAsked('studio_max');
        $wanted = $this->localizedRoute('voice-agents.index');

        $this->inApp($member)->get($wanted)->assertRedirect();
        $agreed = $this->inApp($member)->post($this->localizedRoute('app.consent.agree'));

        $agreed->assertRedirect();
        $this->assertSame($wanted, parse_url((string) $agreed->headers->get('Location'), PHP_URL_PATH));
        $this->assertNotNull($member->fresh()->getAttribute(AiConsent::COLUMN));
        $this->inApp($member->fresh())->get($wanted)->assertOk();
        $this->inApp($member->fresh())->page('dashboard')->assertOk();

        // Agreeing with no page in mind leads to the studio, never to an address from outside.
        $other = $this->memberNotAsked();
        $home = $this->inApp($other)->withSession(['app_consent.intended' => '//evil.example/x'])->post($this->localizedRoute('app.consent.agree'));
        $this->assertStringContainsString('/dashboard', (string) $home->assertRedirect()->headers->get('Location'));
        $this->assertStringNotContainsString('evil.example', (string) $home->headers->get('Location'));
    }

    public function test_the_member_can_take_the_agreement_back(): void
    {
        $member = $this->memberOn('starter');

        // The account page has the row, and the page says when they agreed.
        $this->inApp($member)->page('app.plan')->assertOk()
            ->assertSee(__('app.consent.row'))
            ->assertSee($this->localizedRoute('app.consent'), false);
        $this->inApp($member)->page('app.consent')->assertOk()
            ->assertSee(__('app.consent.withdraw'))
            ->assertSee(now()->translatedFormat('j F Y'))
            ->assertDontSee(__('app.consent.agree'));

        $this->inApp($member)->post($this->localizedRoute('app.consent.withdraw'))->assertRedirect($this->localizedRoute('app.consent'));

        $this->assertNull($member->fresh()->getAttribute(AiConsent::COLUMN));
        $this->inApp($member->fresh())->page('dashboard')->assertRedirect();
        $this->inApp($member->fresh())->page('app.consent')->assertOk()->assertSee(__('app.consent.agree'));
    }

    public function test_the_website_and_the_android_app_never_ask(): void
    {
        $member = $this->memberNotAsked();

        foreach ([self::BROWSER_UA, self::IPHONE_BROWSER_UA, self::APP_UA] as $userAgent) {
            $this->as($member, 'en', $userAgent)->page('dashboard')->assertOk();
            // Nothing to ask there: the question's address leads to the studio.
            $asked = $this->as($member, 'en', $userAgent)->page('app.consent');
            $asked->assertRedirect();
            $this->assertStringContainsString('/dashboard', (string) $asked->headers->get('Location'));
        }
        // The Android app's account page has no such row, and no provider is named in it.
        $android = (string) $this->as($member, 'en', self::APP_UA)->page('app.plan')->assertOk()->getContent();
        $this->assertStringNotContainsString(__('app.consent.row'), $android);
        $this->assertStringNotContainsStringIgnoringCase('google', $this->visibleText($android));
        $this->assertNull($member->fresh()->getAttribute(AiConsent::COLUMN));
    }

    public function test_the_provider_is_named_on_that_one_page_only(): void
    {
        $member = $this->memberOn('studio_max');

        foreach (['dashboard', 'voice-agents.index', 'samples', 'contact', 'app.plan', 'legal.terms'] as $route) {
            foreach (['en', 'ar'] as $locale) {
                $response = $this->inApp($member, $locale)->page($route);
                if ($response->getStatusCode() !== 200) {
                    continue;
                }
                $this->assertStringNotContainsStringIgnoringCase('gemini', $this->visibleText($response->getContent()), "$route ($locale)");
            }
        }
    }

    public function test_every_route_is_either_a_tool_or_known_to_send_nothing(): void
    {
        $unsorted = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if ($name === '' || Str::is(AiConsent::TOOLS, $name) || Str::is(self::OPEN, $name)) {
                continue;
            }
            $unsorted[] = $name;
        }

        $this->assertSame([], array_values(array_unique($unsorted)),
            'New routes: add each to AiConsent::TOOLS if it sends a member\'s text, voice or video to the AI provider, or to OPEN in this test if it does not.');
        // The tools named are real routes.
        foreach (['dashboard.generate', 'dashboard.queue', 'demo.run', 'voice-cloning.store', 'voice-design.design', 'voice-agents.test-call', 'agent-api.start', 'dub.store', 'transcribe.store'] as $tool) {
            $this->assertTrue(Route::has($tool), $tool);
            $this->assertTrue(Str::is(AiConsent::TOOLS, $tool), $tool);
        }
    }

    public function test_nobody_is_asked_before_the_column_exists(): void
    {
        $member = $this->memberNotAsked();
        Schema::table('users', fn ($table) => $table->dropColumn(AiConsent::COLUMN));
        AiConsent::forgetAvailability();

        try {
            // Between a deploy and its migration the tools stay open and nothing breaks.
            $this->inApp($member->fresh())->page('dashboard')->assertOk();
            $this->inApp($member->fresh())->post($this->localizedRoute('app.consent.agree'))->assertRedirect();
        } finally {
            AiConsent::forgetAvailability();
        }
    }
}
