<?php

/*
 * Experiment on STAGING: which way of sending Arabic sound tags gets them
 * performed. Same texts, three variants, two tries each, stock voice and
 * cloned voice; a Gemini model lists the non-speech sounds it hears.
 * Nothing is written. Prints JSON.
 */

use App\Models\ClonedVoice;
use App\Services\Tts\GeminiKeyManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

ini_set('display_errors', 'stderr');
set_time_limit(1200);
$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$keys = app(GeminiKeyManager::class)->getAllKeys();
$key = (string) (app(GeminiKeyManager::class)->getAvailableKey()['key'] ?? '');
$base = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
$cloned = ClonedVoice::query()->whereKey(13)->first();

$sounds = [
    'scream' => 'a real, loud scream of anger',
    'laugh' => 'real, audible laughter (ha ha ha)',
    'sigh' => 'a long, audible sigh',
];
function performStyle(array $tags, array $sounds): string
{
    $lines = [];
    foreach ($tags as $t) {
        $lines[] = "<{$t}> means: {$sounds[$t]}.";
    }

    return 'Read the Arabic naturally in Egyptian colloquial Arabic. The transcript contains sound tags in angle brackets. '
        .'Never read a tag aloud. At each tag, stop speaking and actually perform that sound with your voice, clearly and fully, then continue. '
        .implode(' ', $lines);
}
$texts = [
    'scream' => ['انا مش عايز وجع دماغ يا واد انت <scream> ده انت مستفز جدا', 'انا مش عايز وجع دماغ يا واد انت. <scream> ده انت مستفز جدا!', ['scream']],
    'laugh' => ['يا سلام! <laugh> ما توقعت هذا أبداً. <sigh> بس خير.', 'يا سلام! <laugh> <laugh> ما توقعت هذا أبداً. <sigh> بس خير.', ['laugh', 'sigh']],
];

function speakStock(string $text, string $style, string $base, string $key): ?string
{
    $part = ['text' => $text];
    if ($style !== '') $part['speech_metadata'] = ['style' => $style];
    $r = Http::timeout(90)->withHeaders(['x-goog-api-key' => $key])->post($base.'/models/gemini-3.8-flash-tts:generateContent', [
        'contents' => [['role' => 'user', 'parts' => [$part]]],
        'generationConfig' => ['responseModalities' => ['AUDIO'], 'speechConfig' => ['voiceConfig' => ['voice' => 'Iapetus']]],
    ]);
    foreach ($r->json('candidates.0.content.parts') ?? [] as $p) {
        if (! empty($p['inlineData']['data'])) {
            $pcm = base64_decode($p['inlineData']['data']);
            return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
        }
    }
    fwrite(STDERR, 'stock error: '.mb_substr($r->body(), 0, 200)."\n");
    return null;
}
function speakCloned(string $text, string $style, string $voice, string $base, string $key): ?string
{
    $content = ['type' => 'text', 'text' => $text];
    if ($style !== '') $content['annotations'] = [['type' => 'speech_metadata', 'style' => $style]];
    $r = Http::timeout(90)->withHeaders(['x-goog-api-key' => $key])->post($base.'/interactions', [
        'model' => 'gemini-3.8-flash-tts',
        'input' => [['type' => 'user_input', 'content' => [$content]]],
        'response_format' => ['type' => 'audio', 'mime_type' => 'audio/wav', 'sample_rate' => 24000],
        'generation_config' => ['speech_config' => [['voice' => $voice]]],
    ]);
    $json = $r->json() ?? [];
    $find = function ($node) use (&$find) {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                if (in_array($k, ['data', 'audio_data'], true) && is_string($v) && strlen($v) > 1000) return $v;
                if (($f = $find($v)) !== null) return $f;
            }
        }
        return null;
    };
    $b64 = $find($json);
    if ($b64) return base64_decode($b64);
    fwrite(STDERR, 'cloned error: '.mb_substr($r->body(), 0, 200)."\n");
    return null;
}
function hear(string $bytes, string $base, string $key): array
{
    $prompt = 'Listen. Return JSON {"sounds": ["..."]}: every NON-SPEECH vocal sound actually performed (scream, laugh, sigh, gasp, cry, breath...), in order. Words are not sounds.';
    foreach (['gemini-3.5-flash', 'gemini-2.5-flash'] as $model) {
        $r = Http::timeout(120)->withHeaders(['x-goog-api-key' => $key])->post($base.'/models/'.$model.':generateContent', [
            'contents' => [['role' => 'user', 'parts' => [['inline_data' => ['mime_type' => 'audio/wav', 'data' => base64_encode($bytes)]], ['text' => $prompt]]]],
            'generationConfig' => ['responseMimeType' => 'application/json'],
        ]);
        $t = $r->json('candidates.0.content.parts.0.text');
        if ($r->ok() && is_string($t)) return json_decode($t, true)['sounds'] ?? [];
    }
    return ['?'];
}

$out = [];
foreach ($texts as $name => [$plain, $spaced, $tags]) {
    $variants = [
        'A as now' => [$plain, ''],
        'B perform instruction' => [$plain, performStyle($tags, $sounds)],
        'C instruction + own beat' => [$spaced, performStyle($tags, $sounds)],
    ];
    foreach ($variants as $vname => [$text, $style]) {
        foreach (['stock', 'cloned'] as $who) {
            if ($who === 'cloned' && ! $cloned) continue;
            $heard = [];
            for ($try = 1; $try <= 2; $try++) {
                $audio = $who === 'stock' ? speakStock($text, $style, $base, $key) : speakCloned($text, $style, (string) $cloned->provider_voice_key, $base, $key);
                $heard[] = $audio ? hear($audio, $base, $key) : ['no audio'];
            }
            $out[] = ['text' => $name, 'variant' => $vname, 'voice' => $who, 'wanted' => $tags, 'heard' => $heard];
            fwrite(STDERR, "$name | $vname | $who | ".json_encode($heard, JSON_UNESCAPED_UNICODE)."\n");
        }
    }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
