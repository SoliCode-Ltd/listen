<?php

namespace Tests\Feature;

use App\Filament\Pages\SeoSettings;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\Clarity;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Visit recordings (App\Support\Clarity): which pages load the recorder, for whom, and what the policies say. */
class ClarityTest extends TestCase
{
    use RefreshDatabase;

    private const RECORDER = 'www.clarity.ms/tag/';

    protected function setUp(): void
    {
        parent::setUp();
        // A visitor from Egypt, on the live site.
        SiteSetting::set('pricing_forced_country_code', 'EG', 'billing');
        config(['listen.live_actions' => true]);
    }

    public function test_it_is_on_with_the_teams_project(): void
    {
        $this->assertSame('zguy8b887j', Clarity::projectId());
        $this->assertTrue(Clarity::enabled());
    }

    /** @return array<string, array{0: string}> */
    public static function publicPages(): array
    {
        return [
            'home' => ['home'],
            'pricing' => ['pricing'],
            'voice samples' => ['samples'],
            'sign-up' => ['register'],
            'sign-in' => ['login'],
            'contact' => ['contact'],
            'privacy policy' => ['legal.privacy'],
        ];
    }

    #[DataProvider('publicPages')]
    public function test_public_pages_load_it_for_visitors_not_signed_in(string $route): void
    {
        $this->page($route)->assertOk()
            ->assertSee(self::RECORDER, false)
            ->assertSee('"zguy8b887j"', false)
            ->assertSee('visit-check', false);
    }

    public function test_the_free_demo_loads_it_for_the_member_trying_it_with_their_name_hidden(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Mona Adel']))->getLocalizedRoute('demo.page')->assertOk()
            ->assertSee(self::RECORDER, false)
            ->assertSee('id="userMenu" data-clarity-mask="True"', false)
            ->assertSee('id="text" name="text" data-clarity-mask="True"', false);
    }

    public function test_never_for_the_team_members_on_public_pages_the_studio_or_in_the_app(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))->getLocalizedRoute('demo.page')->assertDontSee(self::RECORDER, false);

        $member = User::factory()->create(['subscription_status_checked_at' => now()]);
        $this->actingAs($member)->page('pricing')->assertOk()->assertDontSee(self::RECORDER, false);
        $this->actingAs($member)->getLocalizedRoute('billing.page')->assertOk()->assertDontSee(self::RECORDER, false);
        $this->actingAs($member)->getLocalizedRoute('dashboard')->assertDontSee(self::RECORDER, false);

        auth()->logout();
        // Inside the app (its home and pricing pages lead into the app's own screens).
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) ListenApp/1.4.0 (Android)')->page('legal.privacy')->assertOk()->assertDontSee(self::RECORDER, false);
        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone) ListenApp/1.4.0 (iOS)')->page('legal.cookies')->assertOk()->assertDontSee(self::RECORDER, false);
    }

    public function test_not_when_switched_off_nor_on_staging(): void
    {
        SiteSetting::set(Clarity::ENABLED, '0', Clarity::GROUP);
        $this->page('home')->assertOk()->assertDontSee(self::RECORDER, false);
        $this->getJson('/visit-check')->assertOk()->assertExactJson(['record' => false]);

        SiteSetting::set(Clarity::ENABLED, '1', Clarity::GROUP);
        config(['listen.live_actions' => false]);
        $this->page('home')->assertOk()->assertDontSee(self::RECORDER, false);
        $this->getJson('/visit-check')->assertOk()->assertExactJson(['record' => false]);
    }

    public function test_the_page_asks_whether_this_visitor_may_be_recorded(): void
    {
        $response = $this->getJson('/visit-check')->assertOk()->assertExactJson(['record' => true]);
        $response->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame([], $response->headers->getCookies(), 'No session or other cookie.');

        foreach (['GB', 'DE', 'FR', 'CH'] as $country) {
            SiteSetting::set('pricing_forced_country_code', $country, 'billing');
            $this->getJson('/visit-check')->assertOk()->assertExactJson(['record' => false]);
        }
    }

    public function test_the_policies_name_it_as_its_terms_ask_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $privacy = $this->getLocalizedRoute('legal.privacy')->assertOk();
            $privacy->assertSee(__('ui.legal.privacy.s1_17'))->assertSee(__('ui.legal.privacy.s5_clarity'))
                ->assertSee('https://privacy.microsoft.com/en-us/privacystatement', false)
                ->assertSee('cookie-policy#recordings', false);
            // The Arabic text keeps each name on one line with a no-break space.
            $this->assertStringContainsString('Microsoft Advertising', str_replace("\u{00A0}", ' ', __('ui.legal.privacy.s5_clarity')));

            $this->getLocalizedRoute('legal.cookies')->assertOk()
                ->assertSee('id="recordings"', false)
                ->assertSee(__('ui.legal.cookies.c7_body'))
                ->assertSee('_clck')
                ->assertSee(Clarity::OFF_KEY, false);
        }
    }

    public function test_the_team_sets_it_in_seo_settings(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(SeoSettings::class)
            ->assertSee('Visit recordings (Clarity)')
            ->fillForm([Clarity::ENABLED => false, Clarity::PROJECT => 'abc123xyz9'])
            ->call('save')
            ->assertHasNoFormErrors();

        SiteSetting::flushGroupCache(Clarity::GROUP);
        $this->assertSame('abc123xyz9', Clarity::projectId());
        $this->assertFalse(Clarity::enabled());

        Livewire::test(SeoSettings::class)
            ->fillForm([Clarity::PROJECT => '<script>'])
            ->call('save')
            ->assertHasFormErrors([Clarity::PROJECT]);
    }

    private function page(string $route): TestResponse
    {
        return $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class])
            ->get($this->localizedRoute($route));
    }
}
