<?php

/**
 * Step 3 of the automatic pronunciation feature: longer texts, several open
 * words at once, and every word the step marks scored. Staging only. It makes
 * speech on the quota staging shares with live, so it is run only when the
 * owner has said yes, and it stops by itself at the minutes given (9 unless
 * ONLY carries capN).
 *
 * Each passage of tests/tashkeel/passages.json (written for the test, with a
 * full reference marking and the words to score by ear) is said twice by the
 * premium voice:
 *
 *   today   the text as written, as the site sends it now
 *   auto    after the automatic step (App\Support\Tashkeel\WordReadings): the
 *           words it finds are marked and the voice is told what each means
 *
 * Scored:
 *   - every word the step marked, against the reference marking of that word
 *     (the same reading, a different one, or unclear), with no listening;
 *   - by ear, in both clips, every word the passage lists, and every word the
 *     step marked differently from the reference: two listening models say
 *     which reading they hear. A word counts when both agree; when they do
 *     not it is "split" and is measured afterwards (tashkeel_formants.py
 *     reads splits.json, written beside the clips).
 *
 * The step gives one reading to a spelling: where a passage uses the same
 * letters for two words (كتب as "he wrote" and as "books"), only the first is
 * marked, and what the voice is told about it may reach the second. The
 * report says so for each such word.
 *
 * The passages and the two classes are not on the server: the workflow puts
 * them in a private temporary folder (DIR) and removes it afterwards.
 *
 *   ssh user@host 'cd ~/public_html && DIR=/tmp/x ONLY=passages php' < scripts/support/tashkeel_passages.php
 *   ONLY=passages,first1   only the first passage (a trial of the whole run)
 *   ONLY=passages,skip1    all but the first (after such a trial)
 */

use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Support\AudioDuration;
use App\Support\Tashkeel\TashkeelScore;
use App\Support\Tashkeel\WordReadings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

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
$passages = json_decode((string) @file_get_contents($dir.'/passages.json'), true)['passages'] ?? null;
if (! is_array($passages)) {
    fwrite(STDERR, "The passages were not found in DIR.\n");
    exit(1);
}

$cap = 9.0;
foreach (array_map('trim', explode(',', (string) getenv('ONLY'))) as $token) {
    if (preg_match('/^cap(\d)$/', $token, $m)) {
        $cap = (float) $m[1];
    }
    if (preg_match('/^first(\d+)$/', $token, $m)) {
        $passages = array_slice($passages, 0, (int) $m[1]);
    }
    if (preg_match('/^skip(\d+)$/', $token, $m)) {
        $passages = array_slice($passages, (int) $m[1]);
    }
}
$voice = (string) (getenv('VOICE') ?: 'Kore');
$run = now()->utc()->format('Ymd-His');
$disk = Storage::disk('public');
$key = (string) (app(GeminiKeyManager::class)->getAvailableKey()['key'] ?? '');
$base = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
$listeners = ['gemini-3.8-flash', 'gemini-3.6-flash'];
$reader = 'gemini-3.8-flash';
mt_srand(11);

// The text model of the automatic step: the prompt in, its answer out (null when it failed).
$ask = function (string $prompt) use ($base, $key, $reader): ?string {
    foreach ([true, false] as $thinking) {
        try {
            $r = Http::timeout(60)->withHeaders(['x-goog-api-key' => $key])->post($base.'/models/'.$reader.':generateContent', [
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0] + ($thinking ? ['thinkingConfig' => ['thinkingLevel' => 'low']] : []),
            ]);
            $text = $r->successful() ? data_get($r->json(), 'candidates.0.content.parts.0.text') : null;
            if (is_string($text) && $text !== '') {
                return $text;
            }
        } catch (Throwable) {
        }
    }

    return null;
};

