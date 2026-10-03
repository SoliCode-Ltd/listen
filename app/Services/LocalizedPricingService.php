<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use NumberFormatter;

class LocalizedPricingService
{
    public const COUNTRY_EGYPT = 'EG';

    public const CURRENCY_EGP = 'EGP';

    public const CURRENCY_USD = 'USD';

    /**
     * Fast local mapping for common countries. Remote lookup is used as fallback.
     *
     * @var array<string, string>
     */
    private const COUNTRY_CURRENCY_MAP = [
        'US' => 'USD',
        'EG' => 'EGP',
        'AE' => 'AED',
        'SA' => 'SAR',
        'QA' => 'QAR',
        'KW' => 'KWD',
        'OM' => 'OMR',
        'BH' => 'BHD',
        'JO' => 'JOD',
        'LB' => 'LBP',
        'MA' => 'MAD',
        'DZ' => 'DZD',
        'TN' => 'TND',
        'LY' => 'LYD',
        'IQ' => 'IQD',
        'TR' => 'TRY',
        'GB' => 'GBP',
        'EU' => 'EUR',
        'DE' => 'EUR',
        'FR' => 'EUR',
        'IT' => 'EUR',
        'ES' => 'EUR',
        'NL' => 'EUR',
        'BE' => 'EUR',
        'AT' => 'EUR',
        'PT' => 'EUR',
        'IE' => 'EUR',
        'CH' => 'CHF',
        'SE' => 'SEK',
        'NO' => 'NOK',
        'DK' => 'DKK',
        'PL' => 'PLN',
        'CZ' => 'CZK',
        'HU' => 'HUF',
        'RO' => 'RON',
        'BG' => 'BGN',
        'RU' => 'RUB',
        'UA' => 'UAH',
        'IN' => 'INR',
        'PK' => 'PKR',
        'BD' => 'BDT',
        'CN' => 'CNY',
        'JP' => 'JPY',
        'KR' => 'KRW',
        'TH' => 'THB',
        'MY' => 'MYR',
        'SG' => 'SGD',
        'ID' => 'IDR',
        'PH' => 'PHP',
        'VN' => 'VND',
        'AU' => 'AUD',
        'NZ' => 'NZD',
        'CA' => 'CAD',
        'MX' => 'MXN',
        'BR' => 'BRL',
        'AR' => 'ARS',
        'CL' => 'CLP',
        'CO' => 'COP',
        'ZA' => 'ZAR',
        'NG' => 'NGN',
        'KE' => 'KES',
    ];

    /**
     * Offline fallback rates in case remote FX lookup fails.
     *
     * @var array<string, float>
     */
    private const USD_FX_FALLBACK_RATES = [
        'USD' => 1.0,
        'EGP' => 52.35,
        'EUR' => 0.92,
        'GBP' => 0.79,
        'AED' => 3.67,
        'SAR' => 3.75,
        'QAR' => 3.64,
        'KWD' => 0.31,
        'OMR' => 0.38,
        'BHD' => 0.38,
        'JOD' => 0.71,
        'TRY' => 34.0,
        'INR' => 83.0,
        'PKR' => 279.0,
        'BDT' => 117.0,
        'CNY' => 7.2,
        'JPY' => 150.0,
        'KRW' => 1330.0,
        'THB' => 35.0,
        'MYR' => 4.4,
        'SGD' => 1.35,
        'IDR' => 15700.0,
        'PHP' => 56.0,
        'VND' => 25000.0,
        'AUD' => 1.52,
        'NZD' => 1.64,
        'CAD' => 1.36,
        'MXN' => 17.0,
        'BRL' => 5.1,
        'ARS' => 1000.0,
        'CLP' => 930.0,
        'COP' => 4000.0,
        'CHF' => 0.88,
        'SEK' => 10.5,
        'NOK' => 10.6,
        'DKK' => 6.9,
        'PLN' => 4.0,
        'CZK' => 23.0,
        'HUF' => 360.0,
        'RON' => 4.6,
        'BGN' => 1.8,
        'RUB' => 90.0,
        'UAH' => 40.0,
        'ZAR' => 18.0,
        'NGN' => 1600.0,
        'KES' => 130.0,
    ];

