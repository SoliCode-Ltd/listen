<?php

/*
 * For the "Server deploy" workflow's tashkeel-listen mode (input "tashkeel"
 * starting with "levers"), on STAGING only. Round 2: the first round showed a
 * premium voice follows vowel marks only about two times in three when the
 * sentence does not decide. This measures what makes it say the reading asked
 * for. Owner-approved (2026-11-23): up to 20 minutes of speech on the quota
 * staging shares with live; all kinds together make about 13, and it stops
 * by itself at 19.
 *
 * Sentences that mean something with either reading (words and names):
 *   take1 take2 take3  the word marked, made three times: how much one take
 *                      differs from the next, and what a second take buys
 *   gloss      the word marked, and the voice told what the word means and
 *              how it is said ("means the contract, said al-ʿaqd")
 *   glossonly  the same direction with NO marks: is the meaning enough?
 *   engine     the word marked, read by the standard engine (whichever the
 *              admin has picked: WaveNet or Chirp 3 HD)
 * Dialect words, with the dialect chosen (Lebanese, Egyptian, Najdi):
 *   dialect    as members write them, through the site's own dialect rewrite
 *   raw        as written, the rewrite off
 *   respelled  written the way the dialect says it (ألبي، أوي، گال), rewrite off
 *
 * Two listening models hear every clip and are asked for the sounds, not the
 * meaning. The first round's single listener disagreed with the owner's ear
 * on one clip in three, so a clip counts only when both agree; the rest are
 * "split". A page with every clip to play, splits first, is written beside
 * the clips (tashkeel-listen/<run>/index.html) for the owner to check by ear.
 *
 *   ssh user@host 'cd ~/public_html && ONLY=levers,take1,gloss php' < scripts/support/tashkeel_levers.php
 */

use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Services\Tts\GoogleCloudTtsProvider;
use App\Support\AudioDuration;
use App\Support\StandardVoiceEngine;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

ini_set('display_errors', 'stderr');
set_time_limit(2700);
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (str_contains(strtolower((string) config('app.url')), 'listenai.studio')) {
    fwrite(STDERR, "This is the live site: tashkeel-listen runs on staging only.\n");
    exit(1);
}

