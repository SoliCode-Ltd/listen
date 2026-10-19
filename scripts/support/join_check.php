<?php

/*
 * For the "Server deploy" workflow's join-check mode, on either site: whether
 * a long premium voice, read in pieces and joined (GeminiTtsProvider,
 * SpeechJoin), sounds like one take. For the newest long premium voices it
 * finds the joins in the audio (the pause put between two pieces is pure
 * digital silence; a take's own pauses are not), then measures each piece:
 * its pace (syllable beats a second of speech), its pitch (the median of the
 * voice's fundamental) and its level. It compares the pieces of each voice
 * with one another, and with the two halves of one long piece, which shows
 * how much a voice varies within a single take. Reads, in a READ ONLY
 * database session, only the voices' settings and audio; prints numbers and
 * voice ids, never a text. Writes nothing.
 *
 *   ssh user@host 'cd ~/public_html && LIMIT=60 php' < scripts/support/join_check.php
 */

use App\Models\AudioGeneration;
use App\Support\AudioFormats;
use App\Support\GeminiVocalTags;
use App\Support\TextChunker;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}
ini_set('memory_limit', '1024M');
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";

$ffmpeg = AudioFormats::ffmpegPath();
if ($ffmpeg === null) {
    echo "No ffmpeg on this server.\n";
    exit(0);
}
$limit = max(1, min(200, (int) (getenv('LIMIT') ?: 60)));
$maxChars = max(200, (int) config('services.gemini_tts.max_chars_per_request', 900));
$parallel = max(1, (int) config('services.gemini_tts.max_parallel_requests', 4));
echo "Pieces of up to {$maxChars} characters, {$parallel} at a time.\n";

const RATE = 8000;

/** The decoded audio between two times, as mono 16-bit samples at 8 kHz. */
$decode = function (string $path, float $from, float $to) use ($ffmpeg): array {
    $process = new Process([$ffmpeg, '-hide_banner', '-nostats', '-loglevel', 'error', '-ss', sprintf('%.3f', $from), '-to', sprintf('%.3f', $to),
        '-i', $path, '-ac', '1', '-ar', (string) RATE, '-f', 's16le', '-']);
    $process->setTimeout(120);
    $process->run();

    return $process->isSuccessful() ? array_values(unpack('s*', $process->getOutput()) ?: []) : [];
};

/** Where the audio is pure digital silence for 90 ms or more: [start, end] in seconds, and the length. */
$silences = function (string $path) use ($ffmpeg): array {
    $process = new Process([$ffmpeg, '-hide_banner', '-nostats', '-i', $path, '-af', 'silencedetect=noise=-80dB:d=0.09', '-f', 'null', '-']);
    $process->setTimeout(300);
    $process->run();
    $log = $process->getErrorOutput();
    preg_match('/Duration: (\d+):(\d+):([\d.]+)/', $log, $d);
    $length = $d ? ((int) $d[1]) * 3600 + ((int) $d[2]) * 60 + (float) $d[3] : 0.0;
    preg_match_all('/silence_start: ([\d.]+)/', $log, $starts);
    preg_match_all('/silence_end: ([\d.]+)/', $log, $ends);
    $found = [];
    foreach ($starts[1] as $i => $start) {
        $found[] = [(float) $start, (float) ($ends[1][$i] ?? $length)];
    }

    return [$found, $length];
};

/**
 * One stretch of speech: its pace (syllable beats per second of speech, from
 * the peaks of its loudness), its pitch (median fundamental, Hz) and its level
 * (median loudness of its speech, dB).
 */
