<?php

/**
 * Step 1 of the automatic pronunciation feature, measured on text alone: no
 * speech is made and nothing on the site changes.
 *
 * For every sentence of the tashkeel test set a text model is asked which
 * words could be read wrongly and how each is said here
 * (App\Support\Tashkeel\WordReadings, the same code the feature will use).
 * What comes back is checked against the test set:
 *
 *   - the written homograph sentences, whose context decides the reading:
 *     is the marked word given the right reading, a wrong one, or left alone?
 *     The pass mark is no more than 3 in 100 given a wrong reading, because a
 *     voice already reads these right by itself and a wrong push would break
 *     them;
 *   - the written name sentences, the same way;
 *   - every word the model marks in any sentence, against the reference marks
 *     of that word (drafts until the owner approves them);
 *   - safety: no letter changed, and why entries of the model were refused.
 *
 * The model does not answer the same way every time (حمد came back as Ḥamad
 * in one run and ḥamd in another), so the written sentences are asked several
 * times (five unless ONLY carries runsN) and the pass mark has to hold in
 * every run; the words that did not come back the same are listed. One run
 * is never enough to decide a merge on.
 *
 * The test set and the two classes are not on the server (the branch is not
 * deployed): the workflow puts them in a private temporary folder (DIR) and
 * removes it afterwards. Sentences members wrote are sent to the same model
 * family that already reads members' text; the log shows them only as counts
 * and, for a word marked differently from its reference, that one word.
 *
 *   ssh user@host 'cd ~/public_html && DIR=/tmp/x ONLY=readings php' < scripts/support/tashkeel_readings.php
 *   ONLY=readings,runs3   three runs of the written sentences
 *   ONLY=readings,35      the model the site's dialect rewrite uses, instead of the default
 */

use App\Services\Tts\GeminiKeyManager;
use App\Support\Tashkeel\TashkeelScore;
use App\Support\Tashkeel\WordReadings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

ini_set('display_errors', 'stderr');
set_time_limit(2400);
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (str_contains(strtolower((string) config('app.url')), 'listenai.studio')) {
    fwrite(STDERR, "This is the live site: the tashkeel measurements run on staging only.\n");
    exit(1);
}

$dir = rtrim((string) getenv('DIR'), '/');
foreach (['TashkeelScore' => TashkeelScore::class, 'WordReadings' => WordReadings::class] as $file => $class) {
    if (! class_exists($class)) {
        require_once $dir.'/'.$file.'.php';
    }
}
$golden = json_decode((string) @file_get_contents($dir.'/golden.json'), true);
if (! is_array($golden) || ! isset($golden['items'])) {
    fwrite(STDERR, "The test set was not found in DIR.\n");
    exit(1);
}
$items = $golden['items'];
if ((int) getenv('LIMIT') > 0) {
    $items = array_slice($items, 0, (int) getenv('LIMIT'));
}

$only = array_map('trim', explode(',', (string) getenv('ONLY')));
$model = in_array('35', $only, true) ? 'gemini-3.5-flash' : (in_array('36', $only, true) ? 'gemini-3.6-flash' : 'gemini-3.8-flash');
$runs = 5;
foreach ($only as $token) {
    if (preg_match('/^runs(\d)$/', $token, $m)) {
        $runs = max(1, (int) $m[1]);
    }
}
$key = (string) (app(GeminiKeyManager::class)->getAvailableKey()['key'] ?? '');
$url = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/').'/models/'.$model.':generateContent';
$variety = ['egyptian' => 'Egyptian Arabic', 'levantine' => 'Levantine Arabic', 'gulf' => 'Gulf Arabic'];
$dialectOf = fn (array $item): ?string => $variety[$item['variety'] ?? ''] ?? null;
$body = fn (string $prompt, bool $thinking): array => [
    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
    'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0] + ($thinking ? ['thinkingConfig' => ['thinkingLevel' => 'low']] : []),
];
$answer = fn ($r): ?string => $r instanceof Response && $r->successful() ? (data_get($r->json(), 'candidates.0.content.parts.0.text') ?: null) : null;
$seconds = [];
$failed = 0;