    /**
     * @return array{
     *   country_code:string,
     *   currency:string,
     *   source:string,
     *   used_fallback:bool,
     *   is_egypt:bool
     * }
     */
    public function resolveCountryContext(?Request $request = null): array
    {
        $request ??= request();

        $forcedCountry = $this->normalizeCountryCode((string) SiteSetting::get('pricing_forced_country_code', ''));
        if ($forcedCountry !== null) {
            return $this->buildContext($forcedCountry, 'forced_setting', false);
        }

        // Localhost/private-network requests should prefer explicit local-market handling.
        // This avoids noisy proxy/browser headers forcing the wrong country during development.
        if ($this->shouldUseLocalOverride($request)) {
            if ($this->siteSettingFlag('pricing_local_detect_public_ip', false)) {
                $publicIpCountry = $this->countryFromPublicIpLookup();
                if ($publicIpCountry !== null) {
                    return $this->buildContext($publicIpCountry, 'public_ip_lookup', false);
                }
            }

            $localCountry = $this->localCountry();

            return $this->buildContext($localCountry, 'local_override', false, true);
        }

        foreach ($this->detectionHeaders() as $header) {
            $country = $this->normalizeCountryCode((string) $request?->header($header, ''));
            if ($country !== null) {
                return $this->buildContext($country, 'header:'.$header, false);
            }
        }

        $ipLookupCountry = $this->countryFromIpLookup($request);
        if ($ipLookupCountry !== null) {
            return $this->buildContext($ipLookupCountry, 'ip_lookup', false);
        }

        $publicIpCountry = $this->countryFromPublicIpLookup();
        if ($publicIpCountry !== null) {
            return $this->buildContext($publicIpCountry, 'public_ip_lookup', false);
        }

        $acceptLanguageCountry = $this->countryFromAcceptLanguage($request);
        if ($acceptLanguageCountry !== null) {
            return $this->buildContext($acceptLanguageCountry, 'accept_language', false);
        }

        $fallbackCountry = $this->normalizeCountryCode((string) SiteSetting::get('pricing_fallback_country_code', 'US')) ?? 'US';

        return $this->buildContext($fallbackCountry, 'fallback', true);
    }

    /**
     * @return array<int, string>
     */
    public function enabledManualMethods(?string $countryCode = null): array
    {
        $countryCode = $this->normalizeCountryCode((string) $countryCode);
        if ($countryCode !== self::COUNTRY_EGYPT) {
            return [];
        }

        $methods = [];

        if ($this->isInstaPayConfigured()) {
            $methods[] = 'instapay';
        }

        if ($this->isVodafoneCashConfigured()) {
            $methods[] = 'vodafone_cash';
        }

        return $methods;
    }

