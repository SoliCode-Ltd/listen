<?php

/*
 * On STAGING, with the owner's OK (it uses the shared speech quota): does the voice
 * say a member's saved word as saved? Round 1 showed that no direction in the style
 * overrides what the sentence means (12 of 12 read the word by its meaning). Round 2
 * (8 short voices) wrote the word into the text by its sound, in Latin letters: the voice
 * still read it by the sentence's meaning. Round 3 (10 short voices, with the owner's OK):
 *   isolate: the saved word made on its own, with nothing around it to suggest another
 *            reading, and joined between the text before and after it (no pause);
 *   plain / current: everyday cases (a brand, a foreign name, a word both readings fit),
 *            made without the saved word, then with it as the studio does today.
 * A text model listens to each take and writes how each word was said. Nothing is
 * written to the database; the takes are printed (MP3, base64) for the run's files.
 */

use App\Models\Voice;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Support\GeminiTtsModel;
use App\Support\Pronunciations;
use App\Support\SpeechJoin;
use App\Support\TtsStudioConfig;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

ini_set('display_errors', 'stderr');
$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Arabic with its marks to a Latin sound guide: مَلِك → malik, كُتُب → kutub, لِسِن → lisin.
function soundGuide(string $word): string
{
    $cons = ['ب' => 'b', 'ت' => 't', 'ث' => 'th', 'ج' => 'j', 'ح' => 'h', 'خ' => 'kh', 'د' => 'd', 'ذ' => 'dh', 'ر' => 'r', 'ز' => 'z',
        'س' => 's', 'ش' => 'sh', 'ص' => 's', 'ض' => 'd', 'ط' => 't', 'ظ' => 'z', 'ع' => "'", 'غ' => 'gh', 'ف' => 'f', 'ق' => 'q',
        'ك' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n', 'ه' => 'h', 'و' => 'w', 'ي' => 'y', 'ء' => "'", 'ؤ' => "'", 'ئ' => "'", 'ة' => 'a', 'ى' => 'a'];
    $vowel = ["\u{064E}" => 'a', "\u{064F}" => 'u', "\u{0650}" => 'i', "\u{064B}" => 'an', "\u{064C}" => 'un', "\u{064D}" => 'in', "\u{0652}" => ''];
    $chars = mb_str_split($word);
    $out = '';
    $prevVowel = '';
    // A shadda doubles the letter before it, whether it is written before or after the vowel.
    $lastCons = '';
    $consEnd = 0;
    foreach ($chars as $i => $c) {
        if (isset($vowel[$c])) {
            $out .= $vowel[$c];
            $prevVowel = $vowel[$c];

            continue;
        }
        if ($c === "\u{0651}") {
            $out = substr($out, 0, $consEnd).$lastCons.substr($out, $consEnd);
            $consEnd += strlen($lastCons);

            continue;
        }
        if ($c === "\u{0670}") {
            $out .= 'aa';

            continue;
        }
        if (in_array($c, ['ا', 'أ', 'إ', 'آ', 'ٱ'], true)) {
            $out .= $c === 'إ' ? 'i' : ($c === 'آ' ? 'aa' : ($i === 0 ? 'a' : ($prevVowel === 'a' ? 'a' : 'aa')));
            $prevVowel = '';

            continue;
        }
        $next = $chars[$i + 1] ?? '';
        if ($c === 'و' && $prevVowel === 'u' && ! isset($vowel[$next])) {
            $out .= 'u';
            $prevVowel = '';

            continue;
        }
        if ($c === 'ي' && $prevVowel === 'i' && ! isset($vowel[$next])) {
            $out .= 'i';
            $prevVowel = '';

            continue;
        }
        $lastCons = $cons[$c] ?? (preg_match('/\p{Latin}/u', $c) ? $c : '');
        $out .= $lastCons;
        $consEnd = strlen($out);
        $prevVowel = '';
    }

    return $out;
}

$provider = app(GeminiTtsProvider::class);
$call = function (string $method, array $args) use ($provider) {
    $m = new ReflectionMethod($provider, $method);

    return $m->invokeArgs($m->isStatic() ? null : $provider, $args);
};
$model = GeminiTtsModel::resolve();
$endpoint = $call('buildEndpoint', [$model]);
$key = (string) (app(GeminiKeyManager::class)->getAvailableKey()['key'] ?? '');
$base = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
$style = TtsStudioConfig::defaultPerformanceStyle('ar');
$emotion = TtsStudioConfig::defaultEmotion('ar');
$voiceName = 'ar-Gemini-HD-Schedar';
$voice = $call('resolveGeminiVoice', [$voiceName, 'male']);
$baseStyle = $call('asSpeaker', [$voiceName, $call('buildStylePrompt', [$style, $emotion, 'ar', 1.0, 'ar-EG', 0.0])]);
$wordStyle = 'This is one word from the middle of a sentence being read: say only this word, exactly as written with every vowel mark, '
    .'flowing at the pace of the sentence, with no pause before or after it and no emphasis. '.$baseStyle;

$malak = 'قابلت ملك امبارح في النادي، وقالت لي إن الحفلة هتبقى الشهر الجاي.';
$katab = 'الولد كتب الدرس كله امبارح.';
$brand = 'جرّبوا Listen النهارده، الصوت هيعجبكم.';
$name = 'أهلاً بيكم، معاكم Nguyen من فريق الدعم.';
$alam = 'الحصة الجاية هنتكلم عن علم جديد.';
$cases = [
    ['isolate-malik-1', $malak, [['word' => 'ملك', 'spoken' => 'مَلِك']], 'isolate'],
    ['isolate-malik-2', $malak, [['word' => 'ملك', 'spoken' => 'مَلِك']], 'isolate'],
    ['isolate-kutub', $katab, [['word' => 'كتب', 'spoken' => 'كُتُب']], 'isolate'],
    ['brand-without', $brand, [], 'plain'],
    ['brand-with', $brand, [['word' => 'Listen', 'spoken' => 'لِسِن']], 'current'],
    ['name-without', $name, [], 'plain'],
    ['name-with', $name, [['word' => 'Nguyen', 'spoken' => 'وِين']], 'current'],
    ['alam-without', $alam, [], 'plain'],
    ['alam-with', $alam, [['word' => 'علم', 'spoken' => 'عَلَم']], 'current'],
    ['alam-isolate', $alam, [['word' => 'علم', 'spoken' => 'عَلَم']], 'isolate'],
];

$speak = fn (string $text, string $direction): array => $call('requestAudio', [$endpoint, $call('buildSingleSpeakerPayload', [$text, $direction, $voice])]);
$out = ['meta' => ['model' => $model, 'style' => $style, 'emotion' => $emotion, 'voice' => $voiceName], 'takes' => []];
foreach ($cases as [$id, $text, $saved, $mode]) {
    $applied = Pronunciations::applyEntries($text, $saved);
    $row = ['case' => $id, 'mode' => $mode, 'text_sent' => $applied['text']];
    try {
        if ($mode === 'isolate') {
            // Before | the saved word on its own | after, joined with no pause.
            $word = $applied['spoken'][0];
            $at = mb_strpos($applied['text'], $word);
            $parts = array_values(array_filter([
                ['text' => trim(mb_substr($applied['text'], 0, $at)), 'style' => $baseStyle],
                ['text' => $word, 'style' => $wordStyle],
                ['text' => trim(mb_substr($applied['text'], $at + mb_strlen($word))), 'style' => $baseStyle],
            ], fn (array $p): bool => preg_match('/\p{L}/u', $p['text']) === 1));
            $row['parts'] = array_column($parts, 'text');
            $pieces = array_map(fn (array $p): array => $speak($p['text'], $p['style']), $parts);
            $wav = SpeechJoin::toFile(array_map(fn ($a) => ['bytes' => $a['bytes'], 'mime_type' => $a['mime_type']], $pieces), array_fill(0, count($pieces), 0.02), [], 0.02, true)['path'];
        } else {
            $direction = $mode === 'current' ? trim($baseStyle.' '.Pronunciations::direction($applied['text'], $applied['spoken'])) : $baseStyle;
            $audio = $speak($applied['text'], $direction);
            $wav = SpeechJoin::toFile([['bytes' => $audio['bytes'], 'mime_type' => $audio['mime_type']]])['path'];
        }
        $heard = Http::timeout(90)->withHeaders(['x-goog-api-key' => $key])->post($base.'/models/gemini-3.5-flash:generateContent', [
            'contents' => [['role' => 'user', 'parts' => [
                ['inline_data' => ['mime_type' => 'audio/wav', 'data' => base64_encode((string) file_get_contents($wav))]],
                // Not told the text, so the sentence's meaning cannot lead it.
                ['text' => 'Listen to this Arabic audio. Return JSON with "transcript" (exactly what is said, word for word, in Arabic script with full vowel marks as pronounced) '
                    .'and "latin" (the same, in Latin letters as a phonetic transcription of exactly the sounds you hear, word by word), and "joins" (one short line: does it sound like one natural sentence, or are there breaks or jumps?).'],
            ]]],
            'generationConfig' => ['responseMimeType' => 'application/json'],
        ])->json('candidates.0.content.parts.0.text');
        $row['heard'] = json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', (string) $heard)), true) ?? $heard;
        $mp3 = $wav.'.mp3';
        @shell_exec('ffmpeg -loglevel error -y -i '.escapeshellarg($wav).' -ac 1 -b:a 64k '.escapeshellarg($mp3));
        $row['mp3'] = is_file($mp3) ? base64_encode((string) file_get_contents($mp3)) : null;
        @unlink($wav);
        @unlink($mp3);
    } catch (Throwable $e) {
        $row['error'] = get_class($e).': '.mb_substr($e->getMessage(), 0, 300);
    }
    $out['takes'][] = $row;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