/** The text's words in order, letters and marks only: [the word, where it starts as a share of the text]. */
$tokens = function (string $text): array {
    $out = [];
    $length = max(1, mb_strlen($text));
    preg_match_all('/\S+/u', $text, $found, PREG_OFFSET_CAPTURE);
    foreach ($found[0] as [$token, $offset]) {
        $out[] = [(string) preg_replace('/[^\p{L}\p{M}]/u', '', $token), mb_strlen(substr($text, 0, $offset)) / $length];
    }

    return $out;
};

/**
 * What the two listeners heard for each word of one clip, all asked together.
 *
 * @param  list<array<string, mixed>>  $words  each with word, n, context, options, want
 * @return list<array{a: string, b: string, result: string}>
 */
$hear = function (string $bytes, string $text, array $words) use ($base, $key, $listeners): array {
    $jobs = [];
    foreach ($words as $i => $w) {
        $ids = array_keys($w['options']);
        shuffle($ids);
        $letters = [];
        $lines = '';
        foreach ($ids as $k => $id) {
            [$marked, $said, $means] = $w['options'][$id];
            $letters[chr(65 + $k)] = $id;
            $lines .= chr(65 + $k).') '.($said !== '' ? $said.' — ' : '').$marked.($means !== '' ? ' ('.$means.')' : '')."\n";
        }
        $prompt = "Listen to this Arabic recording. The speaker reads: «{$text}»\n"
            .'Focus only on the word written «'.$w['word'].'»'.($w['n'] > 1 ? ', the '.['', 'first', 'second', 'third', 'fourth'][$w['n']].' time it appears' : '')
            .', in the phrase «'.$w['context'].'». It can be said in more than one way. '
            .'Which way does the speaker actually say it? Judge by the sounds you hear (each vowel, each consonant, and whether a consonant is doubled), '
            ."NOT by which one would make more sense: the speaker may be saying the less likely one. Ignore any prefix or ending attached to the word.\n"
            .$lines
            ."X) none of these, or it cannot be heard\n"
            .'Return only JSON: {"heard": "<the word as it sounded, in Latin letters>", "choice": "<one letter>"}';
        foreach ($listeners as $model) {
            $jobs[] = ['i' => $i, 'model' => $model, 'letters' => $letters, 'prompt' => $prompt];
        }
    }
    $heard = [];
    $mime = str_starts_with($bytes, 'RIFF') ? 'audio/wav' : 'audio/mpeg';
    $data = base64_encode($bytes);
    foreach (array_chunk($jobs, 8, true) as $chunk) {
        $answers = Http::pool(function (Pool $pool) use ($chunk, $base, $key, $mime, $data) {
            foreach ($chunk as $j => $job) {
                $pool->as((string) $j)->timeout(120)->withHeaders(['x-goog-api-key' => $key])->post($base.'/models/'.$job['model'].':generateContent', [
                    'contents' => [['role' => 'user', 'parts' => [['inline_data' => ['mime_type' => $mime, 'data' => $data]], ['text' => $job['prompt']]]]],
                    'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0],
                ]);
            }
        });
        foreach ($chunk as $j => $job) {
            $r = $answers[(string) $j] ?? null;
            $json = $r instanceof Response && $r->ok() ? json_decode((string) $r->json('candidates.0.content.parts.0.text'), true) : null;
            $heard[$job['i']][$job['model']] = is_array($json) ? ($job['letters'][strtoupper(trim((string) ($json['choice'] ?? 'X')))] ?? 'x') : 'error';
        }
    }
    $out = [];
    foreach ($words as $i => $w) {
        $a = $heard[$i][$listeners[0]] ?? 'error';
        $b = $heard[$i][$listeners[1]] ?? 'error';
        $out[$i] = ['a' => $a, 'b' => $b, 'result' => $a === $w['want'] && $b === $w['want'] ? 'ok' : ($a !== $w['want'] && $b !== $w['want'] ? 'not' : 'split')];
    }

    return $out;
};

