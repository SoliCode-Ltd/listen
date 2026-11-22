<?php

/*
 * Read-only: the days with the most sales, from the site's own payment records (Billing → Payments:
 * card, Apple Pay, Google Pay, Link, InstaPay and Vodafone Cash). A sale is a payment with a paid
 * date; refunds are taken off its amount, and a payment refunded in full is not counted. Days are
 * Cairo days, the members' day the site uses for its daily limits. Amounts stay in their own
 * currency (never added across currencies). Prints counts and amounts only: no name, no email.
 * On MySQL the session is READ ONLY before any query.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/sales_days.php
 */

use App\Models\Payment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}

$zone = (string) config('listen.billing.daily_limit_timezone', 'Africa/Cairo');
$money = fn (int $minor, string $currency): string => number_format($minor / 100, 2).' '.strtoupper($currency);

$sales = Payment::query()
    ->whereNotNull('paid_at')
    ->get(['user_id', 'method', 'amount_minor', 'refunded_minor', 'currency', 'paid_at'])
    ->map(fn (Payment $p): array => [
        'day' => $p->paid_at->copy()->setTimezone($zone)->toDateString(),
        'user' => (int) $p->user_id,
        'method' => (string) $p->method,
        'net' => max(0, (int) $p->amount_minor - (int) $p->refunded_minor),
        'currency' => strtoupper((string) $p->currency),
    ])
    ->filter(fn (array $s): bool => $s['net'] > 0)
    ->values();

echo 'Now: '.now($zone)->toDateTimeString()." ({$zone})\n";
if ($sales->isEmpty()) {
    echo "No paid sales recorded.\n";
    exit(0);
}

$describe = function ($rows) use ($money): string {
    $amounts = $rows->groupBy('currency')->map(fn ($c, $cur) => $money($c->sum('net'), $cur))->implode(' + ');
    $methods = $rows->groupBy('method')->map(fn ($m, $k) => (Payment::METHODS[$k] ?? $k).' '.$m->count())->implode(', ');

    return $rows->count().' sales from '.$rows->pluck('user')->unique()->count().' members: '.$amounts.' ('.$methods.')';
};

echo "\n== All time ==\n  first sale ".$sales->min('day').', last '.$sales->max('day')."\n  ".$describe($sales)."\n";

$byDay = $sales->groupBy('day');
echo "\n== The 10 days with the most sales ==\n";
$byDay->sortByDesc(fn ($rows, $day) => sprintf('%06d %s', $rows->count(), $day))->take(10)
    ->each(fn ($rows, $day) => print('  '.$day.' ('.\Carbon\Carbon::parse($day)->format('l').'): '.$describe($rows)."\n"));

foreach ($sales->pluck('currency')->unique()->sort() as $currency) {
    echo "\n== The 5 days with the most money in {$currency} ==\n";
    $byDay->map(fn ($rows) => $rows->where('currency', $currency))->filter->isNotEmpty()
        ->sortByDesc(fn ($rows) => $rows->sum('net'))->take(5)
        ->each(fn ($rows, $day) => print('  '.$day.': '.$money($rows->sum('net'), $currency).' from '.$rows->count()." sales\n"));
}

echo "\n== The last 30 days, day by day ==\n";
for ($d = now($zone)->subDays(29)->startOfDay(); $d->lte(now($zone)); $d->addDay()) {
    $rows = $byDay->get($d->toDateString());
    echo '  '.$d->toDateString().' '.$d->format('D').': '.($rows ? $describe($rows) : 'none')."\n";
}

// Every day with a sale, for a chart: day, sales, then the amount in each currency (minor units).
$currencies = $sales->pluck('currency')->unique()->sort()->values();
echo "\n== Every day with a sale (csv) ==\n  day,sales,members,".$currencies->map(fn ($c) => strtolower($c).'_minor')->implode(',')."\n";
$byDay->sortKeys()->each(fn ($rows, $day) => print('  '.$day.','.$rows->count().','.$rows->pluck('user')->unique()->count().','
    .$currencies->map(fn ($c) => $rows->where('currency', $c)->sum('net'))->implode(',')."\n"));

echo "\nNothing was changed.\n";
