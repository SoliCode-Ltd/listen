<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Services\LocalizedPricingService;
use App\Support\CurrencySymbols;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LocalizedPricingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_country_context_uses_trusted_header_before_fallback(): void
    {
        $service = app(LocalizedPricingService::class);
        $request = Request::create('/billing', 'GET', [], [], [], [
            'HTTP_CF_IPCOUNTRY' => 'EG',
        ]);

        $context = $service->resolveCountryContext($request);

        $this->assertSame('EG', $context['country_code']);
        $this->assertSame('EGP', $context['currency']);
        $this->assertFalse($context['used_fallback']);
    }

    public function test_country_context_falls_back_to_configured_market(): void
    {
        SiteSetting::set('pricing_fallback_country_code', 'US', 'billing');
        SiteSetting::set('pricing_fallback_currency_code', 'USD', 'billing');
        SiteSetting::set('pricing_ip_lookup_enabled', '0', 'billing');
        // Also the server-public-IP lookup, which otherwise geolocates the machine running the tests.
        SiteSetting::set('pricing_public_ip_lookup_enabled', '0', 'billing');
        SiteSetting::set('pricing_accept_language_enabled', '0', 'billing');

        $service = app(LocalizedPricingService::class);
        $request = Request::create('https://example.com/billing', 'GET', [], [], [], [
            'REMOTE_ADDR' => '8.8.8.8',
        ]);

        $context = $service->resolveCountryContext($request);

        $this->assertSame('US', $context['country_code']);
        $this->assertSame('USD', $context['currency']);
        $this->assertTrue($context['used_fallback']);
    }

    public function test_localhost_uses_local_override_market(): void
    {
        SiteSetting::set('pricing_local_country_code', 'EG', 'billing');
        SiteSetting::set('pricing_local_currency_code', 'EGP', 'billing');

        $service = app(LocalizedPricingService::class);
        $request = Request::create('http://localhost/billing', 'GET');

        $context = $service->resolveCountryContext($request);

        $this->assertSame('EG', $context['country_code']);
        $this->assertSame('EGP', $context['currency']);
        $this->assertSame('local_override', $context['source']);
        $this->assertFalse($context['used_fallback']);
    }

    public function test_localhost_ignores_header_noise_and_keeps_local_override_market(): void
    {
        SiteSetting::set('pricing_local_country_code', 'EG', 'billing');
        SiteSetting::set('pricing_local_currency_code', 'EGP', 'billing');

        $service = app(LocalizedPricingService::class);
        $request = Request::create('http://localhost/billing', 'GET', [], [], [], [
            'HTTP_CF_IPCOUNTRY' => 'US',
        ]);

        $context = $service->resolveCountryContext($request);

        $this->assertSame('EG', $context['country_code']);
        $this->assertSame('EGP', $context['currency']);
        $this->assertSame('local_override', $context['source']);
        $this->assertFalse($context['used_fallback']);
    }

    public function test_country_context_uses_ip_lookup_when_headers_are_missing(): void
    {
        Http::fake([
            'https://ipapi.co/*' => Http::response(['country_code' => 'EG'], 200),
            'https://ipwho.is/*' => Http::response(['country_code' => 'US'], 200),
        ]);

        $service = app(LocalizedPricingService::class);
        $request = Request::create('https://example.com/billing', 'GET', [], [], [], [
            'REMOTE_ADDR' => '197.35.225.112',
        ]);

        $context = $service->resolveCountryContext($request);

        $this->assertSame('EG', $context['country_code']);
        $this->assertSame('ip_lookup', $context['source']);
        $this->assertFalse($context['used_fallback']);
    }

    public function test_egypt_manual_quote_uses_automatic_exchange_rate_conversion(): void
    {
        SiteSetting::set('manual_instapay_enabled', '1', 'billing');
        SiteSetting::set('manual_instapay_number', '0123456789', 'billing');
        SiteSetting::set('pricing_exchange_rate_EGP', '52.35', 'billing');

        $plan = Plan::query()->create([
            'slug' => 'starter',
            'name' => 'Starter',
            'price_cents' => 900,
            'monthly_minutes' => 1000,
            'monthly_chars' => 100000,
            'monthly_requests' => 100,
            'max_chars_per_request' => 1000,
            'is_active' => true,
        ]);

        $service = app(LocalizedPricingService::class);
        $quote = $service->manualQuoteForPlan($plan, 'monthly', [
            'country_code' => 'EG',
            'currency' => 'EGP',
            'source' => 'local_override',
            'used_fallback' => false,
            'is_egypt' => true,
        ], 'instapay', 'en');

        $this->assertNotNull($quote);
        $this->assertSame(47200, $quote['amount_minor']); // EGP is rounded up to whole pounds (471.15 -> 472)
        $this->assertSame('EGP', $quote['currency']);
    }

    public function test_converted_quote_uses_auto_currency_with_exchange_rate_override(): void
    {
        SiteSetting::set('pricing_exchange_rate_GBP', '0.8', 'billing');

        $service = app(LocalizedPricingService::class);
        $quote = $service->convertedQuoteFromUsd(900, 'monthly', [
            'country_code' => 'GB',
            'currency' => 'GBP',
            'source' => 'header:CF-IPCountry',
            'used_fallback' => false,
            'is_egypt' => false,
        ], 'stripe', 'en');

        $this->assertSame('GBP', $quote['currency']);
        $this->assertSame(720, $quote['amount_minor']);
        $this->assertTrue($quote['is_converted']);
    }

    public function test_arabic_locale_amount_format_uses_latin_digits(): void
    {
        $service = app(LocalizedPricingService::class);

        $formatted = $service->formatAmount(47115, 'EGP', 'ar');

        $this->assertMatchesRegularExpression('/[0-9]/', $formatted);
        $this->assertDoesNotMatchRegularExpression('/[٠-٩۰-۹]/u', $formatted);
    }

    /**
     * A country the service has no line for gets the currency PHP itself knows for it,
     * with no request to an outside service (the one once asked has retired).
     */
    public function test_a_country_without_its_own_line_gets_its_currency_without_asking_outside(): void
    {
        Http::fake();
        $service = app(LocalizedPricingService::class);

        foreach (['FI' => 'EUR', 'GR' => 'EUR', 'MR' => 'MRU', 'PS' => 'ILS', 'SO' => 'SOS'] as $country => $currency) {
            $context = $service->resolveCountryContext(Request::create('https://example.com/pricing', 'GET', [], [], [], [
                'REMOTE_ADDR' => '203.0.113.10',
                'HTTP_CF_IPCOUNTRY' => $country,
            ]));
            $this->assertSame($currency, $context['currency'], $country);
        }

        Http::assertNothingSent();
    }

    /**
     * Yemen, Syria and Sudan are priced in dollars on purpose: their money trades far from
     * the official rate, and the card is charged in dollars anyway.
     */
    public function test_yemen_syria_and_sudan_are_priced_in_dollars(): void
    {
        Http::fake();
        SiteSetting::set('pricing_exchange_rate_YER', '236', 'billing');
        $service = app(LocalizedPricingService::class);

        foreach (['YE', 'SY', 'SD'] as $country) {
            $context = $service->resolveCountryContext(Request::create('https://example.com/pricing', 'GET', [], [], [], [
                'REMOTE_ADDR' => '203.0.113.10',
                'HTTP_CF_IPCOUNTRY' => $country,
            ]));
            $this->assertSame('USD', $context['currency'], $country);

            $quote = $service->convertedQuoteFromUsd(400, 'monthly', $context, 'stripe', 'ar');
            $this->assertSame('USD', $quote['currency'], $country);
            $this->assertSame(400, $quote['amount_minor'], $country);
            $this->assertFalse($quote['is_converted'], $country);
        }

        Http::assertNothingSent();
    }

    /**
     * Dollars read the same on the Arabic pages as on the English ones: "$4", not "4 US$".
     * Other currencies keep their Arabic form.
     */
    public function test_dollars_are_written_with_the_dollar_sign_first_in_arabic(): void
    {
        $service = app(LocalizedPricingService::class);

        $this->assertSame('$4.00', $service->formatAmount(400, 'USD', 'ar'));
        $this->assertSame('$4.00', $service->formatAmount(400, 'USD', 'en'));
        $this->assertSame('$19.80', $service->formatAmount(1980, 'USD', 'ar'));
        $this->assertSame('$5', $service->formatWholeAmount(450, 'USD', 'ar'));
        $this->assertStringNotContainsString('US$', $service->formatAmount(1234500, 'USD', 'ar'));

        $riyals = $service->formatAmount(1500, 'SAR', 'ar');
        $this->assertStringContainsString('ر.س', $riyals);
        $this->assertStringContainsString('15.00', $riyals);
    }

    /**
     * A currency the library would write as its code is written with its own country's
     * symbol, in English before the number and in Arabic after it; the amount is the same.
     */
    public function test_currencies_are_written_with_their_own_symbol(): void
    {
        $service = app(LocalizedPricingService::class);
        // The library keeps number and symbol together with a no-break space, and marks
        // Arabic prices right to left; compared here as plain text.
        $plain = fn (string $text): string => str_replace(["\u{200F}", "\u{00A0}"], ['', ' '], $text);

        $this->assertMatchesRegularExpression('/^₺15(\.00)?$/u', $plain($service->formatAmount(1500, 'TRY', 'en')));
        $this->assertMatchesRegularExpression('/^15(\.00)?\ ₺$/u', $plain($service->formatAmount(1500, 'TRY', 'ar')));
        // Whether the rupee is written with paisa depends on the formatting library's version.
        $this->assertMatchesRegularExpression('/^Rs 1,106(\.00)?$/', $plain($service->formatAmount(110600, 'PKR', 'en')));
        $this->assertMatchesRegularExpression('/^Ksh\ 15(\.00)?$/u', $plain($service->formatAmount(1500, 'KES', 'en')));
        $this->assertMatchesRegularExpression('/^₦5,920(\.00)?$/u', $plain($service->formatAmount(592000, 'NGN', 'en')));
        $this->assertMatchesRegularExpression('/^R\ 73(\.10)?$/u', $plain($service->formatAmount(7310, 'ZAR', 'en')));
        // The shilling has no cents: none are written.
        $this->assertMatchesRegularExpression('/^USh\ 16,343$/u', $plain($service->formatAmount(1634337, 'UGX', 'en')));
        $this->assertMatchesRegularExpression('/^₺16$/u', $plain($service->formatWholeAmount(1550, 'TRY', 'en')));
        $this->assertMatchesRegularExpression('/^₱15(\.00)?$/u', $plain($service->formatAmount(1500, 'PHP', 'en')));
        $this->assertMatchesRegularExpression('/^15(\.00)?\ ₱$/u', $plain($service->formatAmount(1500, 'PHP', 'ar')));
    }

    /**
     * Every symbol on the list is used, in both languages, instead of the code, and the
     * written price is still the amount.
     */
    public function test_every_listed_symbol_replaces_the_code_and_keeps_the_amount(): void
    {
        $service = app(LocalizedPricingService::class);

        foreach (CurrencySymbols::all() as $currency => $symbol) {
            foreach (['en', 'ar'] as $locale) {
                $written = $service->formatAmount(123456, $currency, $locale);
                $this->assertStringContainsString($symbol, $written, "{$currency} {$locale}");
                if (! str_contains($symbol, $currency)) {
                    $this->assertStringNotContainsString($currency, $written, "{$currency} {$locale}");
                }
                $this->assertSame(1, preg_match('/\d[\d,]*(?:\.\d+)?/', $written, $number), "{$currency} {$locale}");
                $this->assertEqualsWithDelta(1234.56, (float) str_replace(',', '', $number[0]), 1.0, "{$currency} {$locale}: {$written}");
            }
        }
    }

    /**
     * Arab currencies stay as they were: Arabic letters on the Arabic pages, the code on the
     * English ones. A peso whose own sign is "$" keeps its code, so it never reads as dollars.
     */
    public function test_arab_currencies_and_dollar_sign_pesos_keep_their_form(): void
    {
        $service = app(LocalizedPricingService::class);
        $plain = fn (string $text): string => str_replace(["\u{200F}", "\u{00A0}"], ['', ' '], $text);

        $this->assertMatchesRegularExpression('/^SAR\ 15(\.00)?$/u', $plain($service->formatAmount(1500, 'SAR', 'en')));
        $this->assertStringContainsString('ر.س', $service->formatAmount(1500, 'SAR', 'ar'));
        $this->assertMatchesRegularExpression('/^AED\ 15(\.00)?$/u', $plain($service->formatAmount(1500, 'AED', 'en')));
        $this->assertStringContainsString('د.ك', $service->formatAmount(1500, 'KWD', 'ar'));
        $this->assertMatchesRegularExpression('/^ARS\ 15(\.00)?$/u', $plain($service->formatAmount(1500, 'ARS', 'en')));
        // Whether the peso is written with centavos depends on the formatting library's version.
        $this->assertMatchesRegularExpression('/^COP 15(\.00)?$/', $plain($service->formatAmount(1500, 'COP', 'en')));
        $this->assertMatchesRegularExpression('/^€15(\.00)?$/u', $plain($service->formatAmount(1500, 'EUR', 'en')));
        $this->assertMatchesRegularExpression('/^\$15(\.00)?$/u', $plain($service->formatAmount(1500, 'USD', 'ar')));
    }

    /** Bulgaria has used the euro since 1 January 2026. */
    public function test_bulgaria_is_priced_in_euros(): void
    {
        Http::fake();
        $context = app(LocalizedPricingService::class)->resolveCountryContext(Request::create('https://example.com/pricing', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_CF_IPCOUNTRY' => 'BG',
        ]));

        $this->assertSame('EUR', $context['currency']);
    }
}