$seconds = 0.0;
// One clip: made and kept beside the others. [its file, its bytes]
$say = function (string $id, string $text, string $direction) use ($voice, $disk, $run, &$seconds): array {
    $options = ['voice_name' => $voice, 'dialect' => 'ar-XA', 'performance_style' => 'neutral', 'emotion' => 'neutral'];
    $made = app(GeminiTtsProvider::class)->synthesize($text, 'ar', $options + ($direction !== '' ? ['style_prompt' => $direction] : []));
    $bytes = (string) $disk->get($made['audio_path']);
    $seconds += AudioDuration::ofStoredFile($made['audio_path']) ?? 0;
    $file = $id.'.'.pathinfo($made['audio_path'], PATHINFO_EXTENSION);
    $disk->move($made['audio_path'], 'tashkeel-listen/'.$run.'/'.$file);

    return [$file, $bytes];
};

echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "; voice {$voice}; listeners: ", implode(' + ', $listeners), "; the automatic step's model: {$reader}; ",
    count($passages), ' passages; stops at ', $cap, ' minutes of speech; page: ', $disk->url('tashkeel-listen/'.$run.'/index.html'), "\n";

$rows = [];        // every word scored by ear
$marked = [];      // every word the step marked: verdict against the reference
$cards = [];
$splits = [];
foreach ($passages as $p) {
    if ($seconds > $cap * 60 - 40) {
        echo 'Stopped: close to ', $cap, " minutes of speech.\n";
        break;
    }
    try {
        $text = $p['text'];
        $plain = $tokens($text);
        $ref = $tokens($p['ref']);
        $bare = array_map(fn (array $t): string => TashkeelScore::strip($t[0]), $plain);
        $readings = WordReadings::find($text, null, $ask);
        $made = WordReadings::apply($text, $readings);

        // Every word the step marked, against the reference's marks on the same word (its first time in the text).
        $byWord = [];
        $extra = [];
        foreach ($readings as $r) {
            $at = array_search(TashkeelScore::strip($r['word']), $bare, true);
            $verdict = $at === false ? 'no reference' : TashkeelScore::reading($ref[$at][0], $r['marked']);
            $byWord[TashkeelScore::strip($r['word'])] = $r + ['verdict' => $verdict];
            $listed = count(array_filter($p['words'], fn (array $w): bool => $w['word'] === TashkeelScore::strip($r['word']) && $w['n'] === 1)) > 0;
            $marked[] = ['id' => $p['id'], 'word' => $r['word'], 'marked' => $r['marked'], 'ref' => $at === false ? '' : $ref[$at][0], 'verdict' => $verdict, 'kind' => $r['kind'], 'listed' => $listed];
            // Marked differently from the reference and not on the passage's list: heard too, the reference's way against the step's.
            if ($verdict === 'different' && ! $listed && $at !== false) {
                $extra[] = ['word' => TashkeelScore::strip($r['word']), 'n' => 1, 'kind' => 'extra', 'want' => 'ref',
                    'options' => ['ref' => [$ref[$at][0], '', ''], 'step' => [$r['marked'], $r['said'], $r['means']]]];
            }
        }

        // The words heard, each with the words around it and where it is in the text.
        $words = [];
        foreach (array_merge($p['words'], $extra) as $w) {
            $seen = 0;
            foreach ($bare as $k => $token) {
                if ($token === $w['word'] && ++$seen === $w['n']) {
                    $given = $byWord[$w['word']] ?? null;
                    $words[] = $w + [
                        'context' => trim(($plain[$k - 1][0] ?? '').' '.$plain[$k][0].' '.($plain[$k + 1][0] ?? '')),
                        'pos' => round($plain[$k][1], 3),
                        // What the step did to this word: marked it (the right way or not), left it, or marked the same letters earlier in the text.
                        'step' => $given === null ? 'left' : ($w['n'] > 1 ? 'earlier' : ($given['verdict'] === 'same' ? 'marked' : 'marked-'.$given['verdict'])),
                    ];
                }
            }
        }

        [$todayFile, $todayBytes] = $say($p['id'].'-today', $text, '');
        $today = $hear($todayBytes, $text, $words);
        [$autoFile, $autoBytes] = $readings === [] ? [$todayFile, $todayBytes] : $say($p['id'].'-auto', $made['text'], $made['direction']);
        $auto = $readings === [] ? $today : $hear($autoBytes, $text, $words);
    } catch (Throwable $e) {
        echo sprintf("%-4s ERROR %s\n", $p['id'], mb_substr($e->getMessage(), 0, 160));

        continue;
    }

    echo "\n", $p['id'], ': the step marked ', count($readings), ' word(s)', count($readings) >= WordReadings::MAX ? ' (its limit)' : '', '; the voice was told ', mb_strlen($made['direction']), " characters.\n";
    foreach ($words as $i => $w) {
        $rows[] = ['id' => $p['id'], 'w' => $w, 'today' => $today[$i], 'auto' => $auto[$i]];
        echo sprintf("  %-10s %-5s want %-9s today %-5s (%s | %s)   auto %-5s (%s | %s)   step: %s\n", $w['word'].($w['n'] > 1 ? '#'.$w['n'] : ''), $w['kind'], $w['want'],
            $today[$i]['result'], $today[$i]['a'], $today[$i]['b'], $auto[$i]['result'], $auto[$i]['a'], $auto[$i]['b'],
            ['left' => 'left alone', 'earlier' => 'the same letters were marked earlier in the text', 'marked' => 'marked, the right reading'][$w['step']] ?? 'MARKED WITH ANOTHER READING');
        foreach (['today' => [$today[$i], $todayFile, $auto[$i], $autoFile], 'auto' => [$auto[$i], $autoFile, $today[$i], $todayFile]] as $which => [$mine, $file, $other, $otherFile]) {
            if ($mine['result'] === 'split' && $file !== $otherFile) {
                $splits[] = ['clip' => $file, 'id' => $p['id'].'-'.$which.' '.$w['word'].($w['n'] > 1 ? '#'.$w['n'] : ''), 'want' => $w['want'], 'heard' => [$mine['a'], $mine['b']],
                    'partner' => $other['result'] !== 'split' ? $otherFile : null, 'partner_result' => $other['result'] !== 'split' ? $other['result'] : null, 'pos' => $w['pos']];
            }
        }
    }
    $cards[] = ['p' => $p, 'words' => $words, 'today' => $today, 'auto' => $auto, 'todayFile' => $todayFile, 'autoFile' => $autoFile, 'made' => $made];
}

