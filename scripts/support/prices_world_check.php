<?php

/*
 * Every country's prices, checked: for each country in the world, the currency the
 * pricing page shows a visitor from there, and each active plan's monthly and yearly
 * price in it, worked out by the site's own code (LocalizedPricingService) the way the
 * pricing page does. For each price it checks that:
 *
 *   - the shown amount is the dollar price times the rate, once (never twice, never
 *     the dollar amount under another currency's name);
 *   - the written price ("SAR 15.00", "$4", "¥600"), read back, is that amount, in
 *     English and in Arabic;
 *   - the rate agrees with a second, independent rate source (when it can be reached).
 *
 * Prints countries, currencies, rates and prices only: nothing about members. Writes
 * nothing itself (a visitor's page refreshes the rates the same way, at most hourly).
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/prices_world_check.php
 */

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Services\LocalizedPricingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";

$pricing = app(LocalizedPricingService::class);
$forced = trim((string) SiteSetting::get('pricing_forced_country_code', ''));
echo 'Forced country setting: ', $forced === '' ? 'none' : $forced.' (every visitor is priced as this country)', "\n";

// The plans the pricing page shows, at the prices it uses.
$yearlyDiscount = SiteSetting::yearlyDiscountPercent();
$plans = Plan::query()->where('is_active', true)->where('price_cents', '>', 0)->orderBy('price_cents')->get()
    ->filter(fn (Plan $p) => ! $p->isPack())
    ->map(fn (Plan $p) => ['slug' => (string) $p->slug, 'monthly' => (int) $p->getEffectivePriceCents(), 'yearly' => (int) $p->getEffectiveYearlyPriceCents($yearlyDiscount)])
    ->values()->all();
echo 'Plans (US cents, monthly / yearly): ', implode(', ', array_map(fn ($p) => "{$p['slug']} {$p['monthly']} / {$p['yearly']}", $plans)), "\n";
if ($plans === []) {
    echo "No active paid plan.\n";
    exit(0);
}

// The rate the site uses for a currency, read the way it reads it, without asking anyone.
$reflection = new ReflectionClass(LocalizedPricingService::class);
$fallbackRates = $reflection->getConstant('USD_FX_FALLBACK_RATES');
$rateOf = function (string $currency) use ($fallbackRates): float {
    if ($currency === 'USD') {
        return 1.0;
    }
    $set = trim((string) SiteSetting::get('pricing_exchange_rate_'.$currency, ''));
    if ($set !== '' && (float) $set > 0) {
        return (float) $set;
    }
    $live = Cache::get('billing:usd_fx_rates:v3');

    return (float) ((is_array($live) ? ($live[$currency] ?? 0) : 0) ?: ($fallbackRates[$currency] ?? 0));
};

// A second, independent source, only to compare.
$second = [];
try {
    $response = Http::timeout(8)->acceptJson()->get('https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.json');
    foreach ((array) ($response->json('usd') ?? []) as $code => $rate) {
        $second[strtoupper((string) $code)] = (float) $rate;
    }
    echo 'Second rate source: ', count($second), ' currencies, dated ', (string) $response->json('date'), "\n";
} catch (Throwable $e) {
    echo "Second rate source: not reached.\n";
}

// Every country: the two-letter codes PHP knows a name for.
$countries = [];
foreach (range('A', 'Z') as $a) {
    foreach (range('A', 'Z') as $b) {
        $code = $a.$b;
        $name = Locale::getDisplayRegion('-'.$code, 'en');
        if ($name !== '' && $name !== $code && ! in_array($code, ['EU', 'EZ', 'UN', 'QO', 'ZZ', 'XA', 'XB', 'XK'], true)) {
            $countries[$code] = $name;
        }
    }
}
echo 'Countries checked: ', count($countries), "\n\n";

// A written price read back as a number, with the formatter that wrote it.
$readBack = function (string $written, string $currency, string $locale): ?float {
    $fmt = new NumberFormatter($locale === 'ar' ? ($currency === 'USD' ? 'en_US' : ($currency === 'EGP' ? 'ar_EG@numbers=latn' : 'ar@numbers=latn')) : 'en_US', NumberFormatter::CURRENCY);
    $clean = str_replace("\u{200F}", '', $written);
    $cur = '';
    $value = $fmt->parseCurrency($clean, $cur);
    if ($value === false) {
        // The number alone, when the currency's sign is not one the parser reads back.
        $digits = preg_replace('/[^0-9.]/', '', str_replace(',', '', $clean));

        return $digits === '' ? null : (float) $digits;
    }

    return (float) $value;
};

