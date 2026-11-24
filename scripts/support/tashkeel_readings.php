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
 *   - safety: no letter changed, and how many of the model's entries had to
 *     be refused.
 *
 * The test set and the two classes are not on the server (the branch is not
 * deployed): the workflow puts them in a private temporary folder (DIR) and
 * removes it afterwards. Sentences members wrote are sent to the same model
 * family that already reads members' text; the log shows them only as counts
 * and, for a word marked differently from its reference, that one word.
 *
 *   ssh user@host 'cd ~/public_html && DIR=/tmp/x ONLY=readings php' < scripts/support/tashkeel_readings.php
 *   ONLY=readings,35   the model the site's dialect rewrite uses, instead of the default
 */

use App\Services\Tts\GeminiKeyManager;
use App\Support\Tashkeel\TashkeelScore;
use App\Support\Tashkeel\WordReadings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

ini_set('display_errors', 'stderr');
set_time_limit(1500);
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
$key = (string) (app(GeminiKeyManager::class)->getAvailableKey()['key'] ?? '');
$url = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/').'/models/'.$model.':generateContent';
$variety = ['egyptian' => 'Egyptian Arabic', 'levantine' => 'Levantine Arabic', 'gulf' => 'Gulf Arabic'];
$body = fn (string $prompt, bool $thinking): array => [
    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
    'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0] + ($thinking ? ['thinkingConfig' => ['thinkingLevel' => 'low']] : []),
];
$answer = fn ($r): ?string => $r instanceof Response && $r->successful() ? (data_get($r->json(), 'candidates.0.content.parts.0.text') ?: null) : null;

// Ask for every sentence, six at a time; one that failed is asked once more, alone and without the thinking setting.
$raw = [];
$seconds = [];
$failed = 0;
foreach (array_chunk(array_keys($items), 6) as $batch) {
    try {
        $responses = Http::pool(function (Pool $pool) use ($batch, $items, $variety, $url, $key, $body) {
            foreach ($batch as $i) {
                $pool->as((string) $i)->timeout(60)->withHeaders(['x-goog-api-key' => $key])
                    ->post($url, $body(WordReadings::prompt($items[$i]['text'], $variety[$items[$i]['variety'] ?? ''] ?? null), true));
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
                    ->post($url, $body(WordReadings::prompt($items[$i]['text'], $variety[$items[$i]['variety'] ?? ''] ?? null), false));
                $raw[$i] = $answer($again);
            } catch (Throwable) {
                $raw[$i] = null;
            }
            $failed += (int) ($raw[$i] === null);
        }
    }
}
sort($seconds);
$at = fn (float $p): float => $seconds === [] ? 0.0 : round($seconds[(int) floor($p * (count($seconds) - 1))], 1);

echo 'Now (UTC): ', now()->utc()->format('Y-m-d H:i:s'), "; model {$model}; ", count($items), ' sentences; no answer for ', $failed,
    '; one answer takes ', $at(0.5), ' s at the middle, ', $at(0.9), " s for the slowest tenth.\n\n";

$groups = [];      // group => verdict => count, for the marked word of the written sentences
$flagged = [];     // variety => verdict => count, for every word the model marked
$lines = [];
$memberWords = [];
$lettersChanged = 0;
$refused = [];
$perSentence = [];
foreach ($items as $i => $item) {
    $entries = $raw[$i] === null ? [] : WordReadings::parse($raw[$i]);
    $readings = WordReadings::accept($item['text'], $entries, [], $refused);
    $made = WordReadings::apply($item['text'], $readings);
    $lettersChanged += (int) (TashkeelScore::strip($made['text']) !== TashkeelScore::strip($item['text']));
    $written = ($item['source'] ?? '') === 'written';
    $kind = $written ? (in_array('homograph', $item['tags'] ?? [], true) ? 'homograph' : 'name') : (string) ($item['variety'] ?? 'fusha');
    $perSentence[$kind][] = count($readings);
    $refWords = TashkeelScore::words((string) ($item['ref'] ?? ''));

    // The marked word of a written sentence: right, wrong, unclear, or left alone.
    if ($written && isset($item['word'], $item['reading'])) {
        $verdict = 'left alone';
        $shown = '';
        foreach ($readings as $r) {
            if (TashkeelScore::strip($r['word']) === TashkeelScore::strip($item['word'])) {
                $same = TashkeelScore::reading($item['reading'], $r['marked']);
                $verdict = ['same' => 'right', 'different' => 'WRONG', 'unclear' => 'unclear'][$same];
                $shown = $r['marked'].' ('.$r['said'].'; '.$r['means'].')';
            }
        }
        $groups[$kind][$verdict] = ($groups[$kind][$verdict] ?? 0) + 1;
        $lines[] = sprintf('  %-4s %-10s want %-12s %s %s', $item['id'], $verdict, $item['reading'],
            $shown === '' ? 'the model listed: '.(implode('، ', array_map(fn (array $e): string => (string) ($e['word'] ?? '?'), $entries)) ?: 'nothing') : 'got '.$shown,
            in_array('known-hard', $item['tags'] ?? [], true) ? ' [known hard]' : '');
    }

    // Every word the model marked, against the reference's marks on that word.
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
            $note = sprintf('  %-4s %-9s reference %s, model %s (%s)', $item['id'], $same, $ref['text'], $r['marked'], $r['said']);
            if ($written) {
                $lines[] = $note.'   (another word of the sentence)';
            } else {
                $memberWords[] = $note;
            }
        }
    }
}

