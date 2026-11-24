<?php

/**
 * Step 2 of the automatic pronunciation feature: does it help a voice, heard?
 * Staging only. It makes speech on the quota staging shares with live, so it
 * is run only when the owner has said yes, and it stops by itself at the
 * minutes given (9 unless ONLY carries capN).
 *
 * Each written sentence of the tashkeel test set (a homograph or a name whose
 * reading the sentence decides) is said twice by the premium voice:
 *
 *   today   the text as written, as the site sends it now
 *   auto    after the automatic step (App\Support\Tashkeel\WordReadings, the
 *           code the feature will use): a text model finds the words that
 *           could be misread, those words alone are marked, and the voice is
 *           told what each means. When the step marks nothing the request
 *           would be the same as "today", so no second clip is made.
 *
 * Two listening models say which reading of the sentence's word they hear.
 * A clip counts when both agree; when they disagree it is "split" and is
 * measured afterwards (tashkeel_formants.py reads splits.json, written beside
 * the clips, and compares the clip with the same sentence's other clip).
 * A page of every clip (index.html) is written too, for checking by ear.
 *
 * The test set and the two classes are not on the server: the workflow puts
 * them in a private temporary folder (DIR) and removes it afterwards.
 *
 *   ssh user@host 'cd ~/public_html && DIR=/tmp/x ONLY=autolisten php' < scripts/support/tashkeel_auto_listen.php
 *   ONLY=autolisten,cap5   stop at 5 minutes of speech
 *   ONLY=autolisten,first3 only the first three sentences (a trial of the whole run)
 *   ONLY=autolisten,skip3  all but the first three (after such a trial)
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
$golden = json_decode((string) @file_get_contents($dir.'/golden.json'), true);
if (! is_array($golden) || ! isset($golden['items'])) {
    fwrite(STDERR, "The test set was not found in DIR.\n");
    exit(1);
}

$only = array_map('trim', explode(',', (string) getenv('ONLY')));
$cap = 9.0;
foreach ($only as $token) {
    if (preg_match('/^cap(\d)$/', $token, $m)) {
        $cap = (float) $m[1];
    }
}
$voice = (string) (getenv('VOICE') ?: 'Kore');

// The written sentences, a name after every second homograph, so a run that stops early has both kinds.
$homographs = $names = [];
foreach ($golden['items'] as $item) {
    if (($item['source'] ?? '') === 'written' && isset($item['listen']['want'], $item['listen']['options'], $item['word'])) {
        if (in_array('homograph', $item['tags'] ?? [], true)) {
            $homographs[] = $item;
        } else {
            $names[] = $item;
        }
    }
}
$items = [];
while ($homographs !== [] || $names !== []) {
    array_push($items, ...array_splice($homographs, 0, 2), ...array_splice($names, 0, 1));
}
// "first3": only the first three sentences, to see the whole run work before the speech is spent.
foreach ($only as $token) {
    if (preg_match('/^first(\d+)$/', $token, $m)) {
        $items = array_slice($items, 0, (int) $m[1]);
    }
    // "skip3": leave out the first three, when a trial has already made them.
    if (preg_match('/^skip(\d+)$/', $token, $m)) {
        $items = array_slice($items, (int) $m[1]);
    }
}

$run = now()->utc()->format('Ymd-His');
$disk = Storage::disk('public');
$key = (string) (app(GeminiKeyManager::class)->getAvailableKey()['key'] ?? '');
$base = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
$listeners = ['gemini-3.8-flash', 'gemini-3.6-flash'];
$reader = 'gemini-3.8-flash';
mt_srand(11);

// The text model of the automatic step: the prompt in, its answer out (null when it failed).
// The step asks several times; each ask carries its own fixed seed (the second try of a failed one carries none).
$ask = function (string $prompt, int $k = 0) use ($base, $key, $reader): ?string {
    foreach ([true, false] as $thinking) {
        try {
            $r = Http::timeout(60)->withHeaders(['x-goog-api-key' => $key])->post($base.'/models/'.$reader.':generateContent', [
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0] + ($thinking ? ['seed' => 101 + $k, 'thinkingConfig' => ['thinkingLevel' => 'low']] : []),
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

/**
 * What each listener heard: the reading's id, "x" for none of them, "error" when it did not answer.
 *
 * @return list<array{id: string, heard: string}>
 */
