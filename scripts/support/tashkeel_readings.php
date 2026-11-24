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
 *   - what the step takes each text to be written in (it is not told: it treats a
 *     text as Fusha only when most answers call it that), beside the test set's label;
 *   - safety: no letter changed, and why entries of the model were refused.
 *
 * The model does not answer the same way every time (حمد came back as Ḥamad
 * in one run and ḥamd in another; رفع was listed in two runs of a passage and
 * skipped in the third). So the step asks it several times about each text
 * (WordReadings::ASKS) and uses a word only when most answers agree on it, and
 * this test repeats the whole step several times (five unless ONLY carries
 * runsN) on the written sentences and on the longer passages of step 3: the
 * pass mark has to hold in every run, and the report says how steady the
 * choice of words was between runs, beside what one answer alone would have
 * given. One run is never enough to decide a merge on.
 *
 * The test set and the two classes are not on the server (the branch is not
 * deployed): the workflow puts them in a private temporary folder (DIR) and
 * removes it afterwards. Sentences members wrote are sent to the same model
 * family that already reads members' text; the log shows them only as counts
 * and, for a word marked differently from its reference, that one word.
 *
 *   ssh user@host 'cd ~/public_html && DIR=/tmp/x ONLY=readings php' < scripts/support/tashkeel_readings.php
 *   ONLY=readings,runs3   three runs of the written sentences and the passages
 *   ONLY=readings,labels  the step is told each text's variety from the test set, as before it judged for itself
 *   ONLY=readings,ptexts  the passages only (a quick look at the words marked in each)
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
set_time_limit(2600);
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
// The longer passages of step 3, as texts of their own: several open words in each.
foreach (json_decode((string) @file_get_contents($dir.'/passages.json'), true)['passages'] ?? [] as $p) {
    $items[] = ['id' => $p['id'], 'text' => $p['text'], 'ref' => $p['ref'], 'source' => 'passage'];
}

$only = array_map('trim', explode(',', (string) getenv('ONLY')));
if (in_array('ptexts', $only, true)) {
    $items = array_values(array_filter($items, fn (array $item): bool => ($item['source'] ?? '') === 'passage'));
}
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
// The step is not told what a text is written in (the site does not know: members write Egyptian for a Fusha voice):
// it decides from the answers. ONLY=readings,labels tells it the test set's label instead, as the first tests did.
$labels = in_array('labels', $only, true);
$dialectOf = fn (array $item): ?string => $labels ? ($variety[$item['variety'] ?? ''] ?? null) : null;
$judged = [];        // what the text is written in by its label => what the step judged (first run) => count
$judgedWords = [];   // the same => words marked there
$judgedOther = [];   // texts asked every run (all Fusha) that some run judged a dialect
$asks = WordReadings::ASKS;
// Each of the step's asks carries its own fixed seed, so the same ask of the same text has the best chance of the same answer.
$body = fn (string $prompt, ?int $k): array => [
    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
    'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0] + ($k !== null ? ['seed' => 101 + $k, 'thinkingConfig' => ['thinkingLevel' => 'low']] : []),
];
$answer = fn ($r): ?string => $r instanceof Response && $r->successful() ? (data_get($r->json(), 'candidates.0.content.parts.0.text') ?: null) : null;
$seconds = [];
$failed = 0;

// Ask about these texts, each as many times as the step does, eight requests at a time; one that failed is asked
// once more, alone and without the seed and the thinking setting. [text index => [ask => the answer or null]]
$ask = function (array $indices) use ($items, $dialectOf, $url, $key, $body, $answer, $asks, &$seconds, &$failed): array {
    $jobs = [];
    foreach ($indices as $i) {
        for ($k = 0; $k < $asks; $k++) {
            $jobs[] = [$i, $k];
        }
    }
    $raw = [];
    foreach (array_chunk($jobs, 8) as $batch) {
        try {
            $responses = Http::pool(function (Pool $pool) use ($batch, $items, $dialectOf, $url, $key, $body) {
                foreach ($batch as [$i, $k]) {
                    $pool->as($i.'.'.$k)->timeout(60)->withHeaders(['x-goog-api-key' => $key])
                        ->post($url, $body(WordReadings::prompt($items[$i]['text'], $dialectOf($items[$i])), $k));
                }
            });
        } catch (Throwable) {
            $responses = [];
        }
        foreach ($batch as [$i, $k]) {
            $r = $responses[$i.'.'.$k] ?? null;
            $raw[$i][$k] = $answer($r);
            if ($raw[$i][$k] !== null && $r instanceof Response) {
                $seconds[] = (float) ($r->handlerStats()['total_time'] ?? 0);
            }
            if ($raw[$i][$k] === null) {
                usleep(800000);
                try {
                    $again = Http::timeout(60)->withHeaders(['x-goog-api-key' => $key])
                        ->post($url, $body(WordReadings::prompt($items[$i]['text'], $dialectOf($items[$i])), null));
                    $raw[$i][$k] = $answer($again);
                } catch (Throwable) {
                    $raw[$i][$k] = null;
                }
                $failed += (int) ($raw[$i][$k] === null);
            }
        }
    }

    return $raw;
};

