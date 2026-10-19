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
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";

// The site's own delivery tags, by the direction the voice is given; a
// member's own direction is counted as "custom", never shown.
$known = [];
foreach ((new ReflectionClassConstant(GeminiVocalTags::class, 'DELIVERY_DIRECTIONS'))->getValue() as $tag => $direction) {
    $known[$direction] = $tag;
}

/** What a text holds, as the speech pipeline reads it. */
$features = function (string $text) use ($known): array {
    $parts = GeminiVocalTags::parts($text, true);
    $spoken = '';
    $sounds = $short = $long = 0;
    $delivery = false;
    $moods = [];
    foreach ($parts as $part) {
        foreach ($part['cues'] ?? [] as $cue) {
            $moods[] = $known[$cue] ?? 'custom';
        }
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
        'moods' => array_values(array_unique($moods)),
        'digits' => preg_match_all('/\p{N}+/u', $spoken),
        // Vowel marks (tashkeel) are not letters, but a fully marked text may be read slower.
        'marks' => preg_match_all('/\p{Mn}/u', $spoken),
    ];
};

$rows = [];
$skipped = ['translated' => 0, 'dialogue' => 0, 'no words' => 0];
DB::table('audio_generations')
    ->select(['id', 'user_id', 'input_text', 'target_language', 'voice_tier', 'tts_provider', 'tts_model', 'speed', 'dialect', 'performance_style', 'emotion', 'style_prompt', 'translate', 'is_dialogue', 'duration_seconds', 'created_at'])
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
                'style' => (string) ($g->performance_style ?: 'neutral'),
                'emotion' => (string) ($g->emotion ?: 'neutral'),
                'prompt' => trim((string) $g->style_prompt) !== '',
                'id' => (int) $g->id,
                'user' => (int) $g->user_id,
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
// Fitted to the relative error (iteratively reweighted least squares for the
// median of |predicted - real| / real), so long voices do not drown out the
// short ones most members make, and a few odd voices do not pull the fit.
$fit = function (string $label, array $list, array $columns) use ($solve): ?array {
    // Columns that seldom occur in these voices are left out.
    $columns = array_values(array_filter($columns, fn ($c) => $c === 'one' || count(array_filter($list, fn ($r) => $r[$c] > 0)) >= 8));
    if (count($list) < max(20, count($columns) * 5)) {
        return null;
    }
    $x = fn ($r) => array_map(fn ($c) => $c === 'one' ? 1.0 : (float) $r[$c], $columns);
    $k = count($columns);
    $coef = null;
    $residual = [];
    for ($round = 0; $round < 25; $round++) {
        $ata = array_fill(0, $k, array_fill(0, $k, 0.0));
        $atb = array_fill(0, $k, 0.0);
        foreach ($list as $n => $r) {
            $v = $x($r);
            $y = (float) $r['seconds'];
            $w = 1 / ($y * $y);
            if ($coef !== null) {
                $w = 1 / ($y * max(abs($residual[$n]), 0.03 * $y));
            }
            for ($i = 0; $i < $k; $i++) {
                $atb[$i] += $w * $v[$i] * $y;
                for ($j = 0; $j < $k; $j++) {
                    $ata[$i][$j] += $w * $v[$i] * $v[$j];
                }
            }
        }
        for ($i = 0; $i < $k; $i++) {
            $ata[$i][$i] += $columns[$i] === 'one' ? 0.0 : 1e-6 * max(1e-9, $ata[$i][$i]);
        }
        $coef = $solve($ata, $atb);
        if ($coef === null) {
            echo "  {$label}: no fit\n";

            return null;
        }
        foreach ($list as $n => $r) {
            $residual[$n] = array_sum(array_map(fn ($a, $b) => $a * $b, $coef, $x($r))) - $r['seconds'];
        }
    }
    $model = array_combine($columns, $coef);
    printf("  %-34s %5d voices: %s\n", $label, count($list), implode(', ', array_map(fn ($c, $v) => $c.' '.number_format($v, 3), $columns, $coef)));

    return $model;
};
$check = function (string $label, array $list, callable $predict) use ($quantile, $fmt): void {
    if (count($list) < 5) {
        return;
    }
    $ratios = [];
    $over10 = $over1s = 0;
    foreach ($list as $r) {
        $p = max(1.0, $predict($r));
        $ratios[] = $r['seconds'] / $p;
        $over10 += $r['seconds'] > $p * 1.1 + 0.5 ? 1 : 0;
        $over1s += $r['seconds'] > $p + 1 ? 1 : 0;
    }
    printf("  %-34s %5d   real/estimate p10 %s p25 %s median %s p75 %s p90 %s   real over 10%% longer %4.1f%%, over 1 s longer %4.1f%%\n", $label, count($list),
        $fmt($quantile($ratios, .1)), $fmt($quantile($ratios, .25)), $fmt($quantile($ratios, .5)), $fmt($quantile($ratios, .75)), $fmt($quantile($ratios, .9)),
        100 * $over10 / count($list), 100 * $over1s / count($list));
};
$apply = fn (array $model) => fn (array $r): float => array_sum(array_map(fn ($c, $v) => $v * ($c === 'one' ? 1.0 : (float) $r[$c]), array_keys($model), $model));
$size = fn ($r) => $r['seconds'] < 10 ? 'a: < 10 s' : ($r['seconds'] < 30 ? 'b: 10-29 s' : ($r['seconds'] < 120 ? 'c: 30-119 s' : 'd: 120 s +'));