$problems = [];
$byCurrency = [];
$noRate = [];
$rateGaps = [];
$checked = 0;
foreach ($countries as $code => $name) {
    $request = Request::create('https://listen.example/pricing', 'GET', [], [], [], [
        'REMOTE_ADDR' => '198.51.100.20',
        'HTTP_CF_IPCOUNTRY' => $code,
    ]);
    $context = $pricing->resolveCountryContext($request);
    if (($context['country_code'] ?? '') !== $code && $forced === '') {
        $problems[] = "{$code}: taken for {$context['country_code']}";
    }
    $first = $pricing->convertedQuoteFromUsd($plans[0]['monthly'], 'monthly', $context, 'stripe', 'en');
    $currency = (string) $first['currency'];
    $wanted = (string) ($context['currency'] ?? '');
    if ($currency !== $wanted) {
        $noRate[$code] = "{$code} {$wanted}";
    }
    $rate = $rateOf($currency);
    $byCurrency[$currency]['countries'][] = $code;
    $byCurrency[$currency]['rate'] = $rate;
    if ($currency !== 'USD' && isset($second[$currency]) && $second[$currency] > 0 && $rate > 0) {
        $gap = $rate / $second[$currency] - 1;
        if (abs($gap) > 0.03) {
            $rateGaps[$currency] = sprintf('%s: the site uses %s, the second source %s (%+.1f%%)', $currency, rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.'), rtrim(rtrim(number_format($second[$currency], 4, '.', ''), '0'), '.'), 100 * $gap);
        }
    }
    foreach ($plans as $plan) {
        foreach (['monthly', 'yearly'] as $cycle) {
            $usd = $plan[$cycle];
            foreach (['en', 'ar'] as $locale) {
                $quote = $pricing->convertedQuoteFromUsd($usd, $cycle, $context, 'stripe', $locale);
                $checked++;
                $expected = $usd / 100 * $rate;
                $shown = $quote['amount_minor'] / 100;
                // Egyptian pounds are rounded up to whole pounds; everything else to the cent.
                $slack = $currency === 'EGP' ? 1.0 : max(0.011, $expected * 0.0005);
                if ($quote['base_usd_minor'] !== $usd) {
                    $problems[] = "{$code} {$plan['slug']} {$cycle}: dollar base {$quote['base_usd_minor']} for {$usd}";
                }
                if ($shown < $expected - 0.011 || $shown > $expected + $slack) {
                    $problems[] = sprintf('%s %s %s %s: shows %.2f %s, the dollar price times the rate is %.2f (%.2fx)', $code, $plan['slug'], $cycle, $locale, $shown, $currency, $expected, $expected > 0 ? $shown / $expected : 0);
                }
                $back = $readBack((string) $quote['amount_formatted'], $currency, $locale);
                // The written price may round to the currency's own digits (no cents for yen).
                $fmt = new NumberFormatter('en_US@currency='.$currency, NumberFormatter::CURRENCY);
                $unit = 10 ** -max(0, (int) $fmt->getAttribute(NumberFormatter::FRACTION_DIGITS));
                if ($back === null || abs($back - $shown) > max($unit, 0.011)) {
                    $problems[] = "{$code} {$plan['slug']} {$cycle} {$locale}: written \"{$quote['amount_formatted']}\", read back ".var_export($back, true).", the amount is {$shown}";
                }
                if ($cycle === 'yearly') {
                    $perMonth = $quote['equivalent_monthly_minor'];
                    if (abs($perMonth * 12 - $quote['amount_minor']) > 12) {
                        $problems[] = "{$code} {$plan['slug']} yearly {$locale}: {$perMonth} a month for {$quote['amount_minor']} a year";
                    }
                }
                if ($code === 'SA' || $code === 'YE' || $code === 'JP' || $code === 'KW' || $code === 'DE' || $code === 'MR') {
                    $byCurrency[$currency]['examples'][$plan['slug'].' '.$cycle.' '.$locale] = (string) $quote['amount_formatted'];
                }
            }
        }
    }
}

echo "== Currencies shown ==\n";
ksort($byCurrency);
foreach ($byCurrency as $currency => $row) {
    printf("  %s  rate %s  %d countries: %s\n", $currency, rtrim(rtrim(number_format($row['rate'], 4, '.', ''), '0'), '.'), count($row['countries']), implode(' ', $row['countries']));
}
echo "\n== Examples (Saudi Arabia, Yemen, Japan, Kuwait, Germany, Mauritania) ==\n";
foreach ($byCurrency as $currency => $row) {
    foreach ($row['examples'] ?? [] as $what => $written) {
        echo "  {$currency}  {$what}: {$written}\n";
    }
}
echo "\n== Countries shown in dollars because their currency has no rate ==\n";
echo $noRate === [] ? "  none\n" : '  '.implode(', ', $noRate)."\n";
echo "\n== Rates more than 3% from the second source ==\n";
echo $rateGaps === [] ? "  none\n" : '  '.implode("\n  ", $rateGaps)."\n";
echo "\n== Summary ==\n";
printf("Prices checked: %d (%d countries, %d plans, monthly and yearly, English and Arabic).\n", $checked, count($countries), count($plans));
printf("Problems: %d\n", count($problems));
foreach (array_slice($problems, 0, 60) as $p) {
    echo '  ', $p, "\n";
}
