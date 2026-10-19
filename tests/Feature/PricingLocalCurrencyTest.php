<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Services\LocalizedPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Prices in the visitor's own currency are a guide: cards are charged each
 * plan's price in US dollars. The pricing page says so and shows the dollar
 * price on every card; without an exchange rate it quotes dollars; and a
 * visitor is never given the server's country. The exchange-rate and
 * country services are faked.
 */
class PricingLocalCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::query()->where('slug', 'basic')->delete();
        Plan::factory()->create([
            'slug' => 'basic', 'name' => 'Basic', 'price_cents' => 400, 'monthly_chars' => 20000,
            'voice_tier' => 'hd', 'processing_priority' => 'high', 'is_active' => true,
        ]);
    }

    public function test_local_prices_say_they_are_a_guide_and_show_the_dollar_price(): void
    {
        SiteSetting::set('pricing_forced_country_code', 'MX', 'billing');
        Http::fake(['https://open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['USD' => 1, 'MXN' => 18.4575]])]);

        foreach (['en', 'ar'] as $locale) {
            $this->pricingPage($locale)
                ->assertSee(__('ui.pricing.currency_note', ['currency' => 'MXN'], $locale))
                ->assertSee($this->formatted(7383, 'MXN', $locale))
                // The dollar price beside it, the one the card is charged: $4 a month, $3.20 on the yearly price.
                ->assertSee(__('ui.pricing.usd_price', ['price' => "\u{2066}".$this->formatted(400, 'USD', $locale)."\u{2069}"], $locale))
                ->assertSee('data-yearly="'.e(__('ui.pricing.usd_price', ['price' => "\u{2066}".$this->formatted(320, 'USD', $locale)."\u{2069}"], $locale)).'"', false);
        }

        $this->pricingPage('en')->assertSee('$4');
    }

    public function test_a_visitor_in_the_united_states_sees_dollars_and_no_note(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
                ->pricingPage($locale, 'https://listenai.studio', ['CF-IPCountry' => 'US'])
                ->assertSee($this->formatted(400, 'USD', $locale))
                ->assertDontSee('class="currency-note"', false)
                ->assertDontSee('class="price-usd"', false);
        }
    }

    public function test_without_an_exchange_rate_the_price_stays_in_dollars(): void
    {
        // Morocco's dirham is in neither the second service nor the table: no rate at all.
        SiteSetting::set('pricing_forced_country_code', 'MA', 'billing');
        Http::fake([
            'https://open.er-api.com/*' => Http::response(['result' => 'error'], 500),
            'https://api.frankfurter.app/*' => Http::response(['message' => 'down'], 500),
        ]);

        $this->pricingPage('en')
            ->assertDontSee('MAD')
            ->assertSee($this->formatted(400, 'USD', 'en'))
            ->assertDontSee('class="currency-note"', false);
        $this->pricingPage('ar')
            ->assertDontSee('د.م.')
            ->assertSee($this->formatted(400, 'USD', 'ar'));
    }

    public function test_egypts_prices_in_pounds_say_cards_are_charged_in_dollars(): void
    {
        SiteSetting::set('pricing_forced_country_code', 'EG', 'billing');
        SiteSetting::set('pricing_exchange_rate_EGP', '50', 'billing');
        SiteSetting::set('manual_instapay_enabled', '1', 'billing');
        SiteSetting::set('manual_instapay_number', '0123456789', 'billing');

        foreach (['en', 'ar'] as $locale) {
            $this->pricingPage($locale)
                ->assertSee('data-display-manual="1"', false)
                ->assertSee(__('ui.pricing.currency_note_egp', [], $locale))
                ->assertDontSee(__('ui.pricing.currency_note', ['currency' => 'EGP'], $locale))
                ->assertSee(__('ui.pricing.usd_price', ['price' => "\u{2066}".$this->formatted(400, 'USD', $locale)."\u{2069}"], $locale));
        }
    }

    public function test_a_failed_or_partial_answer_never_replaces_the_last_complete_rates(): void
    {
        $answers = ['open' => ['USD' => 1, 'MAD' => 10.0, 'MXN' => 18.0], 'frankfurter' => null];
        Http::fake(function (HttpRequest $request) use (&$answers) {
            $rates = str_contains($request->url(), 'open.er-api.com') ? $answers['open'] : $answers['frankfurter'];

            return $rates === null ? Http::response([], 500) : Http::response(['rates' => $rates]);
        });
        $service = app(LocalizedPricingService::class);
        $quote = fn (string $country): string => $service->convertedQuoteFromUsd(400, 'monthly', ['country_code' => $country], 'stripe', 'en')['amount_formatted'];
        $amount = fn (int $minor, string $currency): string => $service->formatAmount($minor, $currency, 'en');

        $this->assertSame($amount(4000, 'MAD'), $quote('MA'));

        // An hour on, the first service is down and the second has no dirham: the dirham
        // keeps its last rate, and the peso takes the second service's.
        $answers = ['open' => null, 'frankfurter' => ['MXN' => 20.0]];
        $this->travel(61)->minutes();
        $this->assertSame($amount(4000, 'MAD'), $quote('MA'));
        $this->assertSame($amount(8000, 'MXN'), $quote('MX'));

        // Both down: still the last rates.
        $answers = ['open' => null, 'frankfurter' => null];
        $this->travel(61)->minutes();
        $this->assertSame($amount(4000, 'MAD'), $quote('MA'));

        // From nothing, a partial answer is never kept on its own: without a rate the price is in dollars.
        Cache::flush();
        $answers = ['open' => null, 'frankfurter' => ['MXN' => 20.0]];
        $this->assertSame($amount(400, 'USD'), $quote('MA'));
        $this->assertNull(Cache::get('billing:usd_fx_rates:v3'));
    }

    public function test_a_visitor_whose_lookup_fails_is_not_given_the_servers_country(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $context = app(LocalizedPricingService::class)->resolveCountryContext(Request::create('https://listenai.studio/pricing', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_ACCEPT_LANGUAGE' => 'ar-SA,ar;q=0.9',
        ]));

        $this->assertSame(['SA', 'accept_language'], [$context['country_code'], $context['source']]);
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://ipapi.co/203.0.113.9/json/');
        Http::assertNotSent(fn (HttpRequest $request): bool => in_array($request->url(), [
            'https://ipapi.co/json/', 'https://ipwho.is/', 'https://ipinfo.io/json', 'https://api.country.is/',
        ], true));
    }

    public function test_the_pricing_page_looks_the_visitor_up_once(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->pricingPage('en', 'https://listenai.studio');

        $this->assertCount(1, Http::recorded(fn (HttpRequest $request): bool => $request->url() === 'https://ipapi.co/203.0.113.9/json/'));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function pricingPage(string $locale, string $host = '', array $headers = []): TestResponse
    {
        app()->setLocale($locale);

        return $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ])->withHeaders($headers)->get($host.$this->localizedRoute('pricing'))->assertOk();
    }

    /** An amount as the cards show it: "$4.00" reads "$4". */
    private function formatted(int $minor, string $currency, string $locale): string
    {
        return (string) preg_replace('/[.,]00(?=\D*$)/u', '', app(LocalizedPricingService::class)->formatAmount($minor, $currency, $locale));
    }
}