$isWritten = fn (array $item): bool => ($item['source'] ?? '') === 'written' && isset($item['word'], $item['reading']);
$kindOf = fn (array $item): string => ($item['source'] ?? '') === 'written'
    ? (in_array('homograph', $item['tags'] ?? [], true) ? 'homograph' : 'name')
    : (($item['source'] ?? '') === 'passage' ? 'passage' : (string) ($item['variety'] ?? 'fusha'));
$isPassage = fn (array $item): bool => ($item['source'] ?? '') === 'passage';
$written = array_keys(array_filter($items, $isWritten));
// Asked in every run: the written sentences and the passages.
$repeated = array_keys(array_filter($items, fn (array $item): bool => $isWritten($item) || $isPassage($item)));

$groups = [];        // run => group => verdict => count, for the marked word of the written sentences
$verdicts = [];      // item index => run => [verdict, what was got]
$flagged = [];       // variety => verdict => count, for every word the model marked (first run)
$perSentence = [];
$memberWords = [];
$otherWords = [];
$lettersChanged = 0;
$refused = [];
$marks = [];         // 'agreed' | 'one' => item index => run => [the word => how it was marked]
$passageMarks = [];  // run => verdict => count, for every word marked in a passage
for ($run = 1; $run <= $runs; $run++) {
    $raw = $ask($run === 1 ? array_keys($items) : $repeated);
    foreach ($raw as $i => $answers) {
        $item = $items[$i];
        $given = array_values(array_filter($answers, 'is_string'));
        $entries = array_merge(...array_map(fn (string $a): array => WordReadings::parse($a), $given), ...[[]]);
        $readings = WordReadings::agreed($item['text'], $given, [], $refused, $dialectOf($item) !== null);
        // What the first answer alone would have given: the step as it was before it asked several times.
        $unused = [];
        $one = WordReadings::agreed($item['text'], array_slice($given, 0, 1), [], $unused, $dialectOf($item) !== null);
        foreach (['agreed' => $readings, 'one' => $one] as $how => $list) {
            $marks[$how][$i][$run] = array_column($list, 'marked', 'word');
        }
        $made = WordReadings::apply($item['text'], $readings);
        // What the step took the text to be written in, beside the test set's label.
        $in = WordReadings::writtenIn($given) ?? 'not said';
        $label = ($item['source'] ?? '') === 'written' || $isPassage($item) ? 'fusha (written for the test)' : (string) ($item['variety'] ?? 'fusha');
        if ($run === 1) {
            $judged[$label][$in] = ($judged[$label][$in] ?? 0) + 1;
            $judgedWords[$label][$in] = ($judgedWords[$label][$in] ?? 0) + count($readings);
        }
        if ($in !== 'fusha' && (($item['source'] ?? '') === 'written' || $isPassage($item))) {
            $judgedOther[$item['id']][] = $run;
        }
        $lettersChanged += (int) (TashkeelScore::strip($made['text']) !== TashkeelScore::strip($item['text']));
        $kind = $kindOf($item);

        // The marked word of a written sentence: right, wrong, unclear, or left alone.
        if ($isWritten($item)) {
            $verdict = 'left alone';
            $shown = 'the answers listed: '.(implode('، ', array_unique(array_map(fn (array $e): string => (string) ($e['word'] ?? '?'), $entries))) ?: 'nothing');
            foreach ($readings as $r) {
                if (TashkeelScore::strip($r['word']) === TashkeelScore::strip($item['word'])) {
                    $verdict = ['same' => 'right', 'different' => 'WRONG', 'unclear' => 'unclear'][TashkeelScore::reading($item['reading'], $r['marked'])];
                    $shown = 'got '.$r['marked'].' ('.$r['said'].'; '.$r['means'].'; '.$r['kind'].')';
                }
            }
            $groups[$run][$kind][$verdict] = ($groups[$run][$kind][$verdict] ?? 0) + 1;
            $verdicts[$i][$run] = [$verdict, $shown];
        }
        if ($run > 1 && ! $isPassage($item)) {
            continue;
        }

        // Every word the step marked, against the reference's marks on that word: all sentences in the first run, the passages in every run.
        if ($run === 1) {
            $perSentence[$kind][] = count($readings);
        }
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
            if ($isPassage($item)) {
                $v = $ref === null ? 'no reference' : TashkeelScore::reading($ref['text'], $r['marked']);
                $passageMarks[$run][$v] = ($passageMarks[$run][$v] ?? 0) + 1;
                if ($v !== 'same') {
                    $otherWords[] = sprintf('  %-4s run %d %-9s reference %s, step %s (%s)', $item['id'], $run, $v, $ref['text'] ?? '-', $r['marked'], $r['said']);
                }
            }
            if ($run > 1) {
                continue;
            }
            if ($ref === null) {
                $flagged[$kind]['no reference'] = ($flagged[$kind]['no reference'] ?? 0) + 1;

                continue;
            }
            $same = TashkeelScore::reading($ref['text'], $r['marked']);
            $flagged[$kind][$same] = ($flagged[$kind][$same] ?? 0) + 1;
            if ($same !== 'same') {
                $note = sprintf('  %-4s %-9s reference %s, model %s (%s; %s)', $item['id'], $same, $ref['text'], $r['marked'], $r['said'], $r['kind']);
                if ($isPassage($item)) {
                    // Already listed with its run.
                } elseif (($item['source'] ?? '') === 'written') {
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

echo 'Now (UTC): ', now()->utc()->format('Y-m-d H:i:s'), "; model {$model}; ", count($items), ' texts, each asked ', $asks, ' times a run; the ', count($written),
    ' written sentences and the ', count($repeated) - count($written), ' passages run ', $runs, ' times; no answer for ', $failed, ' asks; one answer takes ', $at(0.5),
    ' s at the middle, ', $at(0.9), " s for the slowest tenth.\n\n";

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

if (! $labels) {
    echo "\nWhat the step took each text to be written in (first run), beside the test set's label:\n";
    foreach ($judged as $label => $by) {
        echo sprintf("  %-30s %3d texts: Fusha %3d (%3d words marked), a dialect %3d (%2d words marked, names only), not said %d\n", $label, array_sum($by),
            $by['fusha'] ?? 0, $judgedWords[$label]['fusha'] ?? 0, $by['dialect'] ?? 0, $judgedWords[$label]['dialect'] ?? 0, $by['not said'] ?? 0);
    }
    echo '  Fusha texts written for the test that a run took for a dialect: ',
        $judgedOther === [] ? 'none' : implode('; ', array_map(fn (string $id, array $r): string => $id.' (run '.implode(', ', $r).')', array_keys($judgedOther), $judgedOther)), "\n";
}

// How steady the choice of words is: the same text, run after run.
$same = fn (string $a, string $b): bool => TashkeelScore::reading($a, $b) === 'same' && TashkeelScore::reading($b, $a) === 'same';
echo "\nSteadiness over the ", $runs, ' runs, on the ', count($repeated), " texts asked every time (the same words marked, with the same reading):\n";
$unsteady = [];
foreach (['one' => 'one answer alone (the step before)', 'agreed' => 'what '.$asks.' answers agree on (the step now)'] as $how => $label) {
    $steadyTexts = 0;
    $wordsAll = 0;
    $wordsSome = 0;
    $wordsTwoReadings = 0;
    foreach ($repeated as $i) {
        $byRun = $marks[$how][$i] ?? [];
        $words = array_unique(array_merge(...array_map('array_keys', array_values($byRun)), ...[[]]));
        $steady = true;
        foreach ($words as $word) {
            $in = array_filter($byRun, fn (array $m): bool => isset($m[$word]));
            $first = reset($in)[$word];
            $oneReading = count(array_filter($in, fn (array $m): bool => ! $same($first, $m[$word]))) === 0;
            if (count($in) === count($byRun) && $oneReading) {
                $wordsAll++;

                continue;
            }
            $steady = false;
            $wordsSome += (int) (count($in) < count($byRun));
            $wordsTwoReadings += (int) ! $oneReading;
            if ($how === 'agreed') {
                $unsteady[] = sprintf('  %-4s %s: %s', $items[$i]['id'], $word, implode(' | ', array_map(fn (array $m): string => $m[$word] ?? '-', $byRun)));
            }
        }
        $steadyTexts += (int) $steady;
    }
    echo sprintf("  %-42s %2d of %d texts the same in every run; words marked every time %3d, only some times %2d, with two readings %d\n",
        $label.':', $steadyTexts, count($repeated), $wordsAll, $wordsSome, $wordsTwoReadings);
}
echo "  Words the step now did not mark the same in every run (run by run; - is left alone):\n", $unsteady === [] ? "  none\n" : implode("\n", $unsteady)."\n";

if ($passageMarks !== []) {
    echo "\nPassages: the words the step marked in each (first run):\n";
    foreach ($repeated as $i) {
        if ($isPassage($items[$i])) {
            echo sprintf("  %-4s %d: %s\n", $items[$i]['id'], count($marks['agreed'][$i][1] ?? []), implode('، ', $marks['agreed'][$i][1] ?? []));
        }
    }
    echo "\nPassages: every word the step marked, against the reference marking:\n";
    foreach ($passageMarks as $run => $v) {
        echo sprintf("  run %d: marked %2d: the same reading %2d, A DIFFERENT READING %d, unclear %d, no reference %d\n", $run, array_sum($v), $v['same'] ?? 0, $v['different'] ?? 0, $v['unclear'] ?? 0, $v['no reference'] ?? 0);
    }
}

echo "\nFirst run: every word the step marked, against the reference's marks on that word (member sentences: draft references):\n";
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

echo "\nWritten sentences (first run) and passages (every run): other words marked differently from the reference:\n", $otherWords === [] ? "  none\n" : implode("\n", $otherWords)."\n";
echo "\nMember sentences, first run: words marked differently from the draft reference (the word only):\n", $memberWords === [] ? "  none\n" : implode("\n", $memberWords)."\n";

// A failed pass mark fails the run, so it cannot pass unnoticed.
exit($allPassed ? 0 : 1);
