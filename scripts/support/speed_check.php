<?php

/*
 * For the "Server deploy" workflow's speed-check mode, on either site: how long
 * a premium voice really takes to make, from the finished voices of the last 60
 * days, and so how long a text at each plan's longest allowed length would take
 * at today's pace. A long text is read in pieces (GeminiTtsProvider), a few at
 * a time, and joined; this shows the time per round of pieces, how much audio
 * a second of making gives, the longest voices made, the long voices that
 * failed, and how often a piece failed and was sent again (from the site's
 * log). In a READ ONLY database session; prints totals and voice ids only,
 * never a text, a member or a key. Writes nothing.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/speed_check.php
 */

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Services\Tts\GeminiKeyManager;
use App\Support\GeminiVocalTags;
use App\Support\TextChunker;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}
ini_set('memory_limit', '1024M');
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n\n";

$maxChars = max(200, (int) config('services.gemini_tts.max_chars_per_request', 900));
$parallel = max(1, (int) config('services.gemini_tts.max_parallel_requests', 4));
echo "== How a long premium voice is made ==\n";
printf("Model: %s. Pieces of up to %d characters, %d at a time; a piece waits up to %d s; %d key(s).\n",
    (string) config('services.gemini_tts.model'), $maxChars, $parallel, (int) config('services.gemini_tts.timeout_seconds', 60),
    count(app(GeminiKeyManager::class)->getAllKeys()));
printf("Made after the reply from %d characters; piece check %s.\n\n",
    (int) config('listen.audio.force_async_chars', 2500), config('services.gemini_tts.check_pieces', true) ? 'on' : 'off');

$quantile = function (array $values, float $q): float {
    if ($values === []) {
        return NAN;
    }
    sort($values);
    $pos = $q * (count($values) - 1);

    return $values[(int) floor($pos)] + ($values[(int) ceil($pos)] - $values[(int) floor($pos)]) * ($pos - floor($pos));
};

/** The pieces a text is read in, as GeminiTtsProvider cuts it. */
$piecesOf = function (string $text) use ($maxChars, $parallel): int {
    $n = 0;
    foreach (GeminiVocalTags::parts($text) as $part) {
        $n += mb_strlen($part['text']) > $maxChars ? count(TextChunker::pieces($part['text'], $maxChars, 'mb_strlen', $parallel)) : 1;
    }

    return max(1, $n);
};

$rows = [];
AudioGeneration::query()
    ->where('status', 'done')
    ->where('voice_tier', 'hd')
    ->where('tts_provider', 'gemini')
    ->where('is_dialogue', false)
    ->whereNotNull('tts_latency_ms')
    ->where('created_at', '>=', now()->subDays(60))
    ->select(['id', 'input_text', 'target_language', 'duration_seconds', 'tts_latency_ms', 'tts_model'])
    ->chunkById(500, function ($generations) use (&$rows, $piecesOf, $parallel): void {
        foreach ($generations as $g) {
            $text = (string) $g->input_text;
            $pieces = $piecesOf($text);
            $rows[] = [
                'id' => $g->id,
                'chars' => mb_strlen($text),
                'ar' => $g->target_language === 'ar',
                'pieces' => $pieces,
                'rounds' => (int) ceil($pieces / $parallel),
                'audio' => (float) $g->duration_seconds,
                'making' => $g->tts_latency_ms / 1000,
                'model' => (string) $g->tts_model,
            ];
        }
    });

echo '== Premium voices made in the last 60 days: ', count($rows), " ==\n";
printf("%-12s %6s %10s %10s %12s %12s %14s %16s\n", 'pieces', 'voices', 'chars', 'audio', 'making', 'making p90', 'per round', 'audio a second');
$perRound = [];
foreach ([[1, 1], [2, 4], [5, 8], [9, 16], [17, 32], [33, 64], [65, 100000]] as [$low, $high]) {
    $set = array_values(array_filter($rows, fn ($r) => $r['pieces'] >= $low && $r['pieces'] <= $high));
    if ($set === []) {
        continue;
    }
    $rounds = array_map(fn ($r) => $r['making'] / $r['rounds'], $set);
    if ($low > 1) {
        array_push($perRound, ...$rounds);
    }
    $speed = array_map(fn ($r) => $r['making'] > 0 ? $r['audio'] / $r['making'] : 0, $set);
    printf("%-12s %6d %10d %8.1f m %10.0f s %10.0f s %12.1f s %14.1f s\n",
        $low === $high ? (string) $low : ($high > 1000 ? "{$low}+" : "{$low}-{$high}"), count($set),
        $quantile(array_column($set, 'chars'), .5), $quantile(array_column($set, 'audio'), .5) / 60,
        $quantile(array_column($set, 'making'), .5), $quantile(array_column($set, 'making'), .9),
        $quantile($rounds, .5), $quantile($speed, .5));
}
echo "(medians; \"per round\" is the making time over the rounds of {$parallel} pieces; \"audio a second\" is the seconds of audio one second of making gave)\n\n";

$long = array_values(array_filter($rows, fn ($r) => $r['pieces'] > 1));
$charsPerAudioSecond = ['ar' => [], 'en' => []];
foreach ($rows as $r) {
    if ($r['audio'] > 5) {
        $charsPerAudioSecond[$r['ar'] ? 'ar' : 'en'][] = $r['chars'] / $r['audio'];
    }
}
$roundTime = $perRound === [] ? NAN : $quantile($perRound, .5);
$roundTime90 = $perRound === [] ? NAN : $quantile($perRound, .9);
echo "== A text at each plan's longest allowed length, at today's pace ==\n";
printf("Per round of %d pieces: median %.0f s, p90 %.0f s. Characters per second of audio: Arabic %.1f, English %.1f.\n",
    $parallel, $roundTime, $roundTime90, $quantile($charsPerAudioSecond['ar'], .5), $quantile($charsPerAudioSecond['en'], .5));
