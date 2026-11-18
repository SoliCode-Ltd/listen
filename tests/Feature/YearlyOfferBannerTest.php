<?php

namespace Tests\Feature;

use App\Filament\Pages\BillingSettings;
use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\PlanAccessService;
use App\Support\YearlyOfferBanner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * The yearly offer bar above the header: off until the admin turns it on, showing the yearly
 * discount itself (so the bar and the prices agree), on the public pages only, gone after its
 * last day; its button opens the pricing page on yearly.
 */
class YearlyOfferBannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::query()->update(['is_active' => false]);
        foreach (['basic' => [400, 18000], 'starter' => [900, 90000]] as $slug => [$cents, $credits]) {
            $plan = Plan::query()->firstOrNew(['slug' => $slug]);
            $plan->forceFill(array_merge(Plan::factory()->raw(), [
                'slug' => $slug, 'name' => ucfirst($slug), 'kind' => Plan::KIND_SUBSCRIPTION, 'is_active' => true,
                'price_cents' => $cents, 'monthly_chars' => $credits,
            ]))->save();
        }
        $this->setting('billing_yearly_discount_percentage', '50');
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');
        $this->travelBack();

        parent::tearDown();
    }

    public function test_the_bar_is_off_until_the_admin_turns_it_on(): void
    {
        $this->assertStringNotContainsString('class="offer-bar"', $this->page('home', 'en'));
        $pricing = $this->page('pricing', 'en');
        $this->assertStringNotContainsString('class="offer-bar"', $pricing);
        $this->assertStringContainsString('data-start-yearly="0"', $pricing);
    }

    public function test_once_on_it_shows_the_yearly_discount_with_its_neighbours_and_last_day(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 20));
        $this->turnOn('2026-12-31');

        $en = $this->page('home', 'en');
        $this->assertStringContainsString('class="offer-bar"', $en);
        $this->assertStringContainsString('Yearly plans', $en);
        // The picker: 50% chosen, 40% above and 60% below; screen readers hear one sentence.
        $this->assertMatchesRegularExpression('#is-near">40%</span>\s*<span class="offer-bar-n is-on">50%</span>\s*<span class="offer-bar-n is-near">60%</span>#', $en);
        $this->assertStringContainsString('<span class="offer-bar-sr">50% off yearly plans</span>', $en);
        $this->assertStringContainsString('Ends 31 December', $en);
        $this->assertMatchesRegularExpression('#<a class="offer-bar-btn" href="[^"]*/pricing\?billing=yearly">Get the offer</a>#', $en);

        $ar = $this->page('home', 'ar');
        $this->assertStringContainsString('الاشتراك السنوي', $ar);
        $this->assertStringContainsString('<span aria-hidden="true">خصم</span>', $ar);
        $this->assertStringContainsString('ينتهي في 31 ديسمبر', $ar);
        $this->assertStringContainsString('>احصل على العرض</a>', $ar);

        // While it is on, the pricing page opens on yearly.
        $this->assertStringContainsString('data-start-yearly="1"', $this->page('pricing', 'en'));
    }

    public function test_the_bar_follows_the_yearly_discount_and_hides_without_one(): void
    {
        $this->turnOn();
        $this->setting('billing_yearly_discount_percentage', '25');
        $page = $this->page('pricing', 'en');
        $this->assertStringContainsString('<span class="offer-bar-n is-on">25%</span>', $page);
        $this->assertStringContainsString('<span class="offer-bar-n is-near">15%</span>', $page);
        $this->assertStringContainsString('<span class="offer-bar-n is-near">35%</span>', $page);
        // No end date: no "Ends" part.
        $this->assertStringNotContainsString('class="offer-bar-ends"', $page);

        $this->setting('billing_yearly_discount_percentage', '0');
        $this->assertStringNotContainsString('class="offer-bar"', $this->page('pricing', 'en'));
    }

    public function test_the_bar_hides_itself_after_its_last_day(): void
    {
        $this->turnOn('2026-12-31');

        $this->travelTo(now()->setDate(2026, 12, 31)->setTime(23, 0));
        $this->assertStringContainsString('class="offer-bar"', $this->page('home', 'en'));

        $this->travelTo(now()->setDate(2027, 1, 1)->setTime(0, 5));
        $this->assertStringNotContainsString('class="offer-bar"', $this->page('home', 'en'));
        $this->assertStringContainsString('data-start-yearly="0"', $this->page('pricing', 'en'));
    }

    public function test_the_bar_is_only_on_the_public_pages_never_on_sign_in_or_sign_up(): void
    {
        $this->turnOn();
        $this->assertTrue(YearlyOfferBanner::showsOnRoute('seo.dialect'));
        $this->assertTrue(YearlyOfferBanner::showsOnRoute('pricing'));
        foreach (['login', 'register', 'password.request', 'password.reset', 'verification.notice', 'dashboard', 'developers.keys'] as $route) {
            $this->assertFalse(YearlyOfferBanner::showsOnRoute($route), $route);
        }

        // Sign in, sign up and forgotten password, in both languages.
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            foreach (['login', 'register', 'password.request'] as $route) {
                $page = (string) $this->getLocalizedRoute($route)->assertOk()->getContent();
                $this->assertStringNotContainsString('class="offer-bar"', $page, "{$locale} {$route}");
            }
        }
    }

    public function test_the_bar_never_shows_inside_the_phone_apps_even_to_a_visitor(): void
    {
        $this->turnOn();
        $apps = [
            'Android app' => 'Mozilla/5.0 (Linux; Android 14; SM-A546E Build/UP1A; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/130.0.0.0 Mobile Safari/537.36 ListenApp/1.0.0 (Android)',
            'iPhone app' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 ListenApp/1.0.0 (iOS)',
        ];

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            foreach (['samples', 'contact'] as $route) {
                // A visitor in a browser sees it on these pages...
                $browser = (string) $this->getLocalizedRoute($route)->assertOk()->getContent();
                $this->assertStringContainsString('class="offer-bar"', $browser, "browser {$locale} {$route}");
                // ...and nobody sees it in the apps, where nothing is sold.
                foreach ($apps as $name => $userAgent) {
                    $page = (string) $this->withHeader('User-Agent', $userAgent)->getLocalizedRoute($route)->assertOk()->getContent();
                    $this->assertStringNotContainsString('class="offer-bar"', $page, "{$name} {$locale} {$route}");
                    $this->assertStringNotContainsString('offer-bar-btn"', $page, "{$name} {$locale} {$route}");
                }
                $this->flushHeaders();
            }
        }
    }

    public function test_a_member_who_already_pays_yearly_does_not_see_the_bar(): void
    {
        $this->turnOn();
        $starter = Plan::query()->where('slug', 'starter')->firstOrFail();

        $monthly = $this->handPaidMember($starter, 'monthly');
        $this->assertNotNull(YearlyOfferBanner::forPage('home', $monthly, 'en'));
        $this->actingAs($monthly);
        $this->assertStringContainsString('class="offer-bar"', $this->page('home', 'en'));

        $yearly = $this->handPaidMember($starter, 'yearly');
        $this->assertNull(YearlyOfferBanner::forPage('home', $yearly, 'en'));
        $this->actingAs($yearly);
        $this->assertStringNotContainsString('class="offer-bar"', $this->page('home', 'en'));
    }

    public function test_the_admin_wording_replaces_the_default(): void
    {
        $this->turnOn();
        $this->setting(YearlyOfferBanner::LABEL_EN, 'Every yearly plan');
        $this->setting(YearlyOfferBanner::BUTTON_AR, 'اشترك الآن');

        $this->assertStringContainsString('Every yearly plan', $this->page('home', 'en'));
        $this->assertStringContainsString('>اشترك الآن</a>', $this->page('home', 'ar'));
    }

    public function test_the_banner_link_opens_pricing_on_yearly_even_while_the_bar_is_off(): void
    {
        app()->setLocale('en');
        $page = (string) $this->withoutMiddleware([
            LocaleSessionRedirect::class,
            LaravelLocalizationRedirectFilter::class,
            LaravelLocalizationViewPath::class,
        ])->get($this->localizedRoute('pricing').'?billing=yearly')->assertOk()->getContent();
        $this->assertStringContainsString('data-start-yearly="1"', $page);
        $this->assertStringNotContainsString('class="offer-bar"', $page);
    }

    public function test_the_admin_turns_it_on_and_sets_its_last_day_in_billing_settings(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['listen.live_actions' => false]);

        Livewire::test(BillingSettings::class)
            ->assertFormSet(['offer_banner_enabled' => false])
            ->fillForm(['offer_banner_enabled' => true, 'offer_banner_ends_on' => '2026-12-31', 'offer_banner_label_ar' => 'كل الخطط السنوية'])
            ->call('save')
            ->assertHasNoFormErrors();

        Cache::flush();
        $this->assertTrue(YearlyOfferBanner::enabled());
        $this->assertSame('2026-12-31', SiteSetting::get(YearlyOfferBanner::ENDS_ON));
        $this->assertSame('كل الخطط السنوية', SiteSetting::get(YearlyOfferBanner::LABEL_AR));
    }

    private function handPaidMember(Plan $plan, string $cycle): User
    {
        $user = User::factory()->create();
        $row = app(PlanAccessService::class)->assignPlan($user, $plan, 'active', now()->addMonths(6), now()->subMonth());
        ManualPaymentRequest::query()->create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'billing_cycle' => $cycle,
            'payment_method' => 'instapay', 'expected_amount_minor' => 100000, 'currency' => 'EGP',
            'country_code' => 'EG', 'status' => ManualPaymentRequest::STATUS_APPROVED,
            'approved_subscription_id' => $row->id, 'submitted_at' => now()->subMonth(), 'reviewed_at' => now()->subMonth(),
        ]);

        return $user;
    }

    private function turnOn(?string $endsOn = null): void
    {
        $this->setting(YearlyOfferBanner::ENABLED, '1');
        $this->setting(YearlyOfferBanner::ENDS_ON, (string) $endsOn);
    }

    private function setting(string $key, string $value): void
    {
        SiteSetting::set($key, $value, 'billing');
        Cache::flush();
    }

    private function page(string $route, string $locale): string
    {
        app()->setLocale($locale);

        return (string) $this->getLocalizedRoute($route)->assertOk()->getContent();
    }
}
