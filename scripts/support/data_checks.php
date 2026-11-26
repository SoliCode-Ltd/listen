<?php

/*
 * One-off, read-only (READ ONLY database session), run through the "Server deploy"
 * workflow's data-checks mode from this branch only: how long recent recordings took
 * from request to ready, and how the queue that makes them is running. Ids and
 * numbers only: no text, no member, nothing written.
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
echo '== Queue: connection ', config('queue.default'), ', retry_after ', config('queue.connections.'.config('queue.default').'.retry_after'), " ==\n";
if (Schema::hasTable('jobs')) {
    echo '  jobs waiting now: ', DB::table('jobs')->count(), ', oldest waiting (s): ', (int) (time() - (int) (DB::table('jobs')->min('available_at') ?: time())), "\n";
}
$ps = @shell_exec('ps -eo etimes,args 2>/dev/null | grep -E "queue:(work|listen)|horizon|schedule:(run|work)" | grep -v grep');
echo "  worker processes (seconds running, command):\n", $ps ? preg_replace('/^/m', '    ', trim($ps)) : '    none seen', "\n";
$cron = @shell_exec('crontab -l 2>/dev/null | grep -v "^#" | grep -i -E "artisan|queue|schedule"');
echo "  cron lines:\n", $cron ? preg_replace('/^/m', '    ', trim(preg_replace('/[A-Za-z0-9_]*(KEY|SECRET|TOKEN|PASSWORD)=\S+/i', '$1=[hidden]', $cron))) : '    none', "\n";

echo '== audio_generations columns: ', implode(', ', Schema::getColumnListing('audio_generations')), " ==\n";

$g = DB::table('audio_generations')->where('id', 9757)->first();
if ($g) {
    $snap = json_decode((string) ($g->settings_snapshot ?? ''), true) ?: [];
    echo '== Recording 9757: status ', $g->status, ', created ', $g->created_at, ', updated ', $g->updated_at,
        ', seconds between: ', strtotime((string) $g->updated_at) - strtotime((string) $g->created_at), ', tier ', $g->voice_tier ?? '?', " ==\n";
    echo '  snapshot keys: ', implode(', ', array_keys($snap)), "\n";
    foreach ($snap as $k => $v) {
        if (preg_match('/time|ms|second|attempt|retry|provider|model|rewrite|check|queue|wait|took|latency/i', (string) $k) && ! is_array($v)) {
            echo '  ', $k, ': ', is_scalar($v) ? substr((string) $v, 0, 80) : gettype($v), "\n";
        }
    }
}

echo "== Last 40 finished recordings: seconds from request to ready (id, source, tier, characters, audio seconds, took) ==\n";
$rows = DB::table('audio_generations as a')->leftJoin('text_items as t', 't.id', '=', 'a.text_item_id')
    ->where('a.status', 'done')->orderByDesc('a.id')->limit(40)
    ->get(['a.id', 'a.created_at', 'a.updated_at', 'a.voice_tier', 'a.billable_chars', 'a.duration_seconds', 't.source_type']);
$took = [];
foreach ($rows as $r) {
    $s = strtotime((string) $r->updated_at) - strtotime((string) $r->created_at);
    $took[] = $s;
    echo '  ', $r->id, ' ', $r->source_type ?? '-', ' ', $r->voice_tier ?? '-', ' chars ', (int) $r->billable_chars, ' audio ', (int) $r->duration_seconds, 's took ', $s, "s\n";
}
if ($took !== []) {
    sort($took);
    echo '  median ', $took[(int) floor(count($took) / 2)], 's, fastest ', $took[0], 's, slowest ', end($took), "s\n";
}
