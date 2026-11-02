<?php

/*
 * Read-only: refits the premium voices' audio-length estimate (App\Support\UsageEstimator)
 * to the voices made on live with today's engine, and says how much closer it gets.
 *
 * Every number is fitted together, not one at a time: the rates for what a text holds,
 * and the factors for the studio's style, emotion, dialect and written direction, the
 * moods tagged in the text, and the pace. Fitted on half the voices (even ids) and judged
 * on the other half, against today's estimate on the same voices; the numbers to ship
 * are then fitted on all of them. Prints numbers only: never a text or a member's email.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/estimate_fit.php
 */

use App\Support\GeminiVocalTags;
use App\Support\UsageEstimator;
use App\Support\VoiceTier;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

ini_set('display_errors', 'stderr');
ini_set('memory_limit', '1024M');

$root = getcwd();
if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "Run this from the Laravel app root.\n");
    exit(1);
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";

$model = (string) (getenv('FIT_MODEL') ?: 'gemini-3.8-flash-tts');
$member = (int) getenv('MEMBER_ID');

// The site's delivery tags by the direction the voice is given; any other mood is "custom".
$tagOf = [];
foreach ((new ReflectionClassConstant(GeminiVocalTags::class, 'DELIVERY_DIRECTIONS'))->getValue() as $tag => $direction) {
    $tagOf[$direction] = $tag;
}

// ---------------------------------------------------------------- the voices
$rows = [];
DB::table('audio_generations')
    ->select(['id', 'user_id', 'input_text', 'voice_tier', 'tts_provider', 'tts_model', 'speed', 'dialect', 'performance_style', 'emotion', 'style_prompt', 'translate', 'is_dialogue', 'duration_seconds'])
    ->where('status', 'done')->where('duration_measured', true)->where('duration_seconds', '>', 0)
    ->where('tts_provider', 'gemini')->where('tts_model', $model)
    ->orderBy('id')
    ->chunkById(500, function ($chunk) use (&$rows, $tagOf): void {
        foreach ($chunk as $g) {
            if ($g->translate || $g->is_dialogue || VoiceTier::normalize((string) $g->voice_tier) === VoiceTier::STANDARD) {
                continue;
            }
            $text = trim((string) $g->input_text);
            $f = UsageEstimator::features($text);
            if ($f['arabic'] + $f['latin'] + $f['cjk'] === 0) {
                continue;
            }
            $moods = [];
            foreach (GeminiVocalTags::parts($text, true) as $part) {
                foreach ($part['cues'] ?? [] as $cue) {
                    $moods[] = $tagOf[$cue] ?? 'custom';
                }
            }
            $studio = ['performance_style' => (string) $g->performance_style, 'emotion' => (string) $g->emotion, 'dialect' => (string) $g->dialect, 'style_prompt' => (string) $g->style_prompt];
            $rows[] = [
                'id' => (int) $g->id,
                'user' => (int) $g->user_id,
                'lang' => $f['arabic'] >= $f['latin'] + $f['cjk'] ? 'ar' : 'en',
                'x' => $f,
                'y' => (float) $g->duration_seconds,
                'speed' => (float) ($g->speed ?: 1.0),
                'style' => $studio['performance_style'],
                'emotion' => $studio['emotion'],
                'dialect' => $studio['dialect'],
                'direction' => trim($studio['style_prompt']) !== '' ? 'written' : '',
                'moods' => array_values(array_unique($moods)),
                'today' => (float) UsageEstimator::estimate($text, (string) $g->voice_tier, (float) ($g->speed ?: 1.0), $studio)['seconds'],
            ];
        }
    });
echo 'Premium voices on '.$model.': '.count($rows).' (ar '.count(array_filter($rows, fn ($r) => $r['lang'] === 'ar')).', en '.count(array_filter($rows, fn ($r) => $r['lang'] === 'en')).")\n";
if (count($rows) < 100) {
    fwrite(STDERR, "Too few voices to fit.\n");
    exit(1);
}