// Ask for these sentences, six at a time; one that failed is asked once more, alone and without the thinking setting.
$ask = function (array $indices) use ($items, $dialectOf, $url, $key, $body, $answer, &$seconds, &$failed): array {
    $raw = [];
    foreach (array_chunk($indices, 6) as $batch) {
        try {
            $responses = Http::pool(function (Pool $pool) use ($batch, $items, $dialectOf, $url, $key, $body) {
                foreach ($batch as $i) {
                    $pool->as((string) $i)->timeout(60)->withHeaders(['x-goog-api-key' => $key])
                        ->post($url, $body(WordReadings::prompt($items[$i]['text'], $dialectOf($items[$i])), true));
                }
            });
        } catch (Throwable) {
            $responses = [];
        }
        foreach ($batch as $i) {
            $r = $responses[(string) $i] ?? null;
            $raw[$i] = $answer($r);
            if ($raw[$i] !== null && $r instanceof Response) {
                $seconds[] = (float) ($r->handlerStats()['total_time'] ?? 0);
            }
            if ($raw[$i] === null) {
                usleep(800000);
                try {
                    $again = Http::timeout(60)->withHeaders(['x-goog-api-key' => $key])
                        ->post($url, $body(WordReadings::prompt($items[$i]['text'], $dialectOf($items[$i])), false));
                    $raw[$i] = $answer($again);
                } catch (Throwable) {
                    $raw[$i] = null;
                }
                $failed += (int) ($raw[$i] === null);
            }
        }
    }

    return $raw;
};

$isWritten = fn (array $item): bool => ($item['source'] ?? '') === 'written' && isset($item['word'], $item['reading']);
$kindOf = fn (array $item): string => ($item['source'] ?? '') === 'written'
    ? (in_array('homograph', $item['tags'] ?? [], true) ? 'homograph' : 'name')
    : (string) ($item['variety'] ?? 'fusha');
$written = array_keys(array_filter($items, $isWritten));

$groups = [];        // run => group => verdict => count, for the marked word of the written sentences
$verdicts = [];      // item index => run => [verdict, what was got]
$flagged = [];       // variety => verdict => count, for every word the model marked (first run)
$perSentence = [];
$memberWords = [];
$otherWords = [];
$lettersChanged = 0;
$refused = [];
for ($run = 1; $run <= $runs; $run++) {
    $raw = $ask($run === 1 ? array_keys($items) : $written);
    foreach ($raw as $i => $text) {
        $item = $items[$i];
        $entries = $text === null ? [] : WordReadings::parse($text);
        $readings = WordReadings::accept($item['text'], $entries, [], $refused, $dialectOf($item) !== null);
        $made = WordReadings::apply($item['text'], $readings);
        $lettersChanged += (int) (TashkeelScore::strip($made['text']) !== TashkeelScore::strip($item['text']));
        $kind = $kindOf($item);

        // The marked word of a written sentence: right, wrong, unclear, or left alone.
        if ($isWritten($item)) {
            $verdict = 'left alone';
            $shown = 'the model listed: '.(implode('، ', array_map(fn (array $e): string => (string) ($e['word'] ?? '?'), $entries)) ?: 'nothing');
            foreach ($readings as $r) {
                if (TashkeelScore::strip($r['word']) === TashkeelScore::strip($item['word'])) {
                    $verdict = ['same' => 'right', 'different' => 'WRONG', 'unclear' => 'unclear'][TashkeelScore::reading($item['reading'], $r['marked'])];
                    $shown = 'got '.$r['marked'].' ('.$r['said'].'; '.$r['means'].'; '.$r['kind'].')';
                }
            }
            $groups[$run][$kind][$verdict] = ($groups[$run][$kind][$verdict] ?? 0) + 1;
            $verdicts[$i][$run] = [$verdict, $shown];
        }
        if ($run > 1) {
            continue;
        }

        // First run: every word the model marked, against the reference's marks on that word.
        $perSentence[$kind][] = count($readings);
        $refWords = TashkeelScore::words((string) ($item['ref'] ?? ''));
        foreach ($readings as $r) {
            $bare = TashkeelScore::words($r['marked'])[0]['bare'] ?? '';
            $ref = null;
            foreach ($refWords as $w) {
                if ($w['bare'] === $bare && $w['marked'] > 0) {
                    $ref = $w;
                    break;
                }
            }
            if ($ref === null) {
                $flagged[$kind]['no reference'] = ($flagged[$kind]['no reference'] ?? 0) + 1;

                continue;
            }
            $same = TashkeelScore::reading($ref['text'], $r['marked']);
            $flagged[$kind][$same] = ($flagged[$kind][$same] ?? 0) + 1;
            if ($same !== 'same') {
                $note = sprintf('  %-4s %-9s reference %s, model %s (%s; %s)', $item['id'], $same, $ref['text'], $r['marked'], $r['said'], $r['kind']);
                if (($item['source'] ?? '') === 'written') {
                    $otherWords[] = $note;
                } else {
                    $memberWords[] = $note;
                }
            }
        }
    }
}
sort($seconds);
$at = fn (float $p): float => $seconds === [] ? 0.0 : round($seconds[(int) floor($p * (count($seconds) - 1))], 1);

