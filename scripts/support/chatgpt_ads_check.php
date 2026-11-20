<?php

/*
 * For the "Server deploy" workflow's chatgpt-ads-check mode, on either site: reads,
 * in a READ ONLY database session, whether the ChatGPT Ads purchase events are on
 * (the switch, whether a pixel ID and a key are saved: never the key), the events of
 * the last 14 days and what OpenAI answered, and for one Stripe customer (input
 * "customer", optional) what the server kept from their checkout. Then the app log's
 * ChatGPT Ads lines of the last 3 days. Prints member ids only, never a name, e-mail
 * address or network address, and writes nothing.
 *
 *   ssh user@host 'cd ~/public_html && CUSTOMER=cus_... php' < scripts/support/chatgpt_ads_check.php
 */
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// An error anywhere stops the check as FAILED (left to Laravel, it would be shown and the run would still pass).
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, 'The check stopped on an error: '.$e::class.': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().")\nFAILED\n");
    exit(1);
});

if (in_array(Illuminate\Support\Facades\DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    Illuminate\Support\Facades\DB::statement('SET SESSION TRANSACTION READ ONLY');
}

use App\Models\ChatGptAdsEvent;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Billing\ChatGptAdsConversions;
use App\Support\Countries;

$ads = app(ChatGptAdsConversions::class);
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";

echo "== Settings (SEO Settings → ChatGPT Ads) ==\n";
echo '  switch on: ', (string) SiteSetting::get(ChatGptAdsConversions::ENABLED, '0') === '1' ? 'yes' : 'NO', "\n";
echo '  pixel ID: ', $ads->pixelId() !== '' ? $ads->pixelId() : 'NOT SAVED', "\n";
$stored = trim((string) SiteSetting::get(ChatGptAdsConversions::API_KEY, ''));
echo '  Conversions API key: ', $stored === '' ? 'NOT SAVED' : ($ads->apiKey() !== '' ? 'saved ('.strlen($ads->apiKey()).' characters)' : 'saved but cannot be read (APP_KEY changed?)'), "\n";
echo '  sending: ', $ads->enabled() ? 'on' : 'OFF (nothing is kept or sent)', "\n";

echo "== Events of the last 14 days ==\n";
$counts = $ads->counts(14);
echo '  ', $counts === [] ? 'none' : json_encode($counts), "\n";
$events = ChatGptAdsEvent::query()->where('created_at', '>=', now()->subDays(14))->latest('id')->limit(15)->get();
foreach ($events as $e) {
    $p = is_array($e->payload) ? $e->payload : [];
    echo '  ', json_encode([
        'id' => $e->id, 'member' => $e->user_id, 'source' => $e->source, 'reference' => $e->reference, 'status' => $e->status,
        'attempts' => $e->attempts, 'answer' => $e->response_status, 'error' => $e->error ? mb_substr($e->error, 0, 300) : null,
        'made' => (string) $e->created_at, 'sent' => (string) $e->sent_at,
        'from an ad click' => isset($p['oppref']), 'pixel browser id' => isset($p['user']['obref']),
        'plan' => $p['data']['plan_id'] ?? null,
    ], JSON_UNESCAPED_SLASHES), "\n";
}

$customer = trim((string) getenv('CUSTOMER'));
if ($customer !== '') {
    echo "== Customer {$customer} ==\n";
    $users = User::query()->where('stripe_id', $customer)->get();
    if ($users->isEmpty()) {
        echo "  no member has this Stripe customer\n";
    }
    foreach ($users as $u) {
        $country = strtoupper((string) $u->country_code);
        $context = is_array($u->chatgpt_ads_context) ? $u->chatgpt_ads_context : null;
        echo '  ', json_encode([
            'member' => $u->id, 'joined' => (string) $u->created_at,
            'country (from sign-in)' => $country ?: null,
            'consent rules apply there' => $country !== '' ? Countries::needsAdsConsent($country) : null,
            'checkout kept' => $context !== null,
            'checkout fields' => $context !== null ? array_keys($context) : null,
            'checkout at' => $context['captured_at'] ?? null,
            'plan' => $context['plan_id'] ?? null,
            'from an ad click' => $context !== null ? isset($context['oppref']) : null,
            'events' => ChatGptAdsEvent::query()->where('user_id', $u->id)->get(['id', 'reference', 'status', 'created_at'])->toArray(),
        ], JSON_UNESCAPED_SLASHES), "\n";
    }
}

echo "== App log: ChatGPT Ads lines, last 3 days ==\n";
$files = glob(storage_path('logs/*.log')) ?: [];
usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
$since = now()->subDays(3)->format('Y-m-d');
$found = 0;
foreach (array_slice($files, 0, 4) as $file) {
    $handle = @fopen($file, 'r');
    if (! $handle) {
        continue;
    }
    while (($line = fgets($handle)) !== false) {
        if (stripos($line, 'ChatGPT Ads') === false || substr($line, 1, 10) < $since) {
            continue;
        }
        // Never a network address or an e-mail address.
        $line = preg_replace(['/\b\d{1,3}(\.\d{1,3}){3}\b/', '/[^\s"@]+@[^\s"@]+/'], ['[ip]', '[email]'], $line);
        echo '  ', mb_substr(rtrim((string) $line), 0, 400), "\n";
        $found++;
    }
    fclose($handle);
}
if ($found === 0) {
    echo "  none\n";
}