$hear = function (string $bytes, array $item) use ($base, $key, $listeners): array {
    $ids = array_keys($item['listen']['options']);
    shuffle($ids);
    $letters = [];
    $lines = '';
    foreach ($ids as $i => $id) {
        [$marked, $said, $means] = $item['listen']['options'][$id];
        $letters[chr(65 + $i)] = $id;
        $lines .= chr(65 + $i).') '.$said.' — '.$marked.' ('.$means.")\n";
    }
    $sentence = TashkeelScore::strip($item['text']);
    $prompt = "Listen to this Arabic recording. The speaker reads: «{$sentence}»\n"
        .'Focus only on the word written «'.TashkeelScore::strip($item['word']).'». It can be said in more than one way. '
        .'Which way does the speaker actually say it? Judge by the sounds you hear (each vowel, each consonant, and whether a consonant is doubled), '
        ."NOT by which one would make more sense: the speaker may be saying the less likely one. Ignore any ending added to the word.\n"
        .$lines
        ."X) none of these, or it cannot be heard\n"
        .'Return only JSON: {"heard": "<the word as it sounded, in Latin letters>", "choice": "<one letter>"}';
    $body = [
        'contents' => [['role' => 'user', 'parts' => [
            ['inline_data' => ['mime_type' => str_starts_with($bytes, 'RIFF') ? 'audio/wav' : 'audio/mpeg', 'data' => base64_encode($bytes)]],
            ['text' => $prompt],
        ]]],
        'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0],
    ];
    $answers = Http::pool(fn (Pool $pool) => array_map(
        fn (string $model) => $pool->as($model)->timeout(90)->withHeaders(['x-goog-api-key' => $key])->post($base.'/models/'.$model.':generateContent', $body),
        $listeners,
    ));
    $out = [];
    foreach ($listeners as $model) {
        $r = $answers[$model] ?? null;
        $json = $r instanceof Response && $r->ok() ? json_decode((string) $r->json('candidates.0.content.parts.0.text'), true) : null;
        $out[] = is_array($json)
            ? ['id' => $letters[strtoupper(trim((string) ($json['choice'] ?? 'X')))] ?? 'x', 'heard' => mb_substr((string) ($json['heard'] ?? ''), 0, 24)]
            : ['id' => 'error', 'heard' => ''];
    }

    return $out;
};

$seconds = 0.0;
// One clip: made, kept beside the others, heard by both. result: ok (both heard the reading meant), not (both another), split.
$say = function (array $item, string $which, string $text, string $direction) use ($voice, $disk, $run, $hear, &$seconds): array {
    $options = ['voice_name' => $voice, 'dialect' => 'ar-XA', 'performance_style' => 'neutral', 'emotion' => 'neutral'];
    $made = app(GeminiTtsProvider::class)->synthesize($text, 'ar', $options + ($direction !== '' ? ['style_prompt' => $direction] : []));
    $bytes = (string) $disk->get($made['audio_path']);
    $seconds += AudioDuration::ofStoredFile($made['audio_path']) ?? 0;
    $file = $item['id'].'-'.$which.'.'.pathinfo($made['audio_path'], PATHINFO_EXTENSION);
    $disk->move($made['audio_path'], 'tashkeel-listen/'.$run.'/'.$file);
    [$a, $b] = $hear($bytes, $item);
    $want = $item['listen']['want'];
    $okA = $a['id'] === $want;
    $okB = $b['id'] === $want;

    return ['clip' => $item['id'].'-'.$which, 'file' => $file, 'a' => $a, 'b' => $b, 'text' => $text, 'direction' => $direction,
        'result' => $okA && $okB ? 'ok' : (! $okA && ! $okB ? 'not' : 'split')];
};

echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "; voice {$voice}; listeners: ", implode(' + ', $listeners), "; the automatic step's model: {$reader}; ",
    count($items), ' sentences; stops at ', $cap, ' minutes of speech; page: ', $disk->url('tashkeel-listen/'.$run.'/index.html'), "\n";

$rows = [];
foreach ($items as $item) {
    if ($seconds > $cap * 60) {
        echo 'Stopped: ', $cap, ' minutes of speech made; ', count($items) - count($rows), " sentences not reached.\n";
        break;
    }
    $kind = in_array('homograph', $item['tags'] ?? [], true) ? 'homograph' : 'name';
    try {
        $today = $say($item, 'today', $item['text'], '');
        $readings = WordReadings::find($item['text'], null, $ask);
        $made = WordReadings::apply($item['text'], $readings);
        // The word of the sentence: given a reading by the step, or left alone.
        $given = '';
        foreach ($readings as $r) {
            if (TashkeelScore::strip($r['word']) === TashkeelScore::strip($item['word'])) {
                $given = $r['marked'];
            }
        }
        // Nothing marked: the request is the same as "today", so the same clip stands for both.
        $auto = $readings === [] ? ['same' => true] + $today : ['same' => false] + $say($item, 'auto', $made['text'], $made['direction']);
    } catch (Throwable $e) {
        echo sprintf("%-4s ERROR %s\n", $item['id'], mb_substr($e->getMessage(), 0, 160));

        continue;
    }
    $rows[] = ['item' => $item, 'kind' => $kind, 'today' => $today, 'auto' => $auto, 'marked' => count($readings), 'given' => $given];
    echo sprintf("%-4s %-9s want %-9s today %-5s (%s | %s)   auto %-5s (%s | %s)   %s%s\n", $item['id'], $kind, $item['listen']['want'],
        $today['result'], $today['a']['id'], $today['b']['id'],
        $auto['same'] ? 'same' : $auto['result'], $auto['a']['id'], $auto['b']['id'],
        $auto['same'] ? 'the step marked nothing' : 'the step marked '.count($readings).' word(s)'.($given !== '' ? ', this one as '.$given : ', not this one'),
        in_array('known-hard', $item['tags'] ?? [], true) ? '   [known hard]' : '');
}