    public function preferredManualMethodForPlan(Plan $plan, array $countryContext): ?string
    {
        foreach ($this->enabledManualMethods((string) ($countryContext['country_code'] ?? '')) as $method) {
            if ($this->manualQuoteForPlan($plan, 'monthly', $countryContext, $method) !== null) {
                return $method;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function manualQuoteForPlan(
        Plan $plan,
        string $cycle,
        array $countryContext,
        string $method,
        ?string $locale = null,
    ): ?array {
        $cycle = $this->normalizeCycle($cycle);
        if (! in_array($method, $this->enabledManualMethods((string) ($countryContext['country_code'] ?? '')), true)) {
            return null;
        }

        if (($countryContext['country_code'] ?? null) !== self::COUNTRY_EGYPT) {
            return null;
        }

        $amountMinor = $this->manualPriceMinor($plan, $cycle);
        if ($amountMinor <= 0) {
            return null;
        }

        return $this->buildQuote($amountMinor, self::CURRENCY_EGP, $cycle, $method, $locale);
    }

    /**
     * @return array{label:string,description:string}
     */
    public function paymentMethodMeta(string $method): array
    {
        return match ($method) {
            'instapay' => [
                'label' => __('ui.billing.payment_method_instapay'),
                'description' => __('ui.billing.payment_method_instapay_desc'),
                'logo_url' => $this->paymentLogoUrl('instapay'),
            ],
            'vodafone_cash' => [
                'label' => __('ui.billing.payment_method_vodafone_cash'),
                'description' => __('ui.billing.payment_method_vodafone_cash_desc'),
                'logo_url' => $this->paymentLogoUrl('vodafone_cash'),
            ],
            default => [
                'label' => __('ui.billing.payment_method_stripe'),
                'description' => __('ui.billing.payment_method_stripe_desc'),
                'logo_url' => $this->paymentLogoUrl('stripe'),
            ],
        };
    }

    /**
     * @return array{
     *   method:string,
     *   label:string,
     *   destination_primary:?string,
     *   destination_secondary:?string,
     *   instructions:string,
     *   lines:array<int, string>,
     *   payment_link:?string,
     *   qr_image_url:?string
     * }
     */
    public function manualPaymentDestination(string $method, ?string $locale = null): array
    {
        $locale = $locale ?: app()->getLocale();
        $instructions = $this->manualInstructionText($locale);

        return match ($method) {
            'instapay' => [
                'method' => 'instapay',
                'label' => __('ui.billing.payment_method_instapay'),
                'destination_primary' => null,
                'destination_secondary' => null,
                'instructions' => $instructions,
                'lines' => [],
                'payment_link' => $this->cleanSetting('manual_instapay_payment_link'),
                'qr_image_url' => $this->fileSettingUrl('manual_instapay_qr_image'),
            ],
            'vodafone_cash' => [
                'method' => 'vodafone_cash',
                'label' => __('ui.billing.payment_method_vodafone_cash'),
                'destination_primary' => null,
                'destination_secondary' => null,
                'instructions' => $instructions,
                'lines' => [],
                'payment_link' => $this->cleanSetting('manual_vodafone_cash_payment_link'),
                'qr_image_url' => $this->fileSettingUrl('manual_vodafone_cash_qr_image'),
            ],
            default => [
                'method' => 'stripe',
                'label' => __('ui.billing.payment_method_stripe'),
                'destination_primary' => null,
                'destination_secondary' => null,
                'instructions' => '',
                'lines' => [],
                'payment_link' => null,
                'qr_image_url' => null,
            ],
        };
    }

    public function formatAmount(int $amountMinor, string $currency, ?string $locale = null): string
    {
        $locale = strtolower(trim((string) ($locale ?: app()->getLocale())));
        $formatterLocale = $locale === 'ar'
            ? ($currency === self::CURRENCY_EGP ? 'ar_EG@numbers=latn' : 'ar@numbers=latn')
            : 'en_US';

        $isEgp = strtoupper($currency) === self::CURRENCY_EGP;
        $amount = $amountMinor / 100;

        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter($formatterLocale, NumberFormatter::CURRENCY);
            if ($isEgp) {
                $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);
            }
            $formatted = $formatter->formatCurrency($amount, strtoupper($currency));
            if (is_string($formatted) && trim($formatted) !== '') {
                return $this->normalizeToLatinDigits(trim($formatted));
            }
        }

        $decimals = $isEgp ? 0 : 2;

        return $this->normalizeToLatinDigits(strtoupper($currency).' '.number_format($amount, $decimals));
    }

    /**
     * @return array{
     *   method:string,
     *   amount_minor:int,
     *   currency:string,
     *   amount_formatted:string,
     *   cycle:string,
     *   interval:string,
     *   interval_count:int,
     *   equivalent_monthly_minor:int,
     *   equivalent_monthly_formatted:string
     * }
     */
    public function buildQuote(
        int $amountMinor,
        string $currency,
        string $cycle,
        string $method = 'stripe',
        ?string $locale = null,
    ): array {
        $cycle = $this->normalizeCycle($cycle);
        $amountMinor = max(0, $amountMinor);
        $equivalentMonthlyMinor = $cycle === 'yearly'
            ? (int) round($amountMinor / 12)
            : $amountMinor;

        return [
            'method' => $method,
            'amount_minor' => $amountMinor,
            'currency' => strtoupper($currency),
            'amount_formatted' => $this->formatAmount($amountMinor, $currency, $locale),
            'cycle' => $cycle,
            'interval' => $cycle === 'yearly' ? 'year' : 'month',
            'interval_count' => 1,
            'equivalent_monthly_minor' => $equivalentMonthlyMinor,
            'equivalent_monthly_formatted' => $this->formatAmount($equivalentMonthlyMinor, $currency, $locale),
        ];
    }

    /**
     * Build a display quote from a USD base amount converted to the visitor currency.
     *
     * @return array{
     *   method:string,
     *   amount_minor:int,
     *   currency:string,
     *   amount_formatted:string,
     *   cycle:string,
     *   interval:string,
     *   interval_count:int,
     *   equivalent_monthly_minor:int,
     *   equivalent_monthly_formatted:string,
     *   base_usd_minor:int,
     *   base_usd_formatted:string,
     *   is_converted:bool
     * }
     */
    public function convertedQuoteFromUsd(
        int $usdAmountMinor,
        string $cycle,
        array $countryContext,
        string $method = 'stripe',
        ?string $locale = null,
    ): array {
        $currency = $this->displayCurrencyForCountryContext($countryContext);
        $convertedMinor = $this->convertUsdMinorToCurrency($usdAmountMinor, $currency);
        $quote = $this->buildQuote($convertedMinor, $currency, $cycle, $method, $locale);

        $quote['base_usd_minor'] = max(0, $usdAmountMinor);
        $quote['base_usd_formatted'] = $this->formatAmount(max(0, $usdAmountMinor), self::CURRENCY_USD, $locale);
        $quote['is_converted'] = strtoupper($currency) !== self::CURRENCY_USD;

        return $quote;
    }

    public function convertUsdMinorToCurrency(int $usdAmountMinor, string $targetCurrency): int
    {
        $usdAmountMinor = max(0, $usdAmountMinor);
        $targetCurrency = strtoupper(trim($targetCurrency));

        if ($targetCurrency === '' || $targetCurrency === self::CURRENCY_USD) {
            return $usdAmountMinor;
        }

        $rate = $this->exchangeRateForCurrency($targetCurrency);
        if ($rate <= 0) {
            return $usdAmountMinor;
        }

        $converted = max(0, (int) round($usdAmountMinor * $rate));

        // EGP: always round up to whole currency units (no fractions/piasters)
        if ($targetCurrency === self::CURRENCY_EGP && $converted % 100 !== 0) {
            $converted = (int) (ceil($converted / 100) * 100);
        }

        return $converted;
    }

    /**
     * @return array{stripe:?string,instapay:?string,vodafone_cash:?string}
     */
    public function paymentBrandAssets(): array
    {
        return [
            'stripe' => $this->paymentLogoUrl('stripe'),
            'instapay' => $this->paymentLogoUrl('instapay'),
            'vodafone_cash' => $this->paymentLogoUrl('vodafone_cash'),
        ];
    }

    public function decimalToMinor(mixed $value): int
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return 0;
        }