// [sentence, the word in it, [reading id => [the word marked, how it is said in Latin letters, what it means]]]
$either = [
    ['هذا علم مهم.', 'علم', ['ilm' => ['عِلْم', 'ʿilm', 'a science, knowledge'], 'alam' => ['عَلَم', 'ʿalam', 'a flag']]],
    ['قرأت عن ملك عظيم.', 'ملك', ['malik' => ['مَلِك', 'malik', 'a king'], 'mulk' => ['مُلْك', 'mulk', 'a reign, dominion']]],
    ['أعجبني شعرها كثيراً.', 'شعرها', ['shaar' => ['شَعْرها', 'shaʿr-ha', 'her hair'], 'shiir' => ['شِعْرها', 'shiʿr-ha', 'her poetry']]],
    ['سمعت أنه درس هناك.', 'درس', ['darasa' => ['دَرَسَ', 'darasa', 'he studied'], 'darrasa' => ['دَرَّسَ', 'darrasa', 'he taught']]],
    ['رأيت العقد على الطاولة.', 'العقد', ['aqd' => ['العَقْد', 'al-ʿaqd', 'the contract'], 'iqd' => ['العِقْد', 'al-ʿiqd', 'the necklace']]],
    ['الجزر كثيرة هنا.', 'الجزر', ['jazar' => ['الجَزَر', 'al-jazar', 'carrots'], 'juzur' => ['الجُزُر', 'al-juzur', 'islands']]],
    ['هذا دين قديم.', 'دين', ['dayn' => ['دَيْن', 'dayn', 'a debt'], 'din' => ['دِين', 'dīn', 'a religion']]],
    ['تكلموا عن السلم طويلاً.', 'السلم', ['silm' => ['السِّلْم', 'as-silm', 'peace'], 'sullam' => ['السُّلَّم', 'as-sullam', 'the ladder']]],
    ['هي مدرسة معروفة.', 'مدرسة', ['madrasa' => ['مَدْرَسَة', 'madrasa', 'a school'], 'mudarrisa' => ['مُدَرِّسَة', 'mudarrisa', 'a woman teacher']]],
    ['كتب هذا في القاهرة.', 'كتب', ['kataba' => ['كَتَبَ', 'kataba', 'he wrote'], 'kutiba' => ['كُتِبَ', 'kutiba', 'it was written']]],
    ['نشر هذا في الصحف.', 'نشر', ['nashara' => ['نَشَرَ', 'nashara', 'he published'], 'nushira' => ['نُشِرَ', 'nushira', 'it was published']]],
    ['يعد الرجل ابنه.', 'يعد', ['yaidu' => ['يَعِدُ', 'yaʿidu', 'he promises'], 'yuiddu' => ['يُعِدُّ', 'yuʿiddu', 'he prepares']]],
    ['ذكر ذلك في الكتاب.', 'ذكر', ['dhakara' => ['ذَكَرَ', 'dhakara', 'he mentioned'], 'dhukira' => ['ذُكِرَ', 'dhukira', 'it was mentioned']]],
    // Names of people and places.
    ['زرت جدة أمس.', 'جدة', ['jidda' => ['جِدَّة', 'Jidda', 'the city of Jeddah'], 'jadda' => ['جَدَّة', 'jadda', 'a grandmother']]],
    ['سمعت عن الخبر أمس.', 'الخبر', ['khabar' => ['الخَبَر', 'al-khabar', 'the news'], 'khubar' => ['الخُبَر', 'al-Khubar', 'the city of Khobar']]],
    ['سألته عن عمر.', 'عمر', ['umar' => ['عُمَر', 'ʿUmar', 'the man\'s name Omar'], 'umr' => ['عُمْر', 'ʿumr', 'age']]],
    ['أعجبني حسن كثيراً.', 'حسن', ['hasan' => ['حَسَن', 'Ḥasan', 'the man\'s name Hasan'], 'husn' => ['حُسْن', 'ḥusn', 'beauty']]],
    ['رأيت منى هناك.', 'منى', ['muna' => ['مُنى', 'Muna', 'the woman\'s name Mona'], 'mina' => ['مِنى', 'Mina', 'Mina, the place near Mecca']]],
    ['اخترنا الأقصر.', 'الأقصر', ['uqsur' => ['الأُقْصُر', 'al-Uqṣur', 'the city of Luxor'], 'aqsar' => ['الأَقْصَر', 'al-aqṣar', 'the shorter one']]],
    ['تكلمت مع علا.', 'علا', ['ula' => ['عُلا', 'ʿUla', 'the woman\'s name Ola'], 'ala' => ['عَلا', 'ʿala', 'he rose']]],
    ['شكرت حمد كثيراً.', 'حمد', ['hamad' => ['حَمَد', 'Ḥamad', 'the man\'s name Hamad'], 'hamd' => ['حَمْد', 'ḥamd', 'praise']]],
    ['وصلنا عنيزة مساء.', 'عنيزة', ['unayza' => ['عُنَيْزة', 'ʿUnayza', 'the city of Unaizah'], 'aniza' => ['عَنِيزة', 'ʿAnīza', 'another reading of the same letters']]],
];

