<?php

/**
 * Does the automatic pronunciation step make the voice lean on the word it
 * marks, and which way of telling the voice avoids it? Staging only. The owner
 * heard the marked word stressed on live; the voice should read at the same
 * pace, pitch and loudness as without the step, and still say the word right.
 *
 * It makes speech on the quota staging shares with live, so it is run only
 * when the owner has said yes, and it stops by itself at the minutes given
 * (4 unless ONLY carries capN).
 *
 * Written test sentences (homographs and names, not the known-hard ones) are
 * said by two premium voices, each sentence four ways, through the premium
 * voice's own code and with the direction joined to the voice's usual one as
 * the site joins it:
 *
 *   today   the text as written (the step off)
 *   now     the words marked, and the voice told as the step tells it today
 *   calm    the words marked, and the voice told the same, then asked to read
 *           evenly, with no stress, pause or slowing on those words
 *   marks   the words marked, and the voice told nothing
 *
 * Two listening models say which reading they hear in every clip (a word
 * counts when both agree). The page lists the four clips of each sentence side
 * by side for the owner's ear, and tashkeel_formants.py (formants,emphasis)
 * then measures, on the marked word, how much louder, longer and higher each
 * way is than "today".
 *
 *   ssh user@host 'cd ~/public_html && DIR=/tmp/x ONLY=calmtest php' < scripts/support/tashkeel_calm_test.php
 *   ONLY=calmtest,first1   a trial: the first sentence only
 */

use App\Models\Voice;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Support\AudioDuration;
use App\Support\Tashkeel\AutoReadings;
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
if (! class_exists(AutoReadings::class)) {
    echo "The automatic step is not on this server.\n";
    exit(1);
}

$only = array_map('trim', explode(',', (string) getenv('ONLY')));
$cap = 4.0;
foreach ($only as $token) {
    if (preg_match('/^cap(\d)$/', $token, $m)) {
        $cap = (float) $m[1];
    }
}
$dir = rtrim((string) getenv('DIR'), '/');
$golden = json_decode((string) @file_get_contents($dir.'/golden.json'), true);
if (! is_array($golden) || ! isset($golden['items'])) {
    fwrite(STDERR, "The test set was not found in DIR.\n");
    exit(1);
}
$homographs = $names = [];
foreach ($golden['items'] as $item) {
    if (($item['source'] ?? '') === 'written' && isset($item['listen']['want'], $item['listen']['options'], $item['word']) && ! in_array('known-hard', $item['tags'] ?? [], true)) {
        if (in_array('homograph', $item['tags'] ?? [], true)) {
            $homographs[] = $item;
        } else {
            $names[] = $item;
        }
    }
}
// Nine sentences: six homographs and three names.
$items = array_merge(array_slice($homographs, 0, 6), array_slice($names, 0, 3));
foreach ($only as $token) {
    if (preg_match('/^first(\d+)$/', $token, $m)) {
        $items = array_slice($items, 0, (int) $m[1]);
    }
    if (preg_match('/^skip(\d+)$/', $token, $m)) {
        $items = array_slice($items, (int) $m[1]);
    }
}

// The two premium Arabic voices of the staging check: a man's and a woman's.
$voices = Voice::query()->where('is_active', true)->where('tier', 'hd')->where('language', 'like', 'ar%')->orderBy('sort_order')->orderBy('id')->get()
    ->filter(fn (Voice $v): bool => stripos((string) $v->provider_voice_id.' '.$v->internal_voice_id, 'kore') === false)->values();
$chosen = array_values(array_filter([
    $voices->first(fn (Voice $v): bool => strtolower((string) $v->gender) === 'male'),
    $voices->first(fn (Voice $v): bool => strtolower((string) $v->gender) === 'female'),
]));
if (count($chosen) < 2) {
    echo "Two premium Arabic voices were not found.\n";
    exit(1);
}

$run = now()->utc()->format('Ymd-His');
$disk = Storage::disk('public');
$key = (string) (app(GeminiKeyManager::class)->getAvailableKey()['key'] ?? '');
$base = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
$listeners = ['gemini-3.8-flash', 'gemini-3.6-flash'];
mt_srand(11);

/** What each listener heard: the reading's id, "x" for none of them, "error" when it did not answer. */
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
        $out[] = is_array($json) ? ($letters[strtoupper(trim((string) ($json['choice'] ?? 'X')))] ?? 'x') : 'error';
    }

    return $out;
};

// The calm way: what the step tells the voice today, without its last sentence, then the even reading asked for.
$calm = function (string $now, int $words): string {
    $told = (string) preg_replace('/ Say (that word|those words) exactly that way\.$/', '', $now);

    return $told === '' ? '' : $told.' This only tells you which word is meant. Read the whole text as you would without this note: the same pace, pitch and loudness on every word, with no stress, pause or slowing on '.($words === 1 ? 'that word' : 'those words').'.';
};