        $normalized = str_replace([',', ' '], ['', ''], $normalized);

        return max(0, (int) round(((float) $normalized) * 100));
    }

    public function minorToDecimalString(mixed $value): string
    {
        $minor = max(0, (int) $value);

        return number_format($minor / 100, 2, '.', '');
    }

    private function isInstaPayConfigured(): bool
    {
        if (! $this->siteSettingFlag('manual_instapay_enabled', false)) {
            return false;
        }

        return $this->hasManualCheckoutChannel('instapay');
    }

    private function isVodafoneCashConfigured(): bool
    {
        if (! $this->siteSettingFlag('manual_vodafone_cash_enabled', false)) {
            return false;
        }

        return $this->hasManualCheckoutChannel('vodafone_cash');
    }

    private function manualInstructionText(?string $locale = null): string
    {
        $locale = strtolower(trim((string) ($locale ?: app()->getLocale())));
        $primaryKey = $locale === 'ar'
            ? 'manual_payment_instructions_ar'
            : 'manual_payment_instructions_en';
        $fallbackKey = $locale === 'ar'
            ? 'manual_payment_instructions_en'
            : 'manual_payment_instructions_ar';

        $primary = trim((string) SiteSetting::get($primaryKey, ''));
        if ($primary !== '') {
            return $primary;
        }

        $fallback = trim((string) SiteSetting::get($fallbackKey, ''));
        if ($fallback !== '') {
            return $fallback;
        }

        return __('ui.billing.manual_default_instructions');
    }

    private function buildContext(string $countryCode, string $source, bool $usedFallback, bool $isLocalOverride = false): array
    {
        $isEgypt = $countryCode === self::COUNTRY_EGYPT;

        return [
            'country_code' => $countryCode,
            'currency' => $this->resolveCurrencyForCountry($countryCode, $isLocalOverride),
            'source' => $source,
            'used_fallback' => $usedFallback,
            'is_egypt' => $isEgypt,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function detectionHeaders(): array
    {
        return [
            'CF-IPCountry',
            'X-Vercel-IP-Country',
            'CloudFront-Viewer-Country',
            'X-Country-Code',
            'X-Country',
        ];
    }

    private function fallbackCurrency(): string
    {
        $currency = strtoupper(trim((string) SiteSetting::get('pricing_fallback_currency_code', self::CURRENCY_USD)));

        return $currency !== '' ? $currency : self::CURRENCY_USD;
    }

    private function localCountry(): string
    {
        return $this->normalizeCountryCode((string) SiteSetting::get('pricing_local_country_code', self::COUNTRY_EGYPT))
            ?? self::COUNTRY_EGYPT;
    }

    private function localCurrency(): string
    {
        $defaultCurrency = $this->currencyForCountryCode($this->localCountry()) ?? $this->fallbackCurrency();
        $currency = strtoupper(trim((string) SiteSetting::get('pricing_local_currency_code', $defaultCurrency)));

        return $currency !== '' ? $currency : $defaultCurrency;
    }

    private function normalizeCountryCode(string $countryCode): ?string
    {
        $countryCode = strtoupper(trim($countryCode));
        if ($countryCode === '' || strlen($countryCode) !== 2) {
            return null;
        }

        if (in_array($countryCode, ['XX', 'A1', 'A2', 'O1', 'T1'], true)) {
            return null;
        }

        return $countryCode;
    }

    private function normalizeCycle(string $cycle): string
    {
        return strtolower(trim($cycle)) === 'yearly' ? 'yearly' : 'monthly';
    }

    private function resolveCurrencyForCountry(string $countryCode, bool $isLocalOverride = false): string
    {
        if ($isLocalOverride) {
            return $this->localCurrency();
        }

        return $this->currencyForCountryCode($countryCode) ?? $this->fallbackCurrency();
    }

    private function shouldUseLocalOverride(?Request $request): bool
    {
        if (! $request) {
            return false;
        }

        $host = strtolower(trim((string) $request->getHost()));
        $ip = $this->clientIpForLookup($request) ?? trim((string) $request->ip());

        return $this->isLocalHost($host) || $this->isLocalIp($ip);
    }

    private function isLocalHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        return str_ends_with($host, '.test')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.localhost');
    }

    private function isLocalIp(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        return str_starts_with($ip, '10.')
            || str_starts_with($ip, '192.168.')
            || preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $ip) === 1;
    }

    private function manualPriceMinor(Plan $plan, string $cycle): int
    {
        return $this->derivedEgyptManualPriceMinor($plan, $cycle);
    }

    private function derivedEgyptManualPriceMinor(Plan $plan, string $cycle): int
    {
        $usdMinor = $this->normalizeCycle($cycle) === 'yearly'
            ? max(0, (int) $plan->getEffectiveYearlyPriceCents(SiteSetting::yearlyDiscountPercent()))
            : max(0, (int) $plan->getEffectivePriceCents());

        return $this->convertUsdMinorToCurrency($usdMinor, self::CURRENCY_EGP);
    }

    private function exchangeRateForCurrency(string $currency): float
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '' || $currency === self::CURRENCY_USD) {
            return 1.0;
        }

        $countrySpecificKey = 'pricing_exchange_rate_'.$currency;
        $configured = trim((string) SiteSetting::get($countrySpecificKey, ''));
        if ($configured !== '') {
            $configuredRate = (float) $configured;
            if ($configuredRate > 0) {
                return $configuredRate;
            }
        }

        $rates = Cache::remember('billing:usd_fx_rates:v2', now()->addHours(1), function (): array {
            return $this->fetchUsdRates();
        });

        $liveRate = (float) ($rates[$currency] ?? 0);
        if ($liveRate > 0) {
            return $liveRate;
        }

        return (float) (self::USD_FX_FALLBACK_RATES[$currency] ?? 0);
    }

    private function displayCurrencyForCountryContext(array $countryContext): string
    {
        $countryCode = $this->normalizeCountryCode((string) ($countryContext['country_code'] ?? ''));
        if ($countryCode === null) {
            return $this->fallbackCurrency();
        }

        return $this->currencyForCountryCode($countryCode) ?? $this->fallbackCurrency();
    }

    private function currencyForCountryCode(string $countryCode): ?string
    {
        $countryCode = strtoupper(trim($countryCode));
        if ($countryCode === '' || strlen($countryCode) !== 2) {
            return null;
        }

        $override = strtoupper(trim((string) SiteSetting::get('pricing_currency_override_'.$countryCode, '')));
        if ($override !== '' && strlen($override) === 3) {
            return $override;
        }

        if (isset(self::COUNTRY_CURRENCY_MAP[$countryCode])) {
            return self::COUNTRY_CURRENCY_MAP[$countryCode];
        }

        return Cache::remember('billing:country_currency:'.$countryCode, now()->addDays(30), function () use ($countryCode): ?string {
            try {
                $response = Http::timeout(3)
                    ->acceptJson()
                    ->get('https://restcountries.com/v3.1/alpha/'.$countryCode, [
                        'fields' => 'currencies',
                    ]);

                if (! $response->ok()) {
                    return null;
                }

                $body = $response->json();
                $currencies = [];

                if (is_array($body) && array_is_list($body)) {
                    $currencies = $body[0]['currencies'] ?? [];
                } elseif (is_array($body)) {
                    $currencies = $body['currencies'] ?? [];
                }

                if (! is_array($currencies) || $currencies === []) {
                    return null;
                }

                $code = strtoupper((string) array_key_first($currencies));

                return strlen($code) === 3 ? $code : null;
            } catch (\Throwable) {
                return null;
            }
        });
    }

    private function countryFromIpLookup(?Request $request): ?string
    {
        if (! $request || ! $this->siteSettingFlag('pricing_ip_lookup_enabled', true)) {
            return null;
        }

        $ip = $this->clientIpForLookup($request) ?? trim((string) $request->ip());
        if ($ip === '' || $this->isLocalIp($ip)) {
            return null;
        }

        return Cache::remember('billing:ip_country:'.sha1($ip), now()->addHours(6), function () use ($ip): ?string {
            return $this->lookupCountryFromProviders($ip);
        });
    }

    /**
     * Country of a stored IP address, for analytics and the member country
     * backfill. Private and local addresses have no country.
     */
    public function countryForIp(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false || $this->isLocalIp($ip)
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        $key = 'billing:ip_country:'.sha1($ip);
        try {
            $cached = Cache::get($key);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        } catch (\Throwable) {
            // An unreadable cache only means one more lookup.
        }

        $country = $this->lookupCountryFromProviders($ip);
        if ($country !== null) {
            try {
                Cache::put($key, $country, now()->addDays(30));
            } catch (\Throwable) {
                // A cache the current user cannot write (for example storage folders
                // owned by the web server) must not stop the caller.
            }
        }

        return $country;
    }

    private function countryFromPublicIpLookup(): ?string
    {
        if (! $this->siteSettingFlag('pricing_public_ip_lookup_enabled', true)) {
            return null;
        }

        return Cache::remember('billing:public_ip_country', now()->addHours(4), function (): ?string {
            return $this->lookupCountryFromProviders(null);
        });
    }

    private function countryFromAcceptLanguage(?Request $request): ?string
    {
        if (! $request || ! $this->siteSettingFlag('pricing_accept_language_enabled', true)) {
            return null;
        }

        $header = strtolower(trim((string) $request->header('Accept-Language', '')));
        if ($header === '') {
            return null;
        }

        foreach (explode(',', $header) as $part) {
            $token = trim(explode(';', $part)[0] ?? '');
            if ($token === '') {
                continue;
            }

            $fragments = preg_split('/[-_]/', $token) ?: [];
            if (count($fragments) < 2) {
                continue;
            }

            $region = $this->normalizeCountryCode((string) end($fragments));
            if ($region !== null) {
                return $region;
            }
        }

        return null;
    }

    private function paymentLogoUrl(string $method): ?string
    {
        $key = match ($method) {
            'instapay' => 'payment_logo_instapay',
            'vodafone_cash' => 'payment_logo_vodafone_cash',
            default => 'payment_logo_stripe',
        };

        $configured = $this->fileSettingUrl($key);
        if ($configured !== null) {
            return $configured;
        }

        $fallbackCandidates = match ($method) {
            'instapay' => [
                '/images/payments/instapay.png',
                '/images/payments/instapay.webp',
                '/images/payments/instapay.jpg',
            ],
            'vodafone_cash' => [
                '/images/payments/vodafone-cash.png',
                '/images/payments/vodafone-cash.webp',
                '/images/payments/vodafone-cash.jpg',
            ],
            default => [
                '/images/payments/stripe.png',
                '/images/payments/stripe.webp',
                '/images/payments/stripe.jpg',
            ],
        };

        foreach ($fallbackCandidates as $candidate) {
            if (is_file(public_path(ltrim($candidate, '/')))) {
                return $candidate;
            }
        }

        return null;
    }

    private function fileSettingUrl(string $key): ?string
    {
        $value = trim((string) SiteSetting::get($key, ''));
        if ($value === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $value) === 1 || str_starts_with($value, '/')) {
            return $value;
        }

        return Storage::disk('public')->url($value);
    }

    private function clientIpForLookup(Request $request): ?string
    {
        $directHeaders = [
            'CF-Connecting-IP',
            'True-Client-IP',
            'X-Real-IP',
        ];

        foreach ($directHeaders as $header) {
            $candidate = trim((string) $request->header($header, ''));
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
                if ($this->isLocalIp($candidate)) {
                    continue;
                }
                return $candidate;
            }
        }

        $forwardedFor = trim((string) $request->header('X-Forwarded-For', ''));
        if ($forwardedFor !== '') {
            foreach (explode(',', $forwardedFor) as $candidate) {
                $ip = trim($candidate);
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                    if ($this->isLocalIp($ip)) {
                        continue;
                    }
                    return $ip;
                }
            }
        }

        $requestIp = trim((string) $request->ip());

        return $requestIp !== '' && filter_var($requestIp, FILTER_VALIDATE_IP) ? $requestIp : null;
    }

    private function siteSettingFlag(string $key, bool $default = false): bool
    {
        return (string) SiteSetting::get($key, $default ? '1' : '0') === '1';
    }

    private function cleanSetting(string $key): ?string
    {
        $value = trim((string) SiteSetting::get($key, ''));

        return $value !== '' ? $value : null;
    }

    private function hasManualCheckoutChannel(string $method): bool
    {
        if ($method === 'instapay') {
            return $this->cleanSetting('manual_instapay_payment_link') !== null
                || $this->fileSettingUrl('manual_instapay_qr_image') !== null
                // Backward-compatible fallback in case old records still depend on these fields.
                || $this->cleanSetting('manual_instapay_number') !== null
                || $this->cleanSetting('manual_instapay_handle') !== null;
        }

        if ($method === 'vodafone_cash') {
            return $this->cleanSetting('manual_vodafone_cash_payment_link') !== null
                || $this->fileSettingUrl('manual_vodafone_cash_qr_image') !== null
                // Backward-compatible fallback in case old records still depend on this field.
                || $this->cleanSetting('manual_vodafone_cash_number') !== null;
        }

        return false;
    }

    private function normalizeToLatinDigits(string $value): string
    {
        return strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.', '٬' => ',',
        ]);
    }

    /**
     * @return array<string, float>
     */
    private function fetchUsdRates(): array
    {
        $providers = [
            static function (): array {
                $response = Http::timeout(3)
                    ->acceptJson()
                    ->get('https://open.er-api.com/v6/latest/USD');

                if (! $response->ok()) {
                    return [];
                }

                $rates = $response->json('rates');

                return is_array($rates)
                    ? array_map(static fn ($value): float => (float) $value, $rates)
                    : [];
            },
            static function (): array {
                $response = Http::timeout(3)
                    ->acceptJson()
                    ->get('https://api.frankfurter.app/latest?from=USD');

                if (! $response->ok()) {
                    return [];
                }

                $rates = $response->json('rates');
                if (! is_array($rates)) {
                    return [];
                }

                $normalized = array_map(static fn ($value): float => (float) $value, $rates);
                $normalized[self::CURRENCY_USD] = 1.0;

                return $normalized;
            },
        ];

        foreach ($providers as $provider) {
            try {
                $rates = $provider();
                if ($rates !== []) {
                    return $rates;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return [];
    }

    private function lookupCountryFromProviders(?string $ip = null): ?string
    {
        $encodedIp = $ip !== null && trim($ip) !== '' ? rawurlencode(trim($ip)) : null;
        $providers = [
            [
                'url' => $encodedIp !== null ? 'https://ipapi.co/'.$encodedIp.'/json/' : 'https://ipapi.co/json/',
                'key' => 'country_code',
            ],
            [
                'url' => $encodedIp !== null ? 'https://ipwho.is/'.$encodedIp : 'https://ipwho.is/',
                'key' => 'country_code',
            ],
            [
                'url' => $encodedIp !== null ? 'https://ipinfo.io/'.$encodedIp.'/json' : 'https://ipinfo.io/json',
                'key' => 'country',
            ],
            [
                'url' => $encodedIp !== null ? 'https://api.country.is/'.$encodedIp : 'https://api.country.is/',
                'key' => 'country',
            ],
        ];

        foreach ($providers as $provider) {
            try {
                $response = Http::timeout(2)->acceptJson()->get($provider['url']);
                if (! $response->ok()) {
                    continue;
                }

                $country = $this->normalizeCountryCode((string) $response->json($provider['key']));
                if ($country !== null) {
                    return $country;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
