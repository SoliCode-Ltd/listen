<?php
// Read-only: what Admin → Generation errors shows on LIVE, summarised.
// Member ids only; messages cut short. Writes nothing.
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
$db->statement('SET SESSION TRANSACTION READ ONLY');
$t = fn () => $db->table('generation_errors');
$cut = fn ($s, $n = 260) => mb_substr(preg_replace('/\s+/u', ' ', (string) $s), 0, $n);
echo 'Now (UTC): ', now()->toDateTimeString(), "\n";
echo 'Total rows: ', $t()->count(), ' · unresolved: ', $t()->whereNull('resolved_at')->count(), "\n";
foreach ([1, 3, 7, 30] as $d) {
    echo "Last {$d}d: rows ", $t()->where('last_seen_at', '>=', now()->subDays($d))->count(),
        ', occurrences ', (int) $t()->where('last_seen_at', '>=', now()->subDays($d))->sum('occurrences'), "\n";
}
echo "\n== Groups (last 14 days), most occurrences first ==\n";
$rows = $t()->where('last_seen_at', '>=', now()->subDays(14))
    ->selectRaw('source, severity, provider, voice_tier, exception_class, LEFT(message, 220) AS msg, SUM(occurrences) AS n, COUNT(*) AS r, COUNT(DISTINCT user_id) AS members, MIN(created_at) AS first, MAX(last_seen_at) AS last')
    ->groupBy('source', 'severity', 'provider', 'voice_tier', 'exception_class', 'msg')
    ->orderByDesc('n')->limit(40)->get();
foreach ($rows as $r) {
    echo "- [{$r->severity}] {$r->source} · {$r->provider} · {$r->voice_tier} · {$r->exception_class}\n";
    echo "  x{$r->n} ({$r->r} rows, {$r->members} members) {$r->first} → {$r->last}\n";
    echo '  ', $cut($r->msg), "\n";
}
echo "\n== Newest 25 ==\n";
foreach ($t()->orderByDesc('last_seen_at')->limit(25)->get() as $r) {
    $ctx = json_decode((string) $r->context, true) ?: [];
    unset($ctx['text'], $ctx['content'], $ctx['prompt']);
    echo "#{$r->id} {$r->last_seen_at} [{$r->severity}] {$r->source} member={$r->user_id} {$r->provider}/{$r->voice_tier} {$r->voice_name} {$r->target_language}/{$r->dialect} x{$r->occurrences}", $r->resolved_at ? ' RESOLVED' : '', "\n";
    echo '   ', $cut($r->exception_class.': '.$r->message, 400), "\n";
    if ($r->user_message) echo '   member saw: ', $cut($r->user_message, 200), "\n";
    if ($ctx) echo '   ctx: ', $cut(json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 400), "\n";
}
echo "\n== Audio generations failed per day (last 10 days) ==\n";
foreach ($db->table('audio_generations')->where('created_at', '>=', now()->subDays(10))
    ->selectRaw("DATE(created_at) d, SUM(status='failed') failed, SUM(status='done') ok, COUNT(*) total")
    ->groupBy('d')->orderBy('d')->get() as $r) {
    echo "  {$r->d}: failed {$r->failed} / done {$r->ok} / total {$r->total}\n";
}

echo "\n== Speech keys marked out (live cache) and today's key warnings in the logs ==\n";
$km = app(App\Services\Tts\GeminiKeyManager::class);
$model = App\Services\VoiceCloningService::MODEL;
try {
    $ref = new ReflectionClass($km);
    $keys = $km->getAllKeys();
    foreach ($keys as $k) {
        $name = is_array($k) ? ($k['name'] ?? '?') : (string) $k;
        echo "  key {$name}: out for today on {$model}=", $km->isOutForToday($name, (string) $model) ? 'YES' : 'no', "\n";
    }
} catch (Throwable $e) { echo '  (keys not listed: ', $e->getMessage(), ")\n"; }
$logs = glob(storage_path('logs/*.log')) ?: [];
usort($logs, fn ($a, $b) => filemtime($b) <=> filemtime($a));
$n = 0;
foreach (array_slice($logs, 0, 2) as $f) {
    foreach (file($f) ?: [] as $line) {
        if (str_starts_with($line, '['.now()->toDateString()) && preg_match('/daily limit for this model|spending cap|designed voice|Designed voice/i', $line)) {
            echo '  ', mb_substr(trim(preg_replace('/"text":"[^"]*"/', '"text":"…"', $line)), 0, 400), "\n";
            if (++$n >= 25) break 2;
        }
    }
}
if ($n === 0) echo "  none today\n";