$seconds = 0.0;
/** One clip, through the premium voice's own code: the direction is joined to the voice's usual one as the site joins it. */
$say = function (array $item, Voice $voice, int $n, string $way, string $text, string $direction) use ($disk, $run, $hear, &$seconds): array {
    $made = app(GeminiTtsProvider::class)->synthesize($text, 'ar', [
        'voice_name' => $voice->internal_voice_id, 'gender' => $voice->gender, 'dialect' => 'ar-XA',
        'performance_style' => 'neutral', 'emotion' => 'neutral', 'readings_direction' => $direction,
    ]);
    $bytes = (string) $disk->get($made['audio_path']);
    $seconds += AudioDuration::ofStoredFile($made['audio_path']) ?? 0;
    $clip = 'v'.$n.'-'.$item['id'].'-'.$way;
    $file = $clip.'.'.pathinfo($made['audio_path'], PATHINFO_EXTENSION);
    $disk->move($made['audio_path'], 'tashkeel-listen/'.$run.'/'.$file);
    [$a, $b] = $hear($bytes, $item);
    $want = $item['listen']['want'];

    return ['clip' => $clip, 'file' => $file, 'a' => $a, 'b' => $b, 'text' => $text, 'direction' => $direction,
        'result' => $a === $want && $b === $want ? 'ok' : ($a !== $want && $b !== $want ? 'not' : 'split')];
};

echo 'Now (UTC): ', now()->utc()->toDateTimeString(), '; voices: ', implode(' and ', array_map(fn (Voice $v): string => $v->display_name.' ('.$v->gender.')', $chosen)),
    '; ', count($items), ' sentences, each four ways; stops at ', $cap, ' minutes of speech; page: ', $disk->url('tashkeel-listen/'.$run.'/index.html'), "\n";

$ways = ['today' => 'as written (the step off)', 'now' => 'marked, told as today', 'calm' => 'marked, told to read evenly', 'marks' => 'marked, told nothing'];
$rows = [];
$stopped = false;
foreach ($chosen as $n => $voice) {
    echo "\n== voice ", $n + 1, ': ', $voice->display_name, ' (', $voice->gender, ") ==\n";
    foreach ($items as $item) {
        if ($seconds > $cap * 60 - 14) {
            $stopped = true;
            break 2;
        }
        try {
            $step = AutoReadings::for($item['text']);
            if ($step['info']['words'] === 0) {
                echo sprintf("%-4s the step marks nothing here: left out\n", $item['id']);

                continue;
            }
            $clips = [
                'today' => $say($item, $voice, $n + 1, 'today', $item['text'], ''),
                'now' => $say($item, $voice, $n + 1, 'now', $step['text'], $step['direction']),
                'calm' => $say($item, $voice, $n + 1, 'calm', $step['text'], $calm($step['direction'], (int) $step['info']['words'])),
                'marks' => $say($item, $voice, $n + 1, 'marks', $step['text'], ''),
            ];
        } catch (Throwable $e) {
            echo sprintf("%-4s ERROR %s\n", $item['id'], mb_substr($e->getMessage(), 0, 180));

            continue;
        }
        $rows[] = ['voice' => $n, 'item' => $item, 'clips' => $clips];
        echo sprintf("%-4s want %-9s %s\n", $item['id'], $item['listen']['want'], implode('   ', array_map(
            fn (string $way): string => sprintf('%s %-5s (%s | %s)', $way, $clips[$way]['result'], $clips[$way]['a'], $clips[$way]['b']),
            array_keys($ways),
        )));
    }
}
echo $stopped ? "\nStopped: close to ".$cap." minutes of speech.\n" : '', "\nSpeech made: ", round($seconds / 60, 1), " minutes.\n";

echo "\nThe word read as meant (both listeners), of ", count($rows), " sentence readings:\n";
foreach ($ways as $way => $label) {
    $count = fn (string $result): int => count(array_filter($rows, fn (array $r): bool => $r['clips'][$way]['result'] === $result));
    echo sprintf("  %-6s %-30s right %2d, another reading %2d, the listeners disagree %2d\n", $way, $label, $count('ok'), $count('not'), $count('split'));
}
echo "\nRun id for the measurement (formants,emphasis): ", $run, "\n";

// The page: the four clips of each sentence side by side.
$e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$html = '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="robots" content="noindex"><meta name="viewport" content="width=device-width, initial-scale=1">'
    .'<title>Tashkeel calm test '.$e($run).'</title><style>body{font:16px system-ui;margin:16px;background:#111;color:#eee}section{border:1px solid #333;border-radius:12px;padding:12px;margin:0 0 16px}'
    .'h2{font-size:24px;margin:0 0 6px;line-height:1.9}p{margin:6px 0 2px;direction:ltr;text-align:left;color:#bbb}audio{width:100%}.split{color:#fc6}.not{color:#f77}b{color:#fff}</style>'
    .'<p>Each sentence four ways. Listen to the marked word: is it stressed, slower or louder than in the first clip (the step off)? Tell Claude the sentence and which way sounds most like the first.</p>';
foreach ($rows as $r) {
    $want = $r['item']['listen']['options'][$r['item']['listen']['want']];
    $html .= '<section><h2>'.$e($r['item']['text']).'</h2><p>'.$e($chosen[$r['voice']]->display_name).' · the word «'.$e($r['item']['word']).'» is meant as <b>'.$e($want[1].' — '.$want[0].' ('.$want[2].')').'</b></p>';
    foreach ($ways as $way => $label) {
        $c = $r['clips'][$way];
        // An article each, as the measurement reads the page: the sentence, the clip's name, the word, the file.
        $html .= '<article class="'.$e($c['result']).'"><h2 hidden>'.$e($r['item']['text']).'</h2><p><b>'.$e($c['clip']).'</b> · '.$e($label).' · heard: '.$e($c['a'].' | '.$c['b']).'</p>'
            .'<p hidden>The word «'.$e($r['item']['word']).'»</p><audio controls preload="none" src="'.$e($c['file']).'"></audio></article>';
    }
    $html .= '</section>';
}
$disk->put('tashkeel-listen/'.$run.'/index.html', $html.'</html>');
