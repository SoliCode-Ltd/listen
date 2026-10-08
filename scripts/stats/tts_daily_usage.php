<?php

/*
 * Read-only: speech usage per day, counted the way the site-wide daily
 * limits (listen.cost_guardrails) count it, so the limits can be set above
 * real traffic.
 *
 * Prints one JSON document of aggregate numbers (no text, names or emails).
 * On MySQL the session is switched to READ ONLY before any query runs.
 *
 *   php scripts/stats/tts_daily_usage.php
 *   ssh user@host 'cd ~/public_html && USAGE_DAYS=90 php' < scripts/stats/tts_daily_usage.php
 *
 * Sources: tts_logs (one row per paid generation the speech service handled)
 * and demo_voice_generations. Cached audio ('cache' provider) costs nothing
 * and is left out, as the limits leave it out.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

ini_set('display_errors', 'stderr');

$root = getcwd();
if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "Run this from the Laravel app root (no artisan file in {$root}).\n");
    exit(1);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}

$daysBack = max(1, min(365, (int) (getenv('USAGE_DAYS') ?: 90)));
$since = Carbon::now()->subDays($daysBack - 1)->startOfDay();

$days = [];
$add = function (string $date, string $source, string $provider, int $chars, int $requests) use (&$days): void {
    $day = $days[$date] ??= [
        'date' => $date,
        'total_chars' => 0,
        'gemini_chars' => 0,
        'gemini_requests' => 0,
        'google_chars' => 0,
        'other_chars' => 0,
        'paid_chars' => 0,
        'demo_chars' => 0,
    ];

    $day['total_chars'] += $chars;
    $day[$source.'_chars'] += $chars;
    if ($provider === 'gemini') {
        $day['gemini_chars'] += $chars;
        $day['gemini_requests'] += $requests;
    } elseif ($provider === 'google_cloud') {
        $day['google_chars'] += $chars;
    } else {
        $day['other_chars'] += $chars;
    }

    $days[$date] = $day;
};

$notes = [];

// Paid generations: what the speech service was actually sent.
if (Schema::hasTable('tts_logs')) {
    DB::table('tts_logs')
        ->where('created_at', '>=', $since)
        ->whereIn('status', ['success', 'fallback'])
        ->where('provider', '!=', 'cache')
        ->selectRaw('date(created_at) as d, provider, coalesce(sum(char_count), 0) as chars, count(*) as n')
        ->groupBy('d', 'provider')
        ->get()
        ->each(fn ($r) => $add((string) $r->d, 'paid', (string) $r->provider, (int) $r->chars, (int) $r->n));
} else {
    $notes[] = 'tts_logs table missing: paid generations not counted';
}

// How much of the paid traffic tts_logs covers (a gap means the numbers above are low).
if (Schema::hasTable('audio_generations')) {
    $generated = DB::table('audio_generations')->where('created_at', '>=', $since)->where('status', 'done')->count();
    $logged = Schema::hasTable('tts_logs')
        ? DB::table('tts_logs')->where('created_at', '>=', $since)->whereIn('status', ['success', 'fallback'])->whereNotNull('audio_generation_id')->distinct()->count('audio_generation_id')
        : 0;
    $notes[] = "paid generations done: {$generated}; with a tts_logs row: {$logged}";
}

// Demo previews: speech_chars is what was spoken after any translation.
if (Schema::hasTable('demo_voice_generations')) {
    DB::table('demo_voice_generations')
        ->where('created_at', '>=', $since)
        ->select(['created_at', 'voice_provider', 'billable_chars', 'metadata'])
        ->orderBy('id')
        ->lazy(500)
        ->each(function ($r) use ($add): void {
            $provider = (string) ($r->voice_provider ?? 'unknown');
            if ($provider === 'cache') {
                return;
            }
            $meta = is_string($r->metadata) ? json_decode($r->metadata, true) : null;
            $chars = (int) ($meta['speech_chars'] ?? $r->billable_chars ?? 0);
            $add(Carbon::parse($r->created_at)->toDateString(), 'demo', $provider, $chars, 1);
        });
}

ksort($days);
$days = array_values($days);

$summarise = function (string $field) use ($days, $daysBack): array {
    $values = array_column($days, $field);
    // Days with no speech at all still count as zero.
    $values = array_merge($values, array_fill(0, max(0, $daysBack - count($values)), 0));
    sort($values);
    $n = count($values);
    $pick = fn (float $q) => $values[(int) min($n - 1, floor($q * ($n - 1)))];

    return [
        'max' => $n ? max($values) : 0,
        'p95' => $n ? $pick(0.95) : 0,
        'median' => $n ? $pick(0.5) : 0,
        'average' => $n ? (int) round(array_sum($values) / $n) : 0,
    ];
};

$busiest = $days;
usort($busiest, fn ($a, $b) => $b['total_chars'] <=> $a['total_chars']);

echo json_encode([
    'generated_at' => Carbon::now()->toIso8601String(),
    'timezone' => config('app.timezone'),
    'days_covered' => $daysBack,
    'since' => $since->toDateString(),
    'limits_now' => config('listen.cost_guardrails'),
    'guardrail_counters_today' => [
        'total_chars' => (int) Cache::get('tts:guardrail:'.now()->format('Ymd').':total_chars', 0),
        'gemini_chars' => (int) Cache::get('tts:guardrail:'.now()->format('Ymd').':gemini_chars', 0),
        'gemini_requests' => (int) Cache::get('tts:guardrail:'.now()->format('Ymd').':gemini_requests', 0),
    ],
    'summary' => [
        'total_chars' => $summarise('total_chars'),
        'gemini_chars' => $summarise('gemini_chars'),
        'gemini_requests' => $summarise('gemini_requests'),
        'google_chars' => $summarise('google_chars'),
        'demo_chars' => $summarise('demo_chars'),
    ],
    'busiest_days' => array_slice($busiest, 0, 10),
    'days' => $days,
    'notes' => $notes,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