// [dialect, sentence as members write it, the word, the word respelled the way the dialect says it, [the dialect's way, the Fusha way]]
$dialects = [
    ['ar-LB', 'قلبي معك دايماً.', 'قلبي', 'ألبي', ['ʾalbi (no q: a catch in the throat)', 'qalbi (with q)']],
    ['ar-LB', 'ما في وقت اليوم.', 'وقت', 'وأت', ['waʾt (no q)', 'waqt (with q)']],
    ['ar-LB', 'هلق بحكيك.', 'هلق', 'هلّأ', ['hallaʾ (no q)', 'halaq or hallaq (with q)']],
    ['ar-LB', 'بدي فنجان قهوة.', 'قهوة', 'أهوة', ['ʾahwe (no q)', 'qahwa (with q)']],
    ['ar-LB', 'قال إنو جاي.', 'قال', 'آل', ['ʾāl (no q)', 'qāl (with q)']],
    ['ar-LB', 'الطقس منيح اليوم.', 'الطقس', 'الطأس', ['iṭ-ṭaʾs (no q)', 'aṭ-ṭaqs (with q)']],
    ['ar-EG', 'الأكل حلو قوي.', 'قوي', 'أوي', ['ʾawi (no q)', 'qawi (with q)']],
    ['ar-EG', 'قلت له كده.', 'قلت', 'ألت', ['ʾult (no q)', 'qult (with q)']],
    ['ar-EG', 'ده جميل جداً.', 'جميل', 'گميل', ['gamīl (hard g)', 'jamīl (j as in jam)']],
    ['ar-EG', 'الجو حر النهارده.', 'الجو', 'الگو', ['ig-gaww (hard g)', 'al-jaww (j as in jam)']],
    ['ar-EG', 'الوقت اتأخر.', 'الوقت', 'الوأت', ['il-waʾt (no q)', 'al-waqt (with q)']],
    ['ar-EG', 'جبت لك هدية.', 'جبت', 'گبت', ['gibt (hard g)', 'jibt (j as in jam)']],
    ['ar-SA', 'قال لي تعال.', 'قال', 'گال', ['gāl (hard g)', 'qāl (with q)']],
    ['ar-SA', 'القهوة جاهزة.', 'القهوة', 'الگهوة', ['al-gahwa (hard g)', 'al-qahwa (with q)']],
    ['ar-SA', 'قلبي يحبك.', 'قلبي', 'گلبي', ['galbi (hard g)', 'qalbi (with q)']],
    ['ar-SA', 'البيت قريب من هنا.', 'قريب', 'گريب', ['girīb (hard g)', 'qarīb (with q)']],
    ['ar-SA', 'الحين أقول لك.', 'أقول', 'أگول', ['agūl (hard g)', 'aqūl (with q)']],
    ['ar-SA', 'الطريق طويل.', 'الطريق', 'الطريگ', ['aṭ-ṭirīg (hard g)', 'aṭ-ṭarīq (with q)']],
];

$only = array_values(array_diff(array_filter(array_map('trim', explode(',', (string) getenv('ONLY')))), ['levers']));
$wants = fn (string $kind): bool => $only === [] || in_array($kind, $only, true);
$voice = (string) (getenv('VOICE') ?: 'Kore');
$replace = fn (string $sentence, string $word, string $with): string => (string) preg_replace('/(?<!\p{L})'.preg_quote($word, '/').'(?!\p{L})/u', $with, $sentence, 1);

// Each clip: what to make and what to listen for.
$clips = [];
foreach ($either as $n => [$sentence, $word, $ways]) {
    foreach ($ways as $id => [$marked, $said, $means]) {
        $options = array_map(fn (array $w): string => $w[1].' — '.$w[0].' ('.$w[2].')', $ways);
        $gloss = 'In this text the word written «'.$word.'» means "'.$means.'" and is said "'.$said.'". Say that word exactly that way.';
        $base = ['sentence' => $sentence, 'word' => $word, 'want' => $id, 'options' => $options, 'dialect' => 'ar-XA'];
        foreach (['take1', 'take2', 'take3'] as $take) {
            $clips[] = $base + ['id' => "n{$n}-{$id}-{$take}", 'kind' => $take, 'text' => $replace($sentence, $word, $marked)];
        }
        $clips[] = $base + ['id' => "n{$n}-{$id}-gloss", 'kind' => 'gloss', 'text' => $replace($sentence, $word, $marked), 'direction' => $gloss];
        $clips[] = $base + ['id' => "n{$n}-{$id}-glossonly", 'kind' => 'glossonly', 'text' => $sentence, 'direction' => $gloss];
        $clips[] = $base + ['id' => "n{$n}-{$id}-engine", 'kind' => 'engine', 'text' => $replace($sentence, $word, $marked), 'engine' => 'standard'];
    }
}
foreach ($dialects as $n => [$dialect, $sentence, $word, $respelled, [$theirs, $fusha]]) {
    $base = ['sentence' => $sentence, 'word' => $word, 'want' => 'dialect', 'options' => ['dialect' => $theirs, 'fusha' => $fusha], 'dialect' => $dialect];
    $clips[] = $base + ['id' => "d{$n}-dialect", 'kind' => 'dialect', 'text' => $sentence];
    $clips[] = $base + ['id' => "d{$n}-raw", 'kind' => 'raw', 'text' => $sentence, 'rewrite' => false];
    $clips[] = $base + ['id' => "d{$n}-respelled", 'kind' => 'respelled', 'text' => $replace($sentence, $word, $respelled), 'rewrite' => false];
}
$clips = array_values(array_filter($clips, fn (array $c): bool => $wants($c['kind'])));
if ((int) getenv('LIMIT') > 0) {
    $clips = array_slice($clips, 0, (int) getenv('LIMIT'));
}