foreach (['homograph' => 'Written homograph sentences (the context decides the reading)', 'name' => 'Written name sentences'] as $kind => $title) {
    $g = $groups[$kind] ?? [];
    $n = array_sum($g);
    if ($n === 0) {
        continue;
    }
    echo $title, ': ', $n, "\n";
    foreach (['right', 'WRONG', 'unclear', 'left alone'] as $v) {
        echo sprintf("  %-11s %3d  (%d%%)\n", $v, $g[$v] ?? 0, round(100 * ($g[$v] ?? 0) / $n));
    }
    $given = ($g['right'] ?? 0) + ($g['WRONG'] ?? 0) + ($g['unclear'] ?? 0);
    echo '  Of the ', $given, ' it gave a reading for, right: ', $given > 0 ? round(100 * ($g['right'] ?? 0) / $given, 1) : 0, "%\n";
    if ($kind === 'homograph') {
        $wrong = 100 * ($g['WRONG'] ?? 0) / $n;
        echo '  Pass mark (no more than 3% given a wrong reading): ', round($wrong, 1), '% -> ', $wrong <= 3 ? 'PASSED' : 'FAILED', "\n";
    }
    echo "\n";
}

echo "Every word the model marked, against the reference's marks on that word (member sentences: draft references):\n";
foreach ($flagged as $kind => $f) {
    $n = array_sum($f);
    $counts = $perSentence[$kind];
    echo sprintf("  %-10s %3d sentences, %3d words marked (%.1f a sentence, most %d, none in %d): same %d, different %d, unclear %d, no reference %d\n",
        $kind, count($counts), $n, $n / max(1, count($counts)), max($counts), count(array_filter($counts, fn (int $c): bool => $c === 0)),
        $f['same'] ?? 0, $f['different'] ?? 0, $f['unclear'] ?? 0, $f['no reference'] ?? 0);
}
arsort($refused);
echo "\nSafety: sentences whose letters changed: ", $lettersChanged, '; entries of the model refused: ', array_sum($refused),
    $refused === [] ? '' : ' ('.implode(', ', array_map(fn (string $k, int $v): string => $k.' '.$v, array_keys($refused), $refused)).')', ".\n";

echo "\nThe written sentences, one by one:\n", implode("\n", $lines), "\n";
echo "\nMember sentences: words marked differently from the draft reference (the word only):\n", $memberWords === [] ? "  none\n" : implode("\n", $memberWords)."\n";