echo "\nSpeech made: ", round($seconds / 60, 1), " minutes.\n";
foreach (['homograph' => 'Homograph sentences', 'name' => 'Name sentences'] as $kind => $title) {
    $mine = array_values(array_filter($rows, fn (array $r): bool => $r['kind'] === $kind));
    if ($mine === []) {
        continue;
    }
    $count = fn (string $which, string $result): int => count(array_filter($mine, fn (array $r): bool => $r[$which]['result'] === $result));
    echo "\n", $title, ': ', count($mine), "\n";
    foreach (['today' => 'today', 'auto' => 'automatic'] as $which => $label) {
        echo sprintf("  %-10s both heard the reading meant %2d, both heard another %2d, the listeners disagree %2d\n", $label, $count($which, 'ok'), $count($which, 'not'), $count($which, 'split'));
    }
    $moves = [];
    foreach ($mine as $r) {
        if (! $r['auto']['same'] && $r['today']['result'] !== $r['auto']['result']) {
            $moves[] = $r['item']['id'].' '.$r['today']['result'].' -> '.$r['auto']['result'];
        }
    }
    echo '  The step marked nothing in ', count(array_filter($mine, fn (array $r): bool => $r['auto']['same'])), ' sentences (the same clip stands for both).',
        "\n  Changed between the two: ", $moves === [] ? 'none' : implode('; ', $moves), "\n";
}

// For the measurement afterwards: every clip the listeners disagree on, with the same sentence's other clip when the two agree on it.
$splits = [];
foreach ($rows as $r) {
    foreach (['today' => 'auto', 'auto' => 'today'] as $which => $other) {
        if ($r[$which]['result'] !== 'split' || ($which === 'auto' && $r['auto']['same'])) {
            continue;
        }
        $partner = ! $r['auto']['same'] && $r[$other]['result'] !== 'split' ? $r[$other] : null;
        $splits[] = ['clip' => $r[$which]['file'], 'id' => $r[$which]['clip'], 'want' => $r['item']['listen']['want'],
            'heard' => [$r[$which]['a']['id'], $r[$which]['b']['id']],
            'partner' => $partner['file'] ?? null, 'partner_result' => $partner['result'] ?? null];
    }
}
$disk->put('tashkeel-listen/'.$run.'/splits.json', json_encode($splits, JSON_UNESCAPED_UNICODE));
echo "\nClips the listeners disagree on: ", count($splits), ' (', count(array_filter($splits, fn (array $s): bool => $s['partner'] !== null)),
    " have the same sentence's other clip to be measured against). Run id for the measurement: ", $run, "\n";

// The page to check by ear: disagreements first, then the ones both heard wrong, then the rest.
$e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$cards = [];
foreach ($rows as $r) {
    foreach (['today', 'auto'] as $which) {
        if ($which === 'auto' && $r['auto']['same']) {
            continue;
        }
        $cards[] = ['r' => $r, 'which' => $which, 'c' => $r[$which]];
    }
}
usort($cards, fn (array $x, array $y): int => array_search($x['c']['result'], ['split', 'not', 'ok'], true) <=> array_search($y['c']['result'], ['split', 'not', 'ok'], true));
$html = '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="robots" content="noindex"><meta name="viewport" content="width=device-width, initial-scale=1">'
    .'<title>Tashkeel listening '.$e($run).'</title><style>body{font:16px system-ui;margin:16px;background:#111;color:#eee}article{border:1px solid #333;border-radius:12px;padding:12px;margin:0 0 12px}'
    .'h2{font-size:26px;margin:0 0 6px;line-height:1.9}p{margin:4px 0;direction:ltr;text-align:left;color:#bbb}audio{width:100%;margin-top:8px}.split{border-color:#c90}.not{border-color:#c33}b{color:#fff}</style>'
    .'<p>'.count($cards).' clips. Orange: the two listeners disagree. Red: both heard another reading. Tell Claude the clip and what you hear.</p>';
foreach ($cards as $card) {
    $item = $card['r']['item'];
    $c = $card['c'];
    $want = $item['listen']['options'][$item['listen']['want']];
    $html .= '<article class="'.$e($c['result']).'"><h2>'.$e($c['text']).'</h2>'
        .'<p><b>'.$e($c['clip']).'</b> · '.($card['which'] === 'today' ? 'as the site reads it today' : 'after the automatic step').'</p>'
        .'<p>The word «'.$e($item['word']).'» is meant as <b>'.$e($want[1].' — '.$want[0].' ('.$want[2].')').'</b></p>'
        .'<p>Listener 1 heard: '.$e($c['a']['id'].' ('.$c['a']['heard'].')').' · Listener 2 heard: '.$e($c['b']['id'].' ('.$c['b']['heard'].')').'</p>'
        .($c['direction'] !== '' ? '<p>The voice was told: '.$e($c['direction']).'</p>' : '')
        .'<audio controls preload="none" src="'.$e($c['file']).'"></audio></article>';
}
$disk->put('tashkeel-listen/'.$run.'/index.html', $html.'</html>');
