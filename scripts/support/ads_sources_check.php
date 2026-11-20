<?php

/*
 * For the "Server deploy" workflow's ads-sources mode, on either site: where the paying
 * members came from, as the site itself recorded it. Read-only (READ ONLY database
 * session); prints counts and amounts only, never a name, e-mail address or member id.
 *
 * For each window (since ChatGPT Ads began sending on 18 November, the last 7 and the
 * last 30 days):
 *   - every payment the site recorded (card, phone wallet, by hand), renewals included;
 *   - new plans and plan changes (the purchases ChatGPT Ads is told of), and how many of
 *     them the buyer reached from a ChatGPT ad click (its click id with the purchase);
 *   - purchases from a Google Ads click (kept for the Google Ads upload: a new plan's
 *     first payment or a plan change, within 90 days of the click), and free trials
 *     started from a Google click;
 *   - new accounts, for scale.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/ads_sources_check.php
 */

use App\Models\AdsPurchase;
use App\Models\BillingTrial;
use App\Models\ChatGptAdsEvent;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

ini_set('display_errors', 'stderr');

$root = getcwd();
if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "Run this from the Laravel app root.\n");
    exit(1);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, 'The check stopped on an error: '.$e::class.': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().")\nFAILED\n");
    exit(1);
});

try {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
} catch (Throwable) {
    // Not a server that takes it: the script only reads all the same.
}

$line = fn (string $label, $value) => print '  '.str_pad($label, 52).(string) $value."\n";
$sums = function ($rows, string $amount, string $currency): string {
    $by = [];
    foreach ($rows as $row) {
        $code = strtoupper((string) ($row->{$currency} ?: 'USD'));
        $by[$code] = ($by[$code] ?? 0) + (int) $row->{$amount};
    }
    ksort($by);

    return $by === [] ? '-' : implode(', ', array_map(fn ($code, $minor) => number_format($minor / 100, 2).' '.$code, array_keys($by), $by));
};

echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";
$windows = [
    'Since ChatGPT Ads began (18 Nov)' => Carbon::parse('2026-11-18 00:00:00', 'UTC'),
    'Last 7 days' => now()->subDays(7),
    'Last 30 days' => now()->subDays(30),
];

foreach ($windows as $title => $from) {
    echo "\n== {$title}: from ".$from->utc()->format('Y-m-d H:i')." UTC ==\n";

    $line('New accounts', number_format(User::query()->where('created_at', '>=', $from)->count()));

    $payments = Payment::query()->where('paid_at', '>=', $from)->get(['amount_minor', 'refunded_minor', 'currency', 'method']);
    $line('Payments recorded (renewals included)', $payments->count().'  ('.$sums($payments, 'amount_minor', 'currency').')');
    $byMethod = $payments->groupBy('method')->map->count()->sortDesc();
    $line('  by way of paying', $byMethod->isEmpty() ? '-' : $byMethod->map(fn ($n, $m) => ($m ?: '?').' '.$n)->implode(', '));

    if (Schema::hasTable('chatgpt_ads_events') || Schema::hasTable((new ChatGptAdsEvent)->getTable())) {
        $events = ChatGptAdsEvent::query()->where('created_at', '>=', $from)->get(['payload', 'source']);
        $fromClick = $events->filter(fn ($e) => is_array($e->payload) && isset($e->payload['oppref']))->count();
        $line('New plans and plan changes (sent to ChatGPT Ads)', $events->count().'  (card '.$events->where('source', 'card')->count().', by hand '.$events->where('source', 'manual')->count().')');
        $line('  of them reached from a ChatGPT ad click', $fromClick);
    }

    $google = AdsPurchase::query()->where('purchased_at', '>=', $from)->get(['value_minor', 'currency', 'source']);
    $line('Purchases from a Google Ads click', $google->count().'  ('.$sums($google, 'value_minor', 'currency').')');
    $line('  card / by hand', $google->where('source', AdsPurchase::SOURCE_CARD)->count().' / '.$google->where('source', AdsPurchase::SOURCE_MANUAL)->count());

    $trials = BillingTrial::query()->where('started_at', '>=', $from);
    $line('Free trials started', (clone $trials)->count());
    $line('  of them from a Google Ads click', (clone $trials)->whereNotNull('gclid')->where('gclid', '!=', '')->count());
    $line('  of them now paid', (clone $trials)->where('status', BillingTrial::STATUS_PAID)->count());

    $line('Members at checkout with a Google click (90 days)', User::query()->where('ads_click_at', '>=', $from)->count());
}

echo "\nOK\n";