$run = now()->utc()->format('Ymd-His');
$disk = Storage::disk('public');
$key = (string) (app(GeminiKeyManager::class)->getAvailableKey()['key'] ?? '');
$base = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
$listeners = ['gemini-3.8-flash', 'gemini-3.6-flash'];
mt_srand(11);

/**
 * What each listener heard: the reading's id, "x" for none of them, "error" when it did not answer.
 *
 * @return list<array{id: string, heard: string}>
 */
$hear = function (string $bytes, array $clip) use ($base, $key, $listeners): array {
    $ids = array_keys($clip['options']);
    shuffle($ids);
    $letters = [];
    $lines = '';
    foreach ($ids as $i => $id) {
        $letters[chr(65 + $i)] = $id;
        $lines .= chr(65 + $i).') '.$clip['options'][$id]."\n";
    }
    $prompt = "Listen to this Arabic recording. The speaker reads: «{$clip['sentence']}»\n"
        ."Focus only on the word written «{$clip['word']}». It can be said in more than one way. "
        ."Which way does the speaker actually say it? Judge by the sounds you hear (each vowel, each consonant, and whether a consonant is doubled), "
        ."NOT by which one would make more sense: the speaker may be saying the less likely one.\n"
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
        $json = $r instanceof Illuminate\Http\Client\Response && $r->ok() ? json_decode((string) $r->json('candidates.0.content.parts.0.text'), true) : null;
        $out[] = is_array($json)
            ? ['id' => $letters[strtoupper(trim((string) ($json['choice'] ?? 'X')))] ?? 'x', 'heard' => mb_substr((string) ($json['heard'] ?? ''), 0, 24)]
            : ['id' => 'error', 'heard' => ''];
    }

    return $out;
};

echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "; voice {$voice}; standard engine: ", StandardVoiceEngine::current(), '; listeners: ', implode(' + ', $listeners),
    '; ', count($clips), ' clip(s); page: ', $disk->url('tashkeel-listen/'.$run.'/index.html'), "\n";
$scores = [];
$rows = [];
$seconds = 0.0;
$rewriteWas = config('services.gemini_tts.dialect_rewrite', true);
foreach ($clips as $clip) {
    if ($seconds > 19 * 60) {
        echo "Stopped: 19 minutes of speech made.\n";
        break;
    }
    $kind = $clip['kind'];
    try {
        config(['services.gemini_tts.dialect_rewrite' => ($clip['rewrite'] ?? true) ? $rewriteWas : false]);
        $options = ['voice_name' => $voice, 'dialect' => $clip['dialect'], 'performance_style' => 'neutral', 'emotion' => 'neutral'];
        if (($clip['engine'] ?? '') === 'standard') {
            $made = app(GoogleCloudTtsProvider::class)->synthesize($clip['text'], 'ar', ['voice_name' => '', 'tier' => 'standard', 'gender' => 'female']);
        } else {
            $made = app(GeminiTtsProvider::class)->synthesize($clip['text'], 'ar', $options + (isset($clip['direction']) ? ['style_prompt' => $clip['direction']] : []));
        }
        $bytes = (string) $disk->get($made['audio_path']);
        $seconds += AudioDuration::ofStoredFile($made['audio_path']) ?? 0;
        $file = $clip['id'].'.'.pathinfo($made['audio_path'], PATHINFO_EXTENSION);
        $disk->move($made['audio_path'], 'tashkeel-listen/'.$run.'/'.$file);
        [$a, $b] = $hear($bytes, $clip);
    } catch (Throwable $e) {
        echo sprintf("%-22s %-9s ERROR %s\n", $clip['id'], $kind, mb_substr($e->getMessage(), 0, 160));
        $scores[$kind]['error'] = ($scores[$kind]['error'] ?? 0) + 1;

        continue;
    } finally {
        config(['services.gemini_tts.dialect_rewrite' => $rewriteWas]);
    }
    $okA = $a['id'] === $clip['want'];
    $okB = $b['id'] === $clip['want'];
    $result = $okA && $okB ? 'ok' : (! $okA && ! $okB ? 'not' : 'split');
    $scores[$kind][$result] = ($scores[$kind][$result] ?? 0) + 1;
    $scores[$kind]['a'] = ($scores[$kind]['a'] ?? 0) + (int) $okA;
    $scores[$kind]['b'] = ($scores[$kind]['b'] ?? 0) + (int) $okB;
    $rows[] = $clip + ['file' => $file, 'a' => $a, 'b' => $b, 'result' => $result];
    echo sprintf("%-22s %-9s want %-9s heard %-9s (%s) | %-9s (%s) %s\n", $clip['id'], $kind, $clip['want'], $a['id'], $a['heard'], $b['id'], $b['heard'], $result === 'ok' ? '' : '<< '.$result);
}

