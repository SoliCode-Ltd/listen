<?php

/*
 * For the "Server deploy" workflow's speech-rates mode, on either site: how
 * long the voices really take to say a text, from the finished voices whose
 * audio length was measured, so the studio's "≈ 0:11 · 165 credits" can be
 * set from real audio. In a READ ONLY database session it reads each voice's
 * text, language, engine and pace, and the measured length, and prints only
 * totals: how far today's estimate (App\Support\UsageEstimator) is from the
 * real length, the speaking rates, and a least-squares fit of the seconds
 * against what the text holds (letters, words, sounds such as <laugh>, pauses,
 * sentence ends, line breaks, and the parts a delivery tag splits it into).
 * Never prints a text, a member or a voice; writes nothing.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/speech_rates.php
 */

use App\Support\GeminiVocalTags;
use App\Support\UsageEstimator;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";

/** What a text holds, as the speech pipeline reads it. */
$features = function (string $text): array {
    $parts = GeminiVocalTags::parts($text, true);
    $spoken = '';
    $sounds = $short = $long = 0;
    $delivery = false;
    foreach ($parts as $part) {
        preg_match_all('/<([a-z][a-z -]*)>/', (string) $part['text'], $m);
        foreach ($m[1] as $tag) {
            if ($tag === 'short pause') {
                $short++;
            } elseif ($tag === 'long pause') {
                $long++;
            } else {
                $sounds++;
            }
        }
        $spoken .= ' '.GeminiVocalTags::strip((string) $part['text']);
        $delivery = $delivery || ($part['cues'] ?? []) !== [];
    }
    $spoken = trim((string) preg_replace('/[ \t]+/u', ' ', $spoken));

    return [
        'letters' => preg_match_all('/[\p{L}\p{N}]/u', $spoken),
        'words' => preg_match_all('/[\p{L}\p{N}\']+/u', $spoken),
        'sounds' => $sounds,
        'short' => $short,
        'long' => $long,
        'sentences' => preg_match_all('/[.!?؟…]+/u', $spoken),
        'clauses' => preg_match_all('/[,،;؛:]/u', $spoken),
        'lines' => preg_match_all('/\n\s*/u', $spoken),
        'joins' => max(0, count($parts) - 1),
        'delivery' => $delivery,
        'digits' => preg_match_all('/\p{N}+/u', $spoken),
    ];
};

$rows = [];
$skipped = ['translated' => 0, 'dialogue' => 0, 'no words' => 0];
DB::table('audio_generations')
    ->select(['id', 'input_text', 'target_language', 'voice_tier', 'tts_provider', 'tts_model', 'speed', 'dialect', 'translate', 'is_dialogue', 'duration_seconds', 'created_at'])
    ->where('status', 'done')
    ->where('duration_measured', true)
    ->where('duration_seconds', '>', 0)
    ->orderBy('id')
    ->chunkById(500, function ($chunk) use (&$rows, &$skipped, $features): void {
        foreach ($chunk as $g) {
            // A translated voice reads a text that is not stored; a dialogue has several voices.
            if ($g->translate) {
                $skipped['translated']++;

                continue;
            }
            if ($g->is_dialogue) {
                $skipped['dialogue']++;

                continue;
            }
            $text = (string) $g->input_text;
            $f = $features($text);
            if ($f['words'] === 0) {
                $skipped['no words']++;

                continue;
            }
            $language = strtolower(substr((string) $g->target_language, 0, 2));
            $rows[] = $f + [
                'lang' => in_array($language, ['ar', 'en'], true) ? $language : 'other',
                'engine' => (string) ($g->tts_provider ?: 'unknown'),
                'tier' => (string) $g->voice_tier,
                'model' => (string) ($g->tts_model ?: '-'),
                'speed' => $g->speed === null ? 1.0 : (float) $g->speed,
                'dialect' => (string) ($g->dialect ?: '-'),
                'seconds' => (int) $g->duration_seconds,
                'estimate' => UsageEstimator::estimate($text)['seconds'],
                'days' => (int) now()->diffInDays($g->created_at, true),
            ];
        }
    });

echo 'Measured voices read: ', count($rows), ' (left out: ', implode(', ', array_map(fn ($k, $v) => "{$v} {$k}", array_keys($skipped), $skipped)), ")\n";
if ($rows === []) {
    exit(0);
}

$quantile = function (array $values, float $q): float {
    sort($values);
    $n = count($values);
    if ($n === 0) {
        return NAN;
    }
    $pos = ($n - 1) * $q;
    $lo = (int) floor($pos);
    $hi = (int) ceil($pos);

    return $values[$lo] + ($values[$hi] - $values[$lo]) * ($pos - $lo);
};
$fmt = fn (float $v, int $d = 2): string => is_nan($v) ? '-' : number_format($v, $d);
$group = function (array $rows, callable $key): array {
    $out = [];
    foreach ($rows as $r) {
        $out[$key($r)][] = $r;
    }
    ksort($out);

    return $out;
};