echo "\nSpeech made: ", round($seconds / 60, 1), " minutes.\n";

echo "\nEvery word the step marked, against the reference marking: ", count($marked), "\n";
foreach (['same' => 'the same reading', 'different' => 'A DIFFERENT READING', 'unclear' => 'unclear (a vowel left open)', 'no reference' => 'no reference'] as $v => $label) {
    $n = count(array_filter($marked, fn (array $m): bool => $m['verdict'] === $v));
    if ($n > 0) {
        echo sprintf("  %-28s %3d\n", $label, $n);
    }
}
foreach ($marked as $m) {
    if ($m['verdict'] !== 'same') {
        echo '    ', $m['id'], ' ', $m['word'], ': the step ', $m['marked'], ', the reference ', $m['ref'], ' (', $m['verdict'], $m['listed'] ? '' : ', not on the passage\'s list', ")\n";
    }
}

$table = function (string $title, array $mine): void {
    if ($mine === []) {
        return;
    }
    $count = fn (string $which, string $result): int => count(array_filter($mine, fn (array $r): bool => $r[$which]['result'] === $result));
    echo "\n", $title, ': ', count($mine), "\n";
    foreach (['today' => 'today', 'auto' => 'automatic'] as $which => $label) {
        echo sprintf("  %-10s both heard the reading meant %2d, both heard another %2d, the listeners disagree %2d\n", $label, $count($which, 'ok'), $count($which, 'not'), $count($which, 'split'));
    }
    $moves = [];
    foreach ($mine as $r) {
        if ($r['today']['result'] !== $r['auto']['result']) {
            $moves[] = $r['id'].' '.$r['w']['word'].($r['w']['n'] > 1 ? '#'.$r['w']['n'] : '').' '.$r['today']['result'].' -> '.$r['auto']['result'];
        }
    }
    echo '  Changed between the two: ', $moves === [] ? 'none' : implode('; ', $moves), "\n";
};
$table('Heard: every word scored', $rows);
$table('Heard: ordinary words', array_values(array_filter($rows, fn (array $r): bool => $r['w']['kind'] === 'word')));
$table('Heard: names', array_values(array_filter($rows, fn (array $r): bool => $r['w']['kind'] === 'name')));
$table('Heard: words the step marked with the right reading', array_values(array_filter($rows, fn (array $r): bool => $r['w']['step'] === 'marked')));
$table('Heard: words the step left alone', array_values(array_filter($rows, fn (array $r): bool => $r['w']['step'] === 'left')));
$table('Heard: words whose letters the step marked earlier in the text for another word', array_values(array_filter($rows, fn (array $r): bool => $r['w']['step'] === 'earlier')));
$table('Heard: words the step marked with another reading than the reference', array_values(array_filter($rows, fn (array $r): bool => str_starts_with($r['w']['step'], 'marked-'))));