echo "\nSpeech made: ", round($seconds / 60, 1), " minutes.\n";
foreach ($scores as $kind => $s) {
    $n = ($s['ok'] ?? 0) + ($s['not'] ?? 0) + ($s['split'] ?? 0);
    echo sprintf("%-10s %3d clips: both heard the reading asked for %3d, both heard another %3d, split %2d%s | of the %d they agree on: %d%% | each listener alone: %d%% and %d%%\n",
        $kind, $n + ($s['error'] ?? 0), $s['ok'] ?? 0, $s['not'] ?? 0, $s['split'] ?? 0, isset($s['error']) ? ', failed '.$s['error'] : '',
        ($s['ok'] ?? 0) + ($s['not'] ?? 0), round(100 * ($s['ok'] ?? 0) / max(1, ($s['ok'] ?? 0) + ($s['not'] ?? 0))),
        round(100 * ($s['a'] ?? 0) / max(1, $n)), round(100 * ($s['b'] ?? 0) / max(1, $n)));
}

// What one more take buys: a word is right "within N takes" when any of its first N takes is.
$takes = [];
foreach ($rows as $r) {
    if (preg_match('/^take([123])$/', $r['kind'], $m)) {
        $takes[preg_replace('/-take\d$/', '', $r['id'])][(int) $m[1]] = $r['result'];
    }
}
if ($takes !== []) {
    foreach ([1, 2, 3] as $upTo) {
        $have = array_filter($takes, fn (array $t): bool => count(array_intersect_key($t, array_flip(range(1, $upTo)))) === $upTo);
        $right = count(array_filter($have, fn (array $t): bool => in_array('ok', array_slice($t, 0, $upTo, true), true)));
        if ($have !== []) {
            echo sprintf("Right within %d take(s): %d of %d words (%d%%)\n", $upTo, $right, count($have), round(100 * $right / count($have)));
        }
    }
    echo 'Words whose three takes did not all come out the same: ', count(array_filter($takes, fn (array $t): bool => count($t) === 3 && count(array_unique($t)) > 1)), ' of ', count($takes), "\n";
}

// The page to check by ear: splits first, then the ones both heard wrong, then the rest.
usort($rows, fn (array $x, array $y): int => array_search($x['result'], ['split', 'not', 'ok'], true) <=> array_search($y['result'], ['split', 'not', 'ok'], true));
$e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$html = '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="robots" content="noindex"><meta name="viewport" content="width=device-width, initial-scale=1">'
    .'<title>Tashkeel listening '.$e($run).'</title><style>body{font:16px system-ui;margin:16px;background:#111;color:#eee}article{border:1px solid #333;border-radius:12px;padding:12px;margin:0 0 12px}'
    .'h2{font-size:26px;margin:0 0 6px;line-height:1.9}p{margin:4px 0;direction:ltr;text-align:left;color:#bbb}audio{width:100%;margin-top:8px}.split{border-color:#c90}.not{border-color:#c33}b{color:#fff}</style>'
    .'<p>'.count($rows).' clips. Orange: the two listeners disagree. Red: both heard another reading. Tell Claude the number and what you hear.</p>';
foreach ($rows as $i => $r) {
    $html .= '<article class="'.$e($r['result']).'"><p><b>'.($i + 1).'</b> · '.$e($r['id']).' · '.$e($r['kind']).'</p><h2>'.$e($r['text']).'</h2>'
        .'<p>Asked for: <b>'.$e($r['options'][$r['want']]).'</b></p>'
        .'<p>Listener 1 heard: '.$e($r['a']['id'].' ('.$r['a']['heard'].')').' · Listener 2 heard: '.$e($r['b']['id'].' ('.$r['b']['heard'].')').'</p>'
        .(isset($r['direction']) ? '<p>Direction: '.$e($r['direction']).'</p>' : '')
        .'<audio controls preload="none" src="'.$e($r['file']).'"></audio></article>';
}
$disk->put('tashkeel-listen/'.$run.'/index.html', $html.'</html>');
