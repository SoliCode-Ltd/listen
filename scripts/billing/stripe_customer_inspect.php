<?php

/*
 * Read-only: why a customer's payment failed and whether Stripe will try
 * again. Uses the server's own Stripe key with GET requests only; nothing
 * is changed on Stripe or in the database. Card details: brand, last 4,
 * country and funding only. The workflow injects the customer id.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

ini_set('display_errors', 'stderr');
$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$customer = '__CUSTOMER__';
$secret = (string) config('services.stripe.secret');
if (! preg_match('/^cus_[A-Za-z0-9]+$/', $customer) || $secret === '') {
    fwrite(STDERR, "Missing customer id or Stripe key.\n");
    exit(1);
}
$get = fn (string $path, array $q = []) => Http::withToken($secret)->timeout(30)->get('https://api.stripe.com/v1/'.$path, $q)->json();
$pick = fn (?array $a, array $keys) => $a ? array_intersect_key($a, array_flip($keys)) : null;

$out = ['stripe_mode' => str_starts_with($secret, 'sk_live') || str_starts_with($secret, 'rk_live') ? 'live' : 'test'];
$out['customer'] = $pick($get("customers/{$customer}"), ['id', 'created', 'delinquent', 'currency']);

foreach (($get('subscriptions', ['customer' => $customer, 'status' => 'all', 'limit' => 5])['data'] ?? []) as $sub) {
    $out['subscriptions'][] = $pick($sub, ['id', 'status', 'created', 'trial_start', 'trial_end', 'current_period_end', 'cancel_at_period_end', 'collection_method', 'latest_invoice'])
        + ['price' => $sub['items']['data'][0]['price']['unit_amount'] ?? null, 'currency' => $sub['currency'] ?? null];
}

foreach (($get('invoices', ['customer' => $customer, 'limit' => 5])['data'] ?? []) as $inv) {
    $out['invoices'][] = $pick($inv, ['id', 'number', 'status', 'amount_due', 'currency', 'billing_reason', 'attempt_count', 'attempted', 'next_payment_attempt', 'created', 'collection_method', 'auto_advance']);
}

foreach (($get('charges', ['customer' => $customer, 'limit' => 5])['data'] ?? []) as $ch) {
    $card = $ch['payment_method_details']['card'] ?? [];
    $out['charges'][] = $pick($ch, ['id', 'amount', 'currency', 'status', 'created', 'failure_code', 'failure_message', 'description'])
        + ['outcome' => $ch['outcome'] ?? null,
           'card' => ['brand' => $card['brand'] ?? null, 'last4' => $card['last4'] ?? null, 'country' => $card['country'] ?? null, 'funding' => $card['funding'] ?? null,
                      'checks' => $card['checks'] ?? null, 'three_d_secure' => $card['three_d_secure'] ?? null],
           'billing_country' => $ch['billing_details']['address']['country'] ?? null,
           'ip_country' => null];
}

foreach (($get('payment_intents', ['customer' => $customer, 'limit' => 5])['data'] ?? []) as $pi) {
    $out['payment_intents'][] = $pick($pi, ['id', 'status', 'amount', 'currency', 'created', 'cancellation_reason'])
        + ['last_error' => $pick($pi['last_payment_error'] ?? null, ['code', 'decline_code', 'message', 'type'])];
}

$out['account_retry_note'] = 'Smart Retries / retry schedule is set in Stripe Dashboard > Billing > Revenue recovery; next_payment_attempt on the invoice is the next scheduled try (null = none).';

// The site's own record.
if (Schema::hasTable('subscriptions')) {
    $cols = Schema::getColumnListing('subscriptions');
    $q = DB::table('subscriptions');
    $match = in_array('stripe_customer_id', $cols, true) ? 'stripe_customer_id' : null;
    $rows = $match ? $q->where($match, $customer)->get() : collect();
    if ($rows->isEmpty() && Schema::hasColumn('users', 'stripe_id')) {
        $uid = DB::table('users')->where('stripe_id', $customer)->value('id');
        $rows = $uid ? DB::table('subscriptions')->where('user_id', $uid)->get() : collect();
    }
    $keep = array_values(array_intersect($cols, ['id', 'status', 'stripe_status', 'trial_ends_at', 'ends_at', 'current_period_end', 'plan_id', 'created_at', 'updated_at', 'provider', 'billing_status']));
    $out['site_subscriptions'] = $rows->map(fn ($r) => array_intersect_key((array) $r, array_flip($keep)))->all();
}

// Plans, the member's trial records, and what the server logged around the charge.
$out['plans'] = DB::table('plans')->select('id', 'slug', 'price_cents')->orderBy('id')->get()->all();
$userId = DB::table('users')->where('stripe_id', $customer)->value('id');
$out['user_id'] = $userId;
if ($userId && Schema::hasTable('billing_trials')) {
    $out['billing_trials'] = DB::table('billing_trials')->where('user_id', $userId)->get()->map(function ($r) {
        $r = (array) $r;
        foreach ($r as $k => $v) {
            if (is_string($v) && strlen($v) > 300) { $r[$k] = substr($v, 0, 300).'…'; }
        }
        return $r;
    })->all();
}
$charge = $out['charges'][0]['created'] ?? null;
$log = storage_path('logs/laravel.log');
$lines = [];
foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
    $fh = @fopen($file, 'r');
    if (! $fh) { continue; }
    while (($line = fgets($fh)) !== false) {
        if (! preg_match('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\]/', $line, $m)) { continue; }
        $t = strtotime($m[1].' UTC');
        if ($charge && abs($t - $charge) <= 900 && ($userId === null || preg_match('/user_id"?\s*[:=]\s*"?'.$userId.'\b|'.$customer.'|subscri|trial|stripe|billing/i', $line))) {
            $lines[] = basename($file).': '.mb_substr(trim($line), 0, 600);
        }
    }
    fclose($fh);
}
$out['app_log_near_charge'] = array_slice($lines, 0, 60);
// Web server access log (Cloudways keeps it next to the app), requests to billing in that window.
$access = [];
foreach (array_merge(glob(dirname($root).'/logs/*access*') ?: [], glob($root.'/../logs/*access*') ?: []) as $file) {
    $fh = @fopen($file, 'r');
    if (! $fh) { continue; }
    while (($line = fgets($fh)) !== false) {
        if (str_contains($line, '/billing') && $charge && preg_match('/\[(\d\d\/\w{3}\/\d{4}:\d\d:\d\d:\d\d [+-]\d{4})\]/', $line, $m)) {
            $t = strtotime(str_replace('/', ' ', preg_replace('/:/', ' ', $m[1], 1)));
            if ($t && abs($t - $charge) <= 900) {
                $access[] = basename($file).': '.preg_replace('/^\S+/', '[ip]', mb_substr(trim($line), 0, 400));
            }
        }
    }
    fclose($fh);
}
$out['access_log_billing_near_charge'] = array_slice($access, 0, 40);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