echo "\n== By engine and model ==\n";
foreach ($group($rows, fn ($r) => $r['engine'].' / '.$r['model']) as $k => $list) {
    printf("  %-48s %6d voices, newest %d days ago\n", $k, count($list), min(array_column($list, 'days')));
}

echo "\n== Today's estimate against the real length (real / estimate) ==\n";
echo "  group                                   voices  median   p25    p75    p90   real longer  total real s  total est s\n";
$report = function (string $label, array $list) use ($quantile, $fmt): void {
    $ratios = array_map(fn ($r) => $r['seconds'] / max(1, $r['estimate']), $list);
    $longer = count(array_filter($list, fn ($r) => $r['seconds'] > $r['estimate']));
    printf("  %-40s %6d  %6s %6s %6s %6s  %5.1f%%  %12d %12d\n", $label, count($list), $fmt($quantile($ratios, .5)), $fmt($quantile($ratios, .25)),
        $fmt($quantile($ratios, .75)), $fmt($quantile($ratios, .9)), 100 * $longer / max(1, count($list)),
        array_sum(array_column($list, 'seconds')), array_sum(array_column($list, 'estimate')));
};
$report('all', $rows);
foreach ($group($rows, fn ($r) => $r['engine'].' '.$r['lang']) as $k => $list) {
    $report($k, $list);
}
foreach ($group($rows, fn ($r) => $r['engine'].' '.$r['lang'].($r['sounds'] + $r['short'] + $r['long'] > 0 ? ' +sounds' : '').($r['joins'] > 0 ? ' +delivery split' : ($r['delivery'] ? ' +delivery' : ''))) as $k => $list) {
    if (count($list) >= 5 && (str_contains($k, '+'))) {
        $report($k, $list);
    }
}
foreach ($group($rows, fn ($r) => $r['engine'].' '.$r['lang'].' '.($r['seconds'] < 10 ? 'a: < 10 s' : ($r['seconds'] < 30 ? 'b: 10-29 s' : ($r['seconds'] < 120 ? 'c: 30-119 s' : 'd: 120 s +')))) as $k => $list) {
    $report($k, $list);
}

// Plain texts at the normal pace: the speaking rate itself.
$plain = array_values(array_filter($rows, fn ($r) => abs($r['speed'] - 1.0) < 0.03 && $r['sounds'] + $r['short'] + $r['long'] === 0 && ! $r['delivery'] && $r['seconds'] >= 5));
echo "\n== Speaking rate of plain texts at the normal pace, 5 s or longer ==\n";
echo "  group                                   voices  letters/s (p25 median p75)   words/s (p25 median p75)   letters a word\n";
$rates = function (string $label, array $list) use ($quantile, $fmt): void {
    if (count($list) < 3) {
        return;
    }
    $l = array_map(fn ($r) => $r['letters'] / $r['seconds'], $list);
    $w = array_map(fn ($r) => $r['words'] / $r['seconds'], $list);
    printf("  %-40s %6d     %5s %5s %5s            %5s %5s %5s          %5s\n", $label, count($list), $fmt($quantile($l, .25)), $fmt($quantile($l, .5)), $fmt($quantile($l, .75)),
        $fmt($quantile($w, .25)), $fmt($quantile($w, .5)), $fmt($quantile($w, .75)), $fmt(array_sum(array_column($list, 'letters')) / max(1, array_sum(array_column($list, 'words')))));
};
foreach ($group($plain, fn ($r) => $r['engine'].' '.$r['lang']) as $k => $list) {
    $rates($k, $list);
}
foreach ($group($plain, fn ($r) => $r['engine'].' '.$r['lang'].' '.$r['tier']) as $k => $list) {
    $rates($k, $list);
}
$byDialect = $group(array_filter($plain, fn ($r) => $r['lang'] === 'ar'), fn ($r) => $r['engine'].' ar '.$r['dialect']);
uasort($byDialect, fn ($a, $b) => count($b) <=> count($a));
foreach (array_slice($byDialect, 0, 12, true) as $k => $list) {
    $rates($k, $list);
}
foreach ($group($plain, fn ($r) => $r['engine'].' '.$r['lang'].' '.$r['model']) as $k => $list) {
    $rates($k, $list);
}

