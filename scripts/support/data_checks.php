<?php

/*
 * For the "Server deploy" workflow's data-checks mode, on either site: reads,
 * in a READ ONLY database session, the paid plans marked past due, the members
 * the wording rule blocked (paying ones first, with the words), how sign-ups
 * spread over network addresses (counts only, never an address), the speech
 * and credit settings, how the long texts of the last 90 days fared (counts
 * only, never a text) and how often PHP ran out of memory or time in the app's
 * logs. Prints member ids only, and writes nothing.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/data_checks.php
 */
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (in_array(Illuminate\Support\Facades\DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    Illuminate\Support\Facades\DB::statement('SET SESSION TRANSACTION READ ONLY');
}
$db = fn (string $t) => Illuminate\Support\Facades\DB::table($t);
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";

echo "== Paid plans marked past due (compare each with Stripe) ==\n";
$rows = $db('subscriptions')->where('status', 'active')->where('billing_payment_state', 'past_due')->orderBy('id')
    ->get(['id', 'user_id', 'plan_id', 'billing_grace_ends_at', 'current_period_end', 'updated_at']);
foreach ($rows as $r) { echo '  ', json_encode($r, JSON_UNESCAPED_SLASHES), "\n"; }
if ($rows->isEmpty()) { echo "  none\n"; }

echo "== Members blocked by the wording rule ==\n";
$wording = App\Models\User::query()->where('is_blocked', true)->whereNull('blocked_by')
    ->whereIn('blocked_reason', [App\Services\BlockedUserService::DEFAULT_REASON_EN, App\Services\BlockedUserService::DEFAULT_REASON_AR])
    ->orderBy('id')->get();
$paying = $wording->filter(fn ($u) => App\Support\PaidMember::is($u));
echo '  blocked by it: ', $wording->count(), ', of whom pay or have paid: ', $paying->count(), "\n";
if ($paying->isNotEmpty()) { echo '  paying member ids (unblock these first): ', $paying->pluck('id')->implode(', '), "\n"; }
if ($wording->isNotEmpty()) {
    echo '  member ids: ', $wording->pluck('id')->implode(', '), "\n";
    $terms = $db('blocked_generations')->whereIn('user_id', $wording->pluck('id'))->selectRaw('matched_term, count(*) as n')
        ->groupBy('matched_term')->orderByDesc('n')->limit(25)->get();
    echo '  words that refused their texts: ', $terms->map(fn ($t) => $t->matched_term.' ('.$t->n.')')->implode(', '), "\n";
}
$all = App\Models\User::query()->where('is_blocked', true)->count();
echo '  all blocked members: ', $all, ' (multi-account and admin blocks included)', "\n";

echo "== Sign-ups per network address (counts only, never an address) ==\n";
$total = $db('users')->whereNotNull('registration_ip')->where('registration_ip', '<>', '')->count();
$top = $db('users')->whereNotNull('registration_ip')->where('registration_ip', '<>', '')
    ->selectRaw('registration_ip, count(*) as n')->groupBy('registration_ip')->orderByDesc('n')->limit(5)->get();
$distinct = $db('users')->whereNotNull('registration_ip')->where('registration_ip', '<>', '')->distinct()->count('registration_ip');
echo '  members with a sign-up address: ', $total, ', different addresses: ', $distinct, "\n";
foreach ($top as $i => $t) {
    $public = filter_var($t->registration_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    printf("  #%d busiest address: %d sign-ups (%.1f%%), %s\n", $i + 1, $t->n, $total > 0 ? 100 * $t->n / $total : 0, $public ? 'a public address' : 'a PRIVATE or local address (the site sees its proxy)');
}

echo "== Settings ==\n";
foreach (['credit_periods_from', 'tts_mode', 'gemini_tts_model', 'demo_char_allowance'] as $key) {
    $value = $db('site_settings')->where('key', $key)->value('value');
    echo '  ', str_pad($key, 22), ($value === null || $value === '' ? '(not set)' : $value), "\n";
}

// Texts of 2,500+ characters are made after the reply, in the web server's PHP and its memory limit.
echo "== Long texts in the last 90 days (counts only, never a text) ==\n";
$swept = App\Support\GenerationMessage::stored('ui.backend.generation_stuck_refunded');
$long = $db('audio_generations')->where('created_at', '>=', now()->subDays(90))->whereRaw('CHAR_LENGTH(input_text) >= 2500')
    ->selectRaw("CASE WHEN CHAR_LENGTH(input_text) < 10000 THEN '2,500 to 9,999' WHEN CHAR_LENGTH(input_text) < 20000 THEN '10,000 to 19,999'
        WHEN CHAR_LENGTH(input_text) < 30000 THEN '20,000 to 29,999' WHEN CHAR_LENGTH(input_text) < 40000 THEN '30,000 to 39,999'
        ELSE '40,000 or more' END AS length_band, COUNT(*) AS made, SUM(status = 'done') AS done, SUM(status = 'failed') AS failed,
        SUM(status = 'failed' AND error_message = ?) AS stopped_as_stuck, SUM(status IN ('queued', 'processing')) AS unfinished,
        MAX(CASE WHEN status = 'done' THEN duration_seconds END) AS longest_audio_s,
        MAX(CASE WHEN status = 'done' THEN TIMESTAMPDIFF(SECOND, created_at, updated_at) END) AS longest_making_s,
        MAX(CHAR_LENGTH(input_text)) AS longest_text", [$swept])
    ->groupBy('length_band')->orderByRaw('MIN(CHAR_LENGTH(input_text))')->get();
foreach ($long as $r) { echo '  ', json_encode($r, JSON_UNESCAPED_SLASHES), "\n"; }
if ($long->isEmpty()) { echo "  none\n"; }
$days = $db('audio_generations')->where('created_at', '>=', now()->subDays(7))->whereRaw('CHAR_LENGTH(input_text) >= 2500')
    ->selectRaw("DATE(created_at) AS on_day, COUNT(*) AS made, SUM(status = 'done') AS done, SUM(status = 'failed') AS failed,
        SUM(status = 'failed' AND error_message = ?) AS stopped_as_stuck,
        MAX(CASE WHEN status = 'done' THEN TIMESTAMPDIFF(SECOND, created_at, updated_at) END) AS longest_making_s,
        MAX(CHAR_LENGTH(input_text)) AS longest_text", [$swept])
    ->groupBy('on_day')->orderBy('on_day')->get();
echo "  by day, last 7 days:\n";
foreach ($days as $r) { echo '    ', json_encode($r, JSON_UNESCAPED_SLASHES), "\n"; }
// A process that ran out of memory or time dies without a word: the log has the line.
echo "== PHP limit errors in the app's logs, last 14 days (counts only) ==\n";
$found = false;
foreach (glob('storage/logs/*.log') ?: [] as $file) {
    if (filemtime($file) < time() - 14 * 86400) { continue; }
    $counts = ['memory' => 0, 'time' => 0];
    $handle = fopen($file, 'r');
    while ($handle && ($line = fgets($handle)) !== false) {
        if (str_contains($line, 'Allowed memory size of')) { $counts['memory']++; }
        if (str_contains($line, 'Maximum execution time of')) { $counts['time']++; }
    }
    if ($handle) { fclose($handle); }
    if ($counts['memory'] + $counts['time'] > 0) {
        $found = true;
        echo '  ', basename($file), ': out of memory ', $counts['memory'], ', out of time ', $counts['time'], "\n";
    }
}
if (! $found) { echo "  none\n"; }