echo "\n== Fitted seconds at the normal pace, by voice tier and language ==\n";
$cols = ['letters', 'marks', 'digits', 'sounds', 'short', 'long', 'sentences', 'clauses', 'lines', 'joins', 'one'];
$normal = array_values(array_filter($rows, fn ($r) => abs($r['speed'] - 1.0) < 0.03));
$models = [];
foreach ($group($normal, fn ($r) => $r['tier'].' '.$r['lang']) as $k => $list) {
    $models[$k] = $fit($k, $list, $cols);
    $models[$k.' few'] = $fit($k.' few', $list, ['letters', 'sounds', 'sentences', 'lines', 'joins', 'one']);
}
foreach ($group(array_filter($normal, fn ($r) => $r['tier'] !== 'standard' && $r['model'] === 'gemini-3.8-flash-tts'), fn ($r) => $r['tier'].' '.$r['lang'].' 3.8') as $k => $list) {
    $models[$k] = $fit($k, $list, $cols);
}

echo "\n== How close each fit is (normal pace) ==\n";
foreach ($models as $k => $model) {
    if ($model === null) {
        continue;
    }
    [$tier, $lang] = explode(' ', $k);
    $list = array_filter($normal, fn ($r) => $r['tier'] === $tier && $r['lang'] === $lang && (! str_contains($k, '3.8') || $r['model'] === 'gemini-3.8-flash-tts'));
    $check($k, $list, $apply($model));
    if (! str_contains($k, ' few') && ! str_contains($k, '3.8')) {
        foreach ($group($list, $size) as $s => $part) {
            $check('   '.$s, $part, $apply($model));
        }
        foreach ($group($list, fn ($r) => ($r['sounds'] + $r['short'] + $r['long'] > 0 ? 'sounds' : 'no sounds').($r['joins'] > 0 ? ', moods' : '').($r['marks'] > $r['letters'] / 3 ? ', vowel-marked' : '')) as $s => $part) {
            $check('   '.$s, $part, $apply($model));
        }
    }
}
echo "\n== Today's estimate, same voices (normal pace) ==\n";
foreach ($group($normal, fn ($r) => $r['tier'].' '.$r['lang']) as $k => $list) {
    $check($k, $list, fn ($r) => (float) $r['estimate']);
}

echo "\n== The pace setting: real length against the normal-pace fit ==\n";
foreach ($group($rows, fn ($r) => $r['tier'].' '.$r['lang']) as $k => $list) {
    $model = $models[$k] ?? null;
    if ($model === null) {
        continue;
    }
    foreach ($group($list, fn ($r) => number_format($r['speed'], 2)) as $pace => $part) {
        if (count($part) >= 5) {
            $ratios = array_map(fn ($r) => $r['seconds'] / max(1.0, $apply($model)($r)), $part);
            printf("  %-14s pace %s %5d voices: real / fit p25 %s median %s p75 %s\n", $k, $pace, count($part), $fmt($quantile($ratios, .25)), $fmt($quantile($ratios, .5)), $fmt($quantile($ratios, .75)));
        }
    }
}