echo "\n== The pace setting (real length x pace / plain rate), all texts ==\n";
foreach ($group($rows, fn ($r) => $r['engine'].' '.$r['lang'].' pace '.number_format($r['speed'], 2)) as $k => $list) {
    if (count($list) >= 3) {
        $ratios = array_map(fn ($r) => $r['seconds'] / max(1, $r['estimate']), $list);
        printf("  %-40s %6d voices, real / estimate median %s\n", $k, count($list), $fmt($quantile($ratios, .5)));
    }
}

// Least squares: seconds = sum of coefficient x what the text holds.
$solve = function (array $a, array $b): ?array {
    $n = count($b);
    for ($i = 0; $i < $n; $i++) {
        $a[$i][] = $b[$i];
    }
    for ($c = 0; $c < $n; $c++) {
        $p = $c;
        for ($r = $c + 1; $r < $n; $r++) {
            if (abs($a[$r][$c]) > abs($a[$p][$c])) {
                $p = $r;
            }
        }
        if (abs($a[$p][$c]) < 1e-9) {
            return null;
        }
        [$a[$c], $a[$p]] = [$a[$p], $a[$c]];
        for ($r = 0; $r < $n; $r++) {
            if ($r !== $c) {
                $f = $a[$r][$c] / $a[$c][$c];
                for ($k = $c; $k <= $n; $k++) {
                    $a[$r][$k] -= $f * $a[$c][$k];
                }
            }
        }
    }

    return array_map(fn ($i) => $a[$i][$n] / $a[$i][$i], range(0, $n - 1));
};
$fit = function (string $label, array $list, array $columns) use ($solve, $quantile, $fmt): void {
    // Columns that never occur in these voices are left out.
    $columns = array_values(array_filter($columns, fn ($c) => $c === 'one' || count(array_filter($list, fn ($r) => $r[$c] > 0)) >= 3));
    if (count($list) < count($columns) * 4) {
        return;
    }
    $x = fn ($r) => array_map(fn ($c) => $c === 'one' ? 1.0 : (float) $r[$c], $columns);
    $k = count($columns);
    $ata = array_fill(0, $k, array_fill(0, $k, 0.0));
    $atb = array_fill(0, $k, 0.0);
    foreach ($list as $r) {
        $v = $x($r);
        // The length the text would have at the normal pace.
        $y = $r['seconds'] * $r['speed'];
        for ($i = 0; $i < $k; $i++) {
            $atb[$i] += $v[$i] * $y;
            for ($j = 0; $j < $k; $j++) {
                $ata[$i][$j] += $v[$i] * $v[$j];
            }
        }
    }
    // A touch of ridge, so two columns that always move together still solve.
    for ($i = 0; $i < $k; $i++) {
        $ata[$i][$i] += $columns[$i] === 'one' ? 0.0 : 1e-3;
    }
    $coef = $solve($ata, $atb);
    if ($coef === null) {
        echo "  {$label}: no fit\n";

        return;
    }
    $errors = [];
    $under = 0;
    foreach ($list as $r) {
        $predicted = array_sum(array_map(fn ($a, $b) => $a * $b, $coef, $x($r))) / $r['speed'];
        $errors[] = ($predicted - $r['seconds']) / $r['seconds'];
        $under += $predicted < $r['seconds'] - 0.5 ? 1 : 0;
    }
    $abs = array_map('abs', $errors);
    printf("  %-28s %6d voices: %s\n", $label, count($list), implode(', ', array_map(fn ($c, $v) => $c.' '.number_format($v, 4), $columns, $coef)));
    printf("  %-28s        error median %s%%, |error| median %s%% p90 %s%%, too short by over half a second: %.1f%%\n", '', $fmt(100 * $quantile($errors, .5), 1),
        $fmt(100 * $quantile($abs, .5), 1), $fmt(100 * $quantile($abs, .9), 1), 100 * $under / count($list));
};
echo "\n== Fitted seconds (at the normal pace) from what the text holds ==\n";
$cols = ['letters', 'words', 'sounds', 'short', 'long', 'sentences', 'clauses', 'lines', 'joins', 'digits', 'one'];
foreach ($group($rows, fn ($r) => $r['engine'].' '.$r['lang']) as $k => $list) {
    $fit($k, $list, $cols);
    $fit($k.' letters only', $list, ['letters', 'one']);
    $fit($k.' words only', $list, ['words', 'one']);
    $fit($k.' small', $list, ['letters', 'sounds', 'long', 'sentences', 'lines', 'joins', 'one']);
}
$recent = array_values(array_filter($rows, fn ($r) => $r['days'] <= 30));
foreach ($group($recent, fn ($r) => $r['engine'].' '.$r['lang'].' 30 days') as $k => $list) {
    $fit($k.' small', $list, ['letters', 'sounds', 'long', 'sentences', 'lines', 'joins', 'one']);
}