foreach (Plan::query()->orderBy('price_cents')->get() as $plan) {
    $limit = (int) $plan->max_chars_per_request;
    if ($limit <= 0) {
        continue;
    }
    $pieces = (int) ceil($limit / ($maxChars * 0.95));
    $rounds = (int) ceil($pieces / $parallel);
    printf("  %-12s %s up to %s characters: about %d pieces, %d rounds, %.0f min to make (p90 %.0f min); %.1f h of Arabic audio or %.1f h of English\n",
        $plan->slug, $plan->is_active ? '(on sale)' : '(off)', number_format($limit), $pieces, $rounds, $rounds * $roundTime / 60, $rounds * $roundTime90 / 60,
        $limit / max(1, $quantile($charsPerAudioSecond['ar'], .5)) / 3600, $limit / max(1, $quantile($charsPerAudioSecond['en'], .5)) / 3600);
}
echo "\n";

echo "== The longest premium voices made in the last 60 days ==\n";
usort($rows, fn ($a, $b) => $b['chars'] <=> $a['chars']);
foreach (array_slice($rows, 0, 12) as $r) {
    printf("  #%d %s, %s chars, %d pieces: %.1f min of audio made in %.1f min\n",
        $r['id'], $r['model'] ?: '-', number_format($r['chars']), $r['pieces'], $r['audio'] / 60, $r['making'] / 60);
}
echo "\n";

echo "== Long premium voices that failed in the last 60 days (2,500 characters or more) ==\n";
$failed = AudioGeneration::query()
    ->where('status', 'failed')
    ->where('voice_tier', 'hd')
    ->where('created_at', '>=', now()->subDays(60))
    ->whereRaw('CHAR_LENGTH(input_text) >= 2500')
    ->selectRaw('LEFT(error_message, 90) as reason, COUNT(*) as n, MAX(CHAR_LENGTH(input_text)) as longest')
    ->groupBy('reason')
    ->orderByDesc('n')
    ->limit(12)
    ->get();
if ($failed->isEmpty()) {
    echo "  none\n";
}
foreach ($failed as $f) {
    printf("  %d × (longest %s chars) %s\n", $f->n, number_format((int) $f->longest), $f->reason ?: '(no message)');
}
echo "\n";

echo "== Pieces that failed and were sent again, from the site's log (last 7 days) ==\n";
$counts = ['piece failed' => 0, 'of those HTTP 429 (a key at its limit)' => 0, 'of those timed out' => 0, 'long voices with pieces taken again (piece check)' => 0];
// Which limit a 429 named (per key, per project, per minute or per day), and which keys were set aside.
$limits = $keysAside = $asideAt = [];
foreach (glob(storage_path('logs/laravel*.log')) ?: [] as $file) {
    if (filemtime($file) < time() - 7 * 86400) {
        continue;
    }
    $handle = fopen($file, 'r');
    while ($handle && ($line = fgets($handle)) !== false) {
        if (str_contains($line, 'Gemini TTS piece failed.')) {
            $counts['piece failed']++;
            $counts['of those HTTP 429 (a key at its limit)'] += str_contains($line, 'HTTP 429') ? 1 : 0;
            $counts['of those timed out'] += preg_match('/timed out|cURL error 28/i', $line) ? 1 : 0;
            if (preg_match('/quota[_ ]?(?:metric|id)?[^a-z]*([A-Za-z_.\/-]*(?:PerMinute|PerDay|per_minute|per_day|requests_per)[A-Za-z_.\/-]*)/i', $line, $m)
                || preg_match('/(requests per (?:minute|day)[^"\\\\,.]{0,40})/i', $line, $m)) {
                $limits[$m[1]] = ($limits[$m[1]] ?? 0) + 1;
            } elseif (str_contains($line, 'HTTP 429')) {
                $named = '(not named) '.mb_substr((string) preg_replace('/^.*HTTP 429: /', '', $line), 0, 120);
                $limits[$named] = ($limits[$named] ?? 0) + 1;
            }
        }
        if (str_contains($line, 'Gemini key marked as exhausted') && preg_match('/"key":"([A-Z0-9_]+)"/', $line, $m)) {
            $keysAside[$m[1]] = ($keysAside[$m[1]] ?? 0) + 1;
            // When each was set aside: keys with their own limits are set aside at different times.
            $asideAt[] = substr($line, 1, 16).' '.$m[1];
        }
        $counts['long voices with pieces taken again (piece check)'] += str_contains($line, 'pieces of a long voice were taken again') ? 1 : 0;
    }
    $handle && fclose($handle);
}
foreach ($counts as $what => $n) {
    printf("  %s: %d\n", $what, $n);
}
arsort($limits);
foreach (array_slice($limits, 0, 6, true) as $what => $n) {
    printf("  limit named: %s (%d)\n", $what, $n);
}
ksort($keysAside);
foreach ($keysAside as $name => $n) {
    printf("  key set aside for the minute: %s, %d time(s)\n", $name, $n);
}
sort($asideAt);
foreach (array_slice($asideAt, -20) as $when) {
    echo "  set aside at {$when}\n";
}