$measure = function (array $samples): ?array {
    $hop = (int) (RATE / 100); // 10 ms
    $frames = intdiv(count($samples), $hop);
    if ($frames < 300) {
        return null;
    }
    $power = [];
    $levels = [];
    for ($f = 0; $f < $frames; $f++) {
        $sum = 0.0;
        for ($i = $f * $hop, $end = $i + $hop; $i < $end; $i++) {
            $sum += $samples[$i] * $samples[$i];
        }
        $power[] = $sum / $hop;
        $levels[] = 10 * log10($sum / $hop + 1e-9);
    }
    $sorted = $levels;
    sort($sorted);
    $loud = $sorted[(int) floor(0.95 * (count($sorted) - 1))];
    $gate = $loud - 25;
    // Smoothed over 30 ms, so the beats are syllables, not single periods.
    $smooth = [];
    for ($f = 0; $f < $frames; $f++) {
        $from = max(0, $f - 1);
        $to = min($frames - 1, $f + 1);
        $smooth[] = ($levels[$from] + $levels[$f] + $levels[$to]) / 3;
    }
    $speech = 0;
    $energy = 0.0;
    $beats = 0;
    $low = INF;
    $last = -100;
    for ($f = 1; $f < $frames - 1; $f++) {
        if ($levels[$f] > $gate) {
            $speech++;
            $energy += $power[$f];
        }
        $low = min($low, $smooth[$f]);
        if ($smooth[$f] > $gate && $smooth[$f] >= $smooth[$f - 1] && $smooth[$f] > $smooth[$f + 1] && $smooth[$f] - $low >= 2 && $f - $last >= 8) {
            $beats++;
            $last = $f;
            $low = $smooth[$f];
        }
    }
    if ($speech < 200) {
        return null;
    }

    // Pitch: normalised autocorrelation over 50 ms windows of the louder speech.
    $window = 400;
    $pitches = [];
    $candidates = [];
    for ($f = 0; $f + 42 < $frames; $f += 3) {
        if ($levels[$f] > $loud - 15) {
            $candidates[] = $f;
        }
    }
    $step = max(1, intdiv(count($candidates), 200));
    for ($c = 0; $c < count($candidates); $c += $step) {
        $x = array_slice($samples, $candidates[$c] * $hop, $window);
        if (count($x) < $window) {
            break;
        }
        $mean = array_sum($x) / $window;
        foreach ($x as $i => $v) {
            $x[$i] = $v - $mean;
        }
        $best = 0.0;
        $bestLag = 0;
        $scores = [];
        for ($lag = 16; $lag <= 100; $lag++) { // 500 Hz down to 80 Hz
            $xy = $xx = $yy = 0.0;
            for ($i = 0, $n = $window - $lag; $i < $n; $i++) {
                $xy += $x[$i] * $x[$i + $lag];
                $xx += $x[$i] * $x[$i];
                $yy += $x[$i + $lag] * $x[$i + $lag];
            }
            $r = $xx > 0 && $yy > 0 ? $xy / sqrt($xx * $yy) : 0.0;
            $scores[$lag] = $r;
            if ($r > $best) {
                $best = $r;
                $bestLag = $lag;
            }
        }
        if ($best < 0.7) {
            continue;
        }
        // The shortest period nearly as strong: no octave below the voice.
        foreach ($scores as $lag => $r) {
            if ($lag < $bestLag && $r >= 0.9 * $best && ($scores[$lag - 1] ?? 0) <= $r && ($scores[$lag + 1] ?? 0) <= $r) {
                $bestLag = $lag;
                break;
            }
        }
        $a = $scores[$bestLag - 1] ?? $scores[$bestLag];
        $b = $scores[$bestLag];
        $c2 = $scores[$bestLag + 1] ?? $scores[$bestLag];
        $shift = ($a - 2 * $b + $c2) != 0 ? 0.5 * ($a - $c2) / ($a - 2 * $b + $c2) : 0.0;
        $pitches[] = RATE / ($bestLag + max(-0.5, min(0.5, $shift)));
    }
    if (count($pitches) < 10) {
        return null;
    }
    sort($pitches);

    return [
        'pace' => $beats / ($speech / 100),
        'pitch' => $pitches[intdiv(count($pitches), 2)],
        'level' => 10 * log10($energy / $speech + 1e-9),
        'seconds' => $frames / 100,
    ];
};

/** How far apart a voice's stretches are: pace as a ratio, pitch in semitones, level in dB. */
$spread = function (array $parts): ?array {
    if (count($parts) < 2) {
        return null;
    }
    $paces = array_column($parts, 'pace');
    $pitches = array_column($parts, 'pitch');
    $levels = array_column($parts, 'level');

    return [
        'pace' => max($paces) / max(0.01, min($paces)) - 1,
        'pitch' => 12 * log(max($pitches) / min($pitches), 2),
        'level' => max($levels) - min($levels),
    ];
};

$quantile = function (array $values, float $q): float {
    if ($values === []) {
        return NAN;
    }
    sort($values);
    $pos = ($q * (count($values) - 1));

    return $values[(int) floor($pos)] + ($values[(int) ceil($pos)] - $values[(int) floor($pos)]) * ($pos - floor($pos));
};

$voices = AudioGeneration::query()
    ->where('status', 'done')
    ->where('voice_tier', 'hd')
    ->where('tts_provider', 'gemini')
    ->where('is_dialogue', false)
    ->where('duration_seconds', '>=', 75)
    ->whereNotNull('audio_path')
    ->orderByDesc('id')
    ->limit($limit * 4)
    ->get(['id', 'input_text', 'audio_path', 'duration_seconds', 'settings_snapshot', 'speed', 'dialect', 'tts_model', 'created_at']);