echo 'Now (UTC): ', now()->utc()->format('Y-m-d H:i:s'), "; model {$model}; ", count($items), ' sentences, the ', count($written), ' written ones asked ', $runs,
    ' times; no answer for ', $failed, '; one answer takes ', $at(0.5), ' s at the middle, ', $at(0.9), " s for the slowest tenth.\n\n";

$allPassed = true;
foreach (['homograph' => 'Written homograph sentences (the context decides the reading)', 'name' => 'Written name sentences'] as $kind => $title) {
    $n = array_sum($groups[1][$kind] ?? []);
    if ($n === 0) {
        continue;
    }
    echo $title, ': ', $n, "\n";
    $worst = 0.0;
    for ($run = 1; $run <= $runs; $run++) {
        $g = $groups[$run][$kind] ?? [];
        echo sprintf("  run %d: right %2d, WRONG %2d, unclear %2d, left alone %2d\n", $run, $g['right'] ?? 0, $g['WRONG'] ?? 0, $g['unclear'] ?? 0, $g['left alone'] ?? 0);
        $worst = max($worst, 100 * ($g['WRONG'] ?? 0) / $n);
    }
    if ($kind === 'homograph') {
        $allPassed = $worst <= 3;
        echo '  Pass mark (no more than 3% given a wrong reading, in every run): worst run ', round($worst, 1), '% -> ', $allPassed ? 'PASSED' : 'FAILED', "\n";
    } else {
        echo '  Worst run: ', round($worst, 1), "% given a wrong reading.\n";
    }
    echo "\n";
}

echo "Written sentences that did not come back the same in every run, or were never right:\n";
$any = false;
foreach ($verdicts as $i => $byRun) {
    $seen = array_unique(array_column($byRun, 0));
    if ($seen === ['right']) {
        continue;
    }
    $any = true;
    echo sprintf("  %-4s want %s%s: %s\n", $items[$i]['id'], $items[$i]['reading'], in_array('known-hard', $items[$i]['tags'] ?? [], true) ? ' [known hard]' : '',
        implode(' | ', array_map(fn (array $v): string => $v[0], $byRun)));
    foreach ($byRun as $run => [$verdict, $shown]) {
        if ($verdict !== 'right') {
            echo '         run ', $run, ': ', $shown, "\n";
        }
    }
}
echo $any ? '' : "  none\n";

echo "\nFirst run: every word the model marked, against the reference's marks on that word (member sentences: draft references):\n";
foreach ($flagged + array_fill_keys(array_keys($perSentence), []) as $kind => $f) {
    $n = array_sum($f);
    $counts = $perSentence[$kind];
    echo sprintf("  %-10s %3d sentences, %3d words marked (%.1f a sentence, most %d, none in %d): same %d, different %d, unclear %d, no reference %d\n",
        $kind, count($counts), $n, $n / max(1, count($counts)), max($counts), count(array_filter($counts, fn (int $c): bool => $c === 0)),
        $f['same'] ?? 0, $f['different'] ?? 0, $f['unclear'] ?? 0, $f['no reference'] ?? 0);
}
arsort($refused);
echo "\nSafety, all runs: sentences whose letters changed: ", $lettersChanged, '; entries of the model refused: ', array_sum($refused),
    $refused === [] ? '' : ' ('.implode(', ', array_map(fn (string $k, int $v): string => $k.' '.$v, array_keys($refused), $refused)).')', ".\n";

echo "\nWritten sentences, first run: other words marked differently from the reference:\n", $otherWords === [] ? "  none\n" : implode("\n", $otherWords)."\n";
echo "\nMember sentences, first run: words marked differently from the draft reference (the word only):\n", $memberWords === [] ? "  none\n" : implode("\n", $memberWords)."\n";

// A failed pass mark fails the run, so it cannot pass unnoticed.
exit($allPassed ? 0 : 1);