$disk->put('tashkeel-listen/'.$run.'/splits.json', json_encode($splits, JSON_UNESCAPED_UNICODE));
echo "\nWords the listeners disagree on: ", count($splits), ' (', count(array_filter($splits, fn (array $s): bool => $s['partner'] !== null)),
    " can be measured against the same text's other clip). Run id for the measurement: ", $run, "\n";

// The page to check by ear.
$e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$html = '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="robots" content="noindex"><meta name="viewport" content="width=device-width, initial-scale=1">'
    .'<title>Tashkeel listening '.$e($run).'</title><style>body{font:16px system-ui;margin:16px;background:#111;color:#eee}article{border:1px solid #333;border-radius:12px;padding:12px;margin:0 0 12px}'
    .'h2{font-size:22px;margin:0 0 6px;line-height:1.9}p,li{margin:4px 0;direction:ltr;text-align:left;color:#bbb}audio{width:100%;margin-top:8px}.split{color:#fc6}.not{color:#f77}b{color:#fff}</style>'
    .'<p>'.count($cards).' passages, each as the site reads it today and after the automatic step. Orange: the two listeners disagree. Red: both heard another reading. Tell Claude the passage, the word and what you hear.</p>';
foreach ($cards as $c) {
    foreach (['today' => ['As the site reads it today', $c['todayFile'], $c['today'], $c['p']['text']], 'auto' => ['After the automatic step', $c['autoFile'], $c['auto'], $c['made']['text']]] as $which => [$label, $file, $heard, $shown]) {
        $html .= '<article><h2>'.$e($shown).'</h2><p><b>'.$e($c['p']['id'].'-'.$which).'</b> · '.$label.'</p><ul>';
        foreach ($c['words'] as $i => $w) {
            $want = $w['options'][$w['want']];
            $html .= '<li class="'.$e($heard[$i]['result']).'">«'.$e($w['word']).'»'.($w['n'] > 1 ? ' ('.$w['n'].')' : '').' meant as <b>'.$e(trim($want[1].' '.$want[0])).'</b>: listener 1 heard '
                .$e($heard[$i]['a']).', listener 2 heard '.$e($heard[$i]['b']).'</li>';
        }
        $html .= '</ul>'.($which === 'auto' && $c['made']['direction'] !== '' ? '<p>The voice was told: '.$e($c['made']['direction']).'</p>' : '')
            .'<audio controls preload="none" src="'.$e($file).'"></audio></article>';
    }
}
$disk->put('tashkeel-listen/'.$run.'/index.html', $html.'</html>');