$across = $within = [];
$joinsMatched = $joinsChecked = 0;
$rows = [];
foreach ($voices as $g) {
    if (count($rows) >= $limit) {
        break;
    }
    $snapshot = is_array($g->settings_snapshot) ? $g->settings_snapshot : [];
    // A sound environment fills the pauses: the joins can no longer be found.
    if (trim((string) ($snapshot['audio_effect'] ?? '')) !== '') {
        continue;
    }
    $path = Storage::disk('public')->path((string) $g->audio_path);
    if (! is_file($path)) {
        continue;
    }
    $expected = 0;
    foreach (GeminiVocalTags::parts((string) $g->input_text) as $part) {
        $expected += mb_strlen($part['text']) > $maxChars ? count(TextChunker::pieces($part['text'], $maxChars, 'mb_strlen', $parallel)) : 1;
    }
    if ($expected < 2) {
        continue;
    }

    [$quiet, $length] = $silences($path);
    // The joins: pure silence with sound on both sides.
    $joins = array_values(array_filter($quiet, fn ($s) => $s[0] > 0.5 && $s[1] < $length - 0.5));
    $joinsChecked++;
    $joinsMatched += count($joins) === $expected - 1 ? 1 : 0;

    $bounds = [0.0];
    foreach ($joins as $j) {
        $bounds[] = ($j[0] + $j[1]) / 2;
    }
    $bounds[] = $length;
    $pieces = [];
    for ($i = 0; $i + 1 < count($bounds); $i++) {
        $m = $measure($decode($path, $bounds[$i], $bounds[$i + 1]));
        if ($m !== null) {
            $pieces[] = $m;
            // The two halves of one long piece: how much a single take varies.
            if ($m['seconds'] >= 40) {
                $mid = ($bounds[$i] + $bounds[$i + 1]) / 2;
                $halves = array_filter([$measure($decode($path, $bounds[$i], $mid)), $measure($decode($path, $mid, $bounds[$i + 1]))]);
                if (($s = $spread(array_values($halves))) !== null) {
                    $within[] = $s;
                }
            }
        }
    }
    $s = $spread($pieces);
    if ($s === null) {
        continue;
    }
    $across[] = $s;
    $paces = array_column($pieces, 'pace');
    $pitches = array_column($pieces, 'pitch');
    $levels = array_column($pieces, 'level');
    $medianPace = $quantile($paces, .5);
    $medianPitch = $quantile($pitches, .5);
    $medianLevel = $quantile($levels, .5);
    $odd = [];
    foreach ($pieces as $k => $p) {
        $flags = [];
        if (abs($p['pace'] / $medianPace - 1) > 0.2) {
            $flags[] = sprintf('pace %+d%%', round(100 * ($p['pace'] / $medianPace - 1)));
        }
        if (abs(12 * log($p['pitch'] / $medianPitch, 2)) > 2) {
            $flags[] = sprintf('pitch %+.1f st', 12 * log($p['pitch'] / $medianPitch, 2));
        }
        if (abs($p['level'] - $medianLevel) > 3) {
            $flags[] = sprintf('level %+.1f dB', $p['level'] - $medianLevel);
        }
        if ($flags !== []) {
            $odd[] = 'piece '.($k + 1).': '.implode(', ', $flags);
        }
    }
    $rows[] = $odd;
    printf("  #%d %s, %.1f min, %d pieces expected, %d found; pace spread %d%%, pitch spread %.1f st, level spread %.1f dB%s\n",
        $g->id, (string) ($g->tts_model ?: '-'), $length / 60, $expected, count($joins) + 1, round(100 * $s['pace']), $s['pitch'], $s['level'],
        $odd === [] ? '' : ' | '.implode('; ', $odd));
}

echo "\n== Summary ==\n";
printf("Long premium voices measured: %d. Joins found as expected in %d of %d.\n", count($across), $joinsMatched, $joinsChecked);
foreach (['across the pieces of one voice' => $across, 'between the halves of one piece (one take)' => $within] as $label => $list) {
    if ($list === []) {
        continue;
    }
    printf("  %s (%d): pace spread median %d%% p90 %d%%; pitch spread median %.1f st p90 %.1f st; level spread median %.1f dB p90 %.1f dB\n", $label, count($list),
        round(100 * $quantile(array_column($list, 'pace'), .5)), round(100 * $quantile(array_column($list, 'pace'), .9)),
        $quantile(array_column($list, 'pitch'), .5), $quantile(array_column($list, 'pitch'), .9),
        $quantile(array_column($list, 'level'), .5), $quantile(array_column($list, 'level'), .9));
}
printf("Voices with a piece that stands out (pace over 20%%, pitch over 2 semitones or level over 3 dB from the voice's median): %d of %d.\n",
    count(array_filter($rows)), count($rows));
