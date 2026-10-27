<?php

/*
 * On STAGING, with the owner's OK (it uses the shared speech quota): does the voice
 * say a member's saved word as saved? Two sentences whose meaning points to another
 * reading, two voices, two directions, two takes each (12 short voices):
 *   A: today's direction, the words named at the end of the style;
 *   B: the words first, each with a Latin sound guide built from its marks, and that
 *      this overrides what the sentence suggests.
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
$female = (string) Voice::query()->where('language', 'ar')->where('provider', 'gemini')->where('gender', 'female')->where('is_active', true)->value('internal_voice_id');
$voices = ['ar-Gemini-HD-Schedar' => 'male', $female => 'female'];

$sentences = [
    's1' => ['text' => 'قابلت ملك امبارح في النادي، وقالت لي إن Listen هيتكرر تاني الشهر الجاي.', 'saved' => [['word' => 'ملك', 'spoken' => 'مَلِك'], ['word' => 'Listen', 'spoken' => 'لِسِن']], 'voices' => array_keys($voices)],
    's2' => ['text' => 'الولد كتب الدرس كله امبارح.', 'saved' => [['word' => 'كتب', 'spoken' => 'كُتُب']], 'voices' => ['ar-Gemini-HD-Schedar']],
];

$out = ['meta' => ['model' => $model, 'style' => $style, 'emotion' => $emotion, 'female_voice' => $female, 'ffmpeg' => trim((string) @shell_exec('command -v ffmpeg'))], 'takes' => []];
foreach ($sentences as $sid => $s) {
    $applied = Pronunciations::applyEntries($s['text'], $s['saved']);
    $guides = [];
    foreach ($s['saved'] as $e) {
        $guides[$e['spoken']] = soundGuide($e['spoken']);
    }
    foreach ($s['voices'] as $voiceName) {
        $baseStyle = $call('asSpeaker', [$voiceName, $call('buildStylePrompt', [$style, $emotion, 'ar', 1.0, 'ar-EG', 0.0])]);
        $directions = [
            'A' => trim($baseStyle.' '.Pronunciations::direction($applied['text'], $applied['spoken'])),
            'B' => 'Pronunciation, to follow exactly even where the sentence suggests another word: '
                .implode('; ', array_map(fn (string $w): string => '«'.$w.'» is said "'.$guides[$w].'"', $applied['spoken'])).'. '.$baseStyle,
        ];
        $voice = $call('resolveGeminiVoice', [$voiceName, $voices[$voiceName] ?? null]);
        foreach ($directions as $variant => $direction) {
            foreach ([1, 2] as $take) {
                $row = ['sentence' => $sid, 'voice' => $voiceName, 'variant' => $variant, 'take' => $take, 'text_sent' => $applied['text'], 'direction_start' => mb_substr($direction, 0, 260)];
                try {
                    $audio = $call('requestAudio', [$endpoint, $call('buildSingleSpeakerPayload', [$applied['text'], $direction, $voice])]);
                    $wav = SpeechJoin::toFile([['bytes' => $audio['bytes'], 'mime_type' => $audio['mime_type']]])['path'];
                    $words = implode(', ', array_column($s['saved'], 'word'));
                    $heard = Http::timeout(90)->withHeaders(['x-goog-api-key' => $key])->post($base.'/models/gemini-3.5-flash:generateContent', [
                        'contents' => [['role' => 'user', 'parts' => [
                            ['inline_data' => ['mime_type' => 'audio/wav', 'data' => base64_encode((string) file_get_contents($wav))]],
                            ['text' => 'The speaker reads this text: «'.$s['text'].'». Return JSON with "transcript" (what is said, word for word, with full Arabic vowel marks as pronounced) '
                                .'and "words": an object giving, for each of these written words, a Latin transliteration of exactly how the speaker pronounced it: '.$words.'.'],
                        ]]],
                        'generationConfig' => ['responseMimeType' => 'application/json'],
                    ])->json('candidates.0.content.parts.0.text');
                    $row['heard'] = json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', (string) $heard)), true) ?? $heard;
                    $row['expected'] = array_combine(array_column($s['saved'], 'word'), array_map(fn ($e) => $guides[$e['spoken']], $s['saved']));
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
        }
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
