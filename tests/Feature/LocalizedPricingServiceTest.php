<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Services\LocalizedPricingService;
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
        \App\Models\SiteSetting::set('pricing_fallback_country_code', 'US', 'billing');
        \App\Models\SiteSetting::set('pricing_fallback_currency_code', 'USD', 'billing');
        \App\Models\SiteSetting::set('pricing_ip_lookup_enabled', '0', 'billing');
        \App\Models\SiteSetting::set('pricing_accept_language_enabled', '0', 'billing');

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
        \App\Models\SiteSetting::set('pricing_local_country_code', 'EG', 'billing');
        \App\Models\SiteSetting::set('pricing_local_currency_code', 'EGP', 'billing');

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
        \App\Models\SiteSetting::set('pricing_local_country_code', 'EG', 'billing');
        \App\Models\SiteSetting::set('pricing_local_currency_code', 'EGP', 'billing');

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
        \App\Models\SiteSetting::set('manual_instapay_enabled', '1', 'billing');
        \App\Models\SiteSetting::set('manual_instapay_number', '0123456789', 'billing');
        \App\Models\SiteSetting::set('pricing_exchange_rate_EGP', '52.35', 'billing');

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
        $this->assertSame(47115, $quote['amount_minor']);
        $this->assertSame('EGP', $quote['currency']);
    }

    public function test_converted_quote_uses_auto_currency_with_exchange_rate_override(): void
    {
        \App\Models\SiteSetting::set('pricing_exchange_rate_GBP', '0.8', 'billing');

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
}