// ---------------------------------------------------------------- helpers
$quantile = function (array $values, float $q): float {
    sort($values);
    $n = count($values);
    if ($n === 0) {
        return 0.0;
    }
    $pos = ($n - 1) * $q;
    $lo = (int) floor($pos);
    $hi = (int) ceil($pos);

    return $values[$lo] + ($values[$hi] - $values[$lo]) * ($pos - $lo);
};
$median = fn (array $v): float => $quantile($v, 0.5);
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
        if (abs($a[$p][$c]) < 1e-12) {
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

$families = ['style', 'emotion', 'dialect', 'direction'];
$columnsAll = ['arabic', 'latin', 'mark', 'number', 'sound', 'short_pause', 'long_pause', 'sentence', 'clause', 'line', 'mood'];

/** The seconds a fitted model gives a voice, before the margin. */
$predict = function (array $m, array $r, bool $withMoods = true): float {
    $beta = $m['beta'][$r['lang']];
    $s = $beta['base'];
    foreach ($r['x'] as $name => $count) {
        $s += ($beta[$name] ?? 0.0) * $count;
    }
    foreach (['style', 'emotion', 'dialect', 'direction'] as $family) {
        $s *= $m['factors'][$family][$r[$family]] ?? 1.0;
    }
    if ($withMoods) {
        foreach ($r['moods'] as $mood) {
            $s *= $m['factors']['mood'][$mood] ?? 1.0;
        }
    }
    if ($r['speed'] > 0 && abs($r['speed'] - 1.0) > 0.001) {
        $s *= $r['speed'] ** ($r['speed'] < 1 ? -$m['slower'] : -$m['faster']);
    }

    return max(0.3, $s);
};

/** Fits every number together on these voices. */
$fitAll = function (array $list, bool $withMoods, array $keep = []) use ($predict, $median, $solve, $families, $columnsAll): array {
    $m = ['beta' => [], 'factors' => ['style' => [], 'emotion' => [], 'dialect' => [], 'direction' => [], 'mood' => []], 'slower' => UsageEstimator::SLOWER_POWER, 'faster' => UsageEstimator::FASTER_POWER];
    foreach (['ar', 'en'] as $lang) {
        $m['beta'][$lang] = UsageEstimator::MODEL[VoiceTier::HD][$lang];
    }
    for ($round = 0; $round < 8; $round++) {
        // The rates, for each language, on what the factors leave: fitted to the relative error.
        foreach (['ar', 'en'] as $lang) {
            $mine = array_values(array_filter($list, fn ($r) => $r['lang'] === $lang));
            if (count($mine) < 30) {
                continue;
            }
            $kept = $keep[$lang] ?? [];
            $cols = array_values(array_filter($columnsAll, fn ($c) => ! in_array($c, $kept, true) && count(array_filter($mine, fn ($r) => $r['x'][$c] > 0)) >= 8));
            $cols[] = 'base';
            $dropped = [];
            // The seconds the kept rates give each voice, taken off before the rest is fitted.
            $keptPart = fn (array $r): float => array_sum(array_map(fn ($c) => ($m['beta'][$lang][$c] ?? 0.0) * $r['x'][$c], $kept));
            for ($attempt = 0; $attempt < count($cols); $attempt++) {
                $k = count($cols);
                $coef = null;
                $residual = [];
                for ($irls = 0; $irls < 15; $irls++) {
                    $ata = array_fill(0, $k, array_fill(0, $k, 0.0));
                    $atb = array_fill(0, $k, 0.0);
                    foreach ($mine as $n => $r) {
                        // What the factors make of one second of the plain rate.
                        $scale = $predict(['beta' => [$lang => ['base' => 1.0]]] + $m, ['x' => []] + $r, $withMoods);
                        $v = array_map(fn ($c) => $scale * ($c === 'base' ? 1.0 : (float) $r['x'][$c]), $cols);
                        $y = $r['y'];
                        $target = $y - $scale * $keptPart($r);
                        $w = $coef === null ? 1 / ($y * $y) : 1 / ($y * max(abs($residual[$n]), 0.03 * $y));
                        for ($i = 0; $i < $k; $i++) {
                            $atb[$i] += $w * $v[$i] * $target;
                            for ($j = 0; $j < $k; $j++) {
                                $ata[$i][$j] += $w * $v[$i] * $v[$j];
                            }
                        }
                    }
                    for ($i = 0; $i < $k; $i++) {
                        $ata[$i][$i] += 1e-6 * max(1e-9, $ata[$i][$i]);
                    }
                    $coef = $solve($ata, $atb);
                    if ($coef === null) {
                        break;
                    }
                    $trial = ['beta' => [$lang => array_merge(array_intersect_key($m['beta'][$lang], array_flip($kept)), array_combine($cols, $coef))]] + $m;
                    foreach ($mine as $n => $r) {
                        $residual[$n] = $predict($trial, $r, $withMoods) - $r['y'];
                    }
                }
                if ($coef === null) {
                    break;
                }
                $fitted = array_combine($cols, $coef);
                // A rate below nothing is not a rate: left out, and the rest fitted again.
                $negative = array_keys(array_filter($fitted, fn ($v, $c) => $c !== 'base' && $v < 0, ARRAY_FILTER_USE_BOTH));
                if ($negative === []) {
                    // What seldom occurs keeps today's rate; what the data puts below nothing is 0.
                    $m['beta'][$lang] = array_merge($m['beta'][$lang], array_fill_keys($dropped, 0.0), $fitted);
                    break;
                }
                $dropped[] = $negative[0];
                $cols = array_values(array_diff($cols, [$negative[0]]));
            }
        }

        // Each factor on what the rest leaves, pulled toward 1 by how few voices it rests on.
        $groups = $withMoods ? array_merge($families, ['mood']) : $families;
        foreach ($groups as $family) {
            $by = [];
            foreach ($list as $r) {
                $values = $family === 'mood' ? $r['moods'] : [$r[$family]];
                foreach ($values as $value) {
                    if ($value === '') {
                        continue;
                    }
                    $current = $m['factors'][$family][$value] ?? 1.0;
                    $by[$value][] = $r['y'] / ($predict($m, $r, $withMoods) / $current);
                }
            }
            $m['factors'][$family] = [];
            foreach ($by as $value => $ratios) {
                $n = count($ratios);
                $f = 1 + ($median($ratios) - 1) * $n / ($n + 10);
                if ($n >= 5 && abs($f - 1) >= 0.04) {
                    $m['factors'][$family][$value] = min(2.0, max(0.6, $f));
                }
            }
        }

        // The pace: the power that leaves the smallest miss on average (an average, not the
        // median: most paced voices are only a little slower or faster, and the few at 0.5
        // are the ones that show what the setting does).
        foreach (['slower' => fn ($r) => $r['speed'] < 0.999, 'faster' => fn ($r) => $r['speed'] > 1.001] as $which => $pick) {
            $paced = array_values(array_filter($list, $pick));
            if (count($paced) < 20) {
                continue;
            }
            $best = null;
            for ($p = 0.0; $p <= 1.0001; $p += 0.05) {
                $trial = array_merge($m, [$which => $p]);
                $misses = array_map(fn ($r) => abs(log($r['y'] / $predict($trial, $r, $withMoods))), $paced);
                $miss = array_sum($misses) / count($misses);
                if ($best === null || $miss < $best[1]) {
                    $best = [$p, $miss];
                }
            }
            $m[$which] = round($best[0], 2);
        }
    }

    return $m;
};

$score = function (string $label, array $list, callable $estimate) use ($quantile, $median): void {
    if (count($list) < 5) {
        return;
    }
    $ratios = [];
    $over = $over10 = $within = 0;
    $logs = [];
    foreach ($list as $r) {
        $e = max(1.0, (float) round($estimate($r)));
        $ratio = $r['y'] / $e;
        $ratios[] = $ratio;
        $over += $r['y'] > $e ? 1 : 0;
        $over10 += $r['y'] > $e * 1.1 + 0.5 ? 1 : 0;
        $within += abs($ratio - 1) <= 0.15 ? 1 : 0;
        $logs[] = abs(log($ratio));
    }
    $n = count($list);
    printf("  %-40s %5d  real/est p10 %.2f median %.2f p90 %.2f  real longer %4.1f%%  >10%% longer %4.1f%%  within 15%% %4.1f%%  typical miss %4.1f%%\n",
        $label, $n, $quantile($ratios, .1), $median($ratios), $quantile($ratios, .9), 100 * $over / $n, 100 * $over10 / $n, 100 * $within / $n, 100 * (exp($median($logs)) - 1));
};

// ---------------------------------------------------------------- fit on half, judge on the other half
$train = array_values(array_filter($rows, fn ($r) => $r['id'] % 2 === 0));
$test = array_values(array_filter($rows, fn ($r) => $r['id'] % 2 === 1));
echo 'Fitted on '.count($train).' voices, judged on '.count($test)." others\n";
$noMoods = $fitAll($train, false);
$withMoods = $fitAll($train, true);
// What too few voices hold to measure well keeps today's rate: sounds, pauses, digits,
// letters of the other script, vowel marks, and the extra takes a mood starts.
$cautious = ['ar' => ['latin', 'number', 'mark', 'sound', 'short_pause', 'long_pause', 'mood'], 'en' => ['arabic', 'number', 'mark', 'sound', 'short_pause', 'long_pause', 'mood']];
$careful = $fitAll($train, false, $cautious);

echo "\n== Judged on voices the fit did not see ==\n";
$score("today's estimate", $test, fn ($r) => $r['today']);
foreach ([1.05, 1.10, 1.15] as $margin) {
    $score(sprintf('refit, no mood factors, margin %.2f', $margin), $test, fn ($r) => $predict($noMoods, $r, false) * $margin);
}
foreach ([1.05, 1.10, 1.15] as $margin) {
    $score(sprintf('refit with mood factors, margin %.2f', $margin), $test, fn ($r) => $predict($withMoods, $r, true) * $margin);
}
foreach ([1.05, 1.10, 1.15] as $margin) {
    $score(sprintf('cautious refit, margin %.2f', $margin), $test, fn ($r) => $predict($careful, $r, false) * $margin);
}

echo "\n== By setting, judged on the other half: today, then the refit with moods (margin 1.10) ==\n";
$top = function (array $list, string $key, int $limit): array {
    $count = [];
    foreach ($list as $r) {
        foreach ($key === 'mood' ? ($r['moods'] ?: ['none']) : [$r[$key] !== '' ? $r[$key] : 'none'] as $v) {
            $count[$v] = ($count[$v] ?? 0) + 1;
        }
    }
    arsort($count);

    return array_slice(array_keys($count), 0, $limit);
};
foreach (['style' => 10, 'emotion' => 8, 'dialect' => 8, 'mood' => 8, 'direction' => 2] as $key => $limit) {
    foreach ($top($test, $key, $limit) as $value) {
        $part = array_values(array_filter($test, fn ($r) => $key === 'mood' ? in_array($value, $r['moods'] ?: ['none'], true) : (($r[$key] !== '' ? $r[$key] : 'none') === $value)));
        if (count($part) < 8) {
            continue;
        }
        $score("$key $value today", $part, fn ($r) => $r['today']);
        $score("$key $value refit", $part, fn ($r) => $predict($withMoods, $r, true) * 1.10);
    }
}

// ---------------------------------------------------------------- the numbers to ship, from every voice
// Without mood factors: on voices the fit did not see they add about a point, and the
// studio page would need its own copy of the parsing.
$final = $fitAll($rows, false, $cautious);
$counts = ['style' => [], 'emotion' => [], 'dialect' => [], 'direction' => []];
foreach ($rows as $r) {
    foreach (array_keys($counts) as $family) {
        $counts[$family][$r[$family]] = ($counts[$family][$r[$family]] ?? 0) + 1;
    }
}
// A setting seen on fewer than 5 of these voices keeps today's factor.
$today = UsageEstimator::STUDIO_FACTORS;
$names = ['style' => 'performance_style', 'emotion' => 'emotion', 'dialect' => 'dialect'];
$shipped = [];
foreach ($names as $family => $name) {
    $merged = [];
    foreach ($final['factors'][$family] as $value => $f) {
        $merged[$value] = round($f, 2);
    }
    foreach ($today[$name] as $value => $f) {
        if (($counts[$family][$value] ?? 0) < 5 && ! isset($merged[$value])) {
            $merged[$value] = $f;
        }
    }
    $merged = array_filter($merged, fn ($f) => abs($f - 1) >= 0.04);
    arsort($merged);
    $shipped[$name] = $merged;
}
$direction = round($final['factors']['direction']['written'] ?? 1.0, 2);
$shipModel = ['beta' => $final['beta'], 'factors' => ['style' => $shipped['performance_style'], 'emotion' => $shipped['emotion'], 'dialect' => $shipped['dialect'],
    'direction' => $direction !== 1.0 ? ['written' => $direction] : [], 'mood' => []], 'slower' => $final['slower'], 'faster' => $final['faster']];

echo "\n== To ship (fitted on all ".count($rows)." voices, no mood factors, the rare rates as today) ==\n";
foreach (['ar', 'en'] as $lang) {
    $b = $final['beta'][$lang];
    $order = ['arabic', 'latin', 'cjk', 'mark', 'number', 'sound', 'short_pause', 'long_pause', 'sentence', 'clause', 'line', 'mood', 'base'];
    echo "  '$lang' => [".implode(', ', array_map(fn ($c) => "'$c' => ".rtrim(rtrim(number_format((float) ($b[$c] ?? 0.0), 3, '.', ''), '0'), '.'), $order))."],\n";
}
foreach ($shipped as $name => $factors) {
    echo "  '$name' => [".implode(', ', array_map(fn ($v, $f) => "'$v' => $f", array_keys($factors), $factors))."],\n";
    echo '      seen: '.implode(', ', array_map(fn ($v) => $v.' '.($counts[array_search($name, $names, true)][$v] ?? 0), array_keys($factors)))."\n";
}
echo "  DIRECTION_FACTOR = $direction (written directions seen: ".($counts['direction']['written'] ?? 0).")\n";
echo '  SLOWER_POWER = '.$final['slower'].', FASTER_POWER = '.$final['faster']."\n";

echo "\n== The pace setting: real length against the shipped numbers without their pace factor ==\n";
$bucket = fn (float $speed): string => $speed < 0.55 ? '0.5' : ($speed < 0.95 ? '0.6-0.9' : ($speed <= 1.05 ? '1.0' : ($speed < 1.45 ? '1.1-1.4' : '1.5+')));
$byPace = [];
foreach ($rows as $r) {
    $byPace[$bucket($r['speed'])][] = $r['y'] / $predict(array_merge($shipModel, ['slower' => 0.0, 'faster' => 0.0]), $r, false);
}
ksort($byPace);
foreach ($byPace as $b => $ratios) {
    printf("  pace %-8s %4d voices: real / estimate p25 %.2f median %.2f p75 %.2f\n", $b, count($ratios), $quantile($ratios, .25), $median($ratios), $quantile($ratios, .75));
}

echo "\n== All voices, today against the numbers to ship (margin 1.10) ==\n";
$score("today's estimate", $rows, fn ($r) => $r['today']);
$score('to ship', $rows, fn ($r) => $predict($shipModel, $r, false) * 1.10);
$final = $shipModel;
$withMoods = false;

if ($member > 0) {
    $mine = array_values(array_filter($rows, fn ($r) => $r['user'] === $member));
    echo "\n== Member {$member}'s premium voices on this engine ==\n";
    printf("  voices %d, real %d s, today's estimate %d s, refit %d s\n", count($mine), array_sum(array_column($mine, 'y')),
        array_sum(array_column($mine, 'today')), (int) array_sum(array_map(fn ($r) => round($predict($final, $r, false) * 1.10), $mine)));
    foreach ($mine as $r) {
        printf("  #%d %s pace %.2f style %s emotion %s dialect %s moods %s: real %d s, today %d s, refit %d s\n", $r['id'], $r['lang'], $r['speed'],
            $r['style'] ?: '-', $r['emotion'] ?: '-', $r['dialect'] ?: '-', $r['moods'] ? implode('+', $r['moods']) : '-', $r['y'], $r['today'], round($predict($final, $r, false) * 1.10));
    }
}
