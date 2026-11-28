<?php

/*
 * One-off, read-only, after deploying solicode-ltd/listen#419: the cancellations table is
 * there, and the log since the deploy has no error from the cancel flow. Prints log lines
 * only when they match the cancel flow or are errors, cut short, never secrets.
 */
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";
echo 'subscription_cancellations exists: ', Schema::hasTable('subscription_cancellations') ? 'yes' : 'NO', "\n";
echo 'migration recorded: ', DB::table('migrations')->where('migration', '2026_12_14_090000_create_subscription_cancellations_table')->exists() ? 'yes' : 'NO', "\n";
if (Schema::hasTable('subscription_cancellations')) {
    echo 'cancellations recorded so far: ', DB::table('subscription_cancellations')->count(), ' (with cancelled_at: ', DB::table('subscription_cancellations')->whereNotNull('cancelled_at')->count(), ")\n";
}
echo 'cancel requests to Stripe since 08:53 UTC (subscriptions newly set to end): ', DB::table('billing_subscriptions')->where('updated_at', '>=', '2026-11-28 08:53:00')->whereNotNull('ends_at')->count(), "\n";
$log = storage_path('logs/laravel.log');
echo "== log since 2026-11-28 08:53 UTC: errors, and anything from the cancel flow ==\n";
if (! is_file($log)) {
    echo "  no laravel.log\n";
} else {
    $size = filesize($log);
    $fh = fopen($log, 'r');
    fseek($fh, max(0, $size - 3000000));
    $chunk = stream_get_contents($fh);
    fclose($fh);
    $shown = 0; $errors = 0;
    foreach (preg_split('/\r?\n/', (string) $chunk) as $line) {
        if (! preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m) || $m[1] < '2026-11-28 08:53:00') {
            continue;
        }
        $isError = preg_match('/\.(ERROR|CRITICAL|ALERT|EMERGENCY):/', $line);
        $isCancel = preg_match('/SubscriptionCancellation|subscription_cancellations|CancellationEmail|cancellation email|billing\/cancel|cancel Stripe subscription|manage-subscription/i', $line);
        $errors += $isError ? 1 : 0;
        if (($isError || $isCancel) && $shown < 25) {
            $line = preg_replace('/(password|secret|token|key)[^,}]{0,80}/i', '$1=[hidden]', $line);
            echo '  ', mb_substr($line, 0, 260), "\n";
            $shown++;
        }
    }
    echo '  errors since the deploy: ', $errors, "\n";
}