echo "\n== Voices much shorter than their text (real under 60% of the fit), normal pace ==\n";
foreach ($models as $k => $model) {
    if ($model === null || str_contains($k, ' few') || str_contains($k, '3.8')) {
        continue;
    }
    [$tier, $lang] = explode(' ', $k);
    $list = array_filter($normal, fn ($r) => $r['tier'] === $tier && $r['lang'] === $lang);
    $short = array_filter($list, fn ($r) => $r['seconds'] >= 3 && $r['seconds'] < 0.6 * $apply($model)($r));
    $by = array_count_values(array_map(fn ($r) => $r['engine'].' '.$r['model'].' '.$size($r), $short));
    ksort($by);
    printf("  %-14s %d of %d%s\n", $k, count($short), count($list), $by === [] ? '' : ': '.implode(', ', array_map(fn ($a, $b) => "{$a} {$b}", array_keys($by), $by)));
}

// What else makes a premium voice longer than its text: the studio's
// performance style and emotion, a written direction, moods in the text.
echo "\n== Premium voices at the normal pace: real against the fit, by studio setting ==\n";
foreach (['ar', 'en'] as $lang) {
    $model = $models['hd '.$lang] ?? null;
    if ($model === null) {
        continue;
    }
    $list = array_values(array_filter($normal, fn ($r) => $r['tier'] === 'hd' && $r['lang'] === $lang));
    $by = function (string $title, callable $key) use ($list, $model, $apply, $quantile, $fmt, $lang): void {
        $groups = [];
        foreach ($list as $r) {
            foreach ((array) $key($r) as $k) {
                $groups[$k][] = $r['seconds'] / max(1.0, $apply($model)($r));
            }
        }
        uasort($groups, fn ($a, $b) => count($b) <=> count($a));
        foreach (array_slice($groups, 0, 14, true) as $k => $ratios) {
            if (count($ratios) >= 4) {
                printf("  hd %s %-10s %-24s %5d voices: real / fit p25 %s median %s p75 %s\n", $lang, $title, $k, count($ratios), $fmt($quantile($ratios, .25)), $fmt($quantile($ratios, .5)), $fmt($quantile($ratios, .75)));
            }
        }
    };
    $by('style', fn ($r) => $r['style']);
    $by('emotion', fn ($r) => $r['emotion']);
    $by('direction', fn ($r) => $r['prompt'] ? 'written' : 'none');
    $by('mood', fn ($r) => $r['moods'] === [] ? ['none'] : $r['moods']);
    $by('dialect', fn ($r) => $r['dialect']);
}

// The site's own test voices (admin accounts), the newest first: what the
// fit says against the real length, and the settings, never the text.
$admins = DB::table('users')->where('is_admin', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
echo "\n== The newest voices made by admin accounts (for checking one by hand) ==\n";
$own = array_slice(array_reverse(array_values(array_filter($rows, fn ($r) => in_array($r['user'], $admins, true)))), 0, 10);
foreach ($own as $r) {
    $model = $models[$r['tier'].' '.$r['lang']] ?? null;
    printf("  #%d %s %s %s pace %.2f %s style %s emotion %s%s: letters %d, sounds %d, moods %s; fit %s s, today's estimate %d s, real %d s\n",
        $r['id'], $r['tier'], $r['lang'], $r['model'], $r['speed'], $r['dialect'], $r['style'], $r['emotion'], $r['prompt'] ? ', a written direction' : '',
        $r['letters'], $r['sounds'] + $r['short'] + $r['long'], $r['moods'] === [] ? 'none' : implode('+', $r['moods']),
        $model === null ? '-' : $fmt($apply($model)($r) / max(0.01, $r['speed'] < 1 ? $r['speed'] ** 0.7 : $r['speed'] ** 0.2), 1), $r['estimate'], $r['seconds']);
}
