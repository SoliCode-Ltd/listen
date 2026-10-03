<?php

/*
 * Live check of the Chirp 3 HD standard voice engine against Google.
 *
 * Boots the Laravel app in the current directory and uses its own Google
 * credentials. Nothing is written to the database; the audio files the
 * provider saves are read back and deleted. Prints one JSON document with
 * the results and the audio (base64) so the caller can listen to it.
 *
 * The workflow pipes this file over SSH with StandardVoiceEngine's mapping
 * appended, so it checks the code in this branch even before it is deployed:
 *   cat scripts/tts/standard_voice_check.php <mapping> | ssh host 'cd app && php'
 */

use App\Services\Tts\GoogleCloudTtsProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

ini_set('display_errors', 'stderr');
set_time_limit(600);

$root = getcwd();
if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "Run this from the Laravel app root (no artisan file in {$root}).\n");
    exit(1);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$provider = app(GoogleCloudTtsProvider::class);
$mapper = class_exists('App\Support\StandardVoiceEngine') ? 'App\Support\StandardVoiceEngine' : 'StandardVoiceEngineUnderTest';

$out = [
    'meta' => [
        'generated_at' => now()->toIso8601String(),
        'app_env' => app()->environment(),
        'auth' => trim((string) config('services.google_tts.api_key', '')) !== '' ? 'api_key' : 'service_account',
        'audio_encoding' => (string) config('services.google_tts.audio_encoding', 'MP3'),
        'mapper' => $mapper,
    ],
    'voice_list' => [],
    'cases' => [],
];

// 1. Every Chirp voice the mapping can pick must exist in Google's list.
$headers = [];
$apiKey = trim((string) config('services.google_tts.api_key', ''));
if ($apiKey !== '') {
    $headers['X-Goog-Api-Key'] = $apiKey;
} else {
    $token = (new ReflectionMethod($provider, 'getGoogleAccessToken'));
    $token->setAccessible(true);
    $headers['Authorization'] = 'Bearer '.$token->invoke($provider);
}
foreach (['ar-XA', 'en-US'] as $locale) {
    try {
        $response = Http::timeout(30)->withHeaders($headers)
            ->get('https://texttospeech.googleapis.com/v1/voices', ['languageCode' => $locale]);
        $status = $response->status();
        $available = collect($response->json('voices', []))->pluck('name')->all();
    } catch (Throwable $e) {
        $status = 'error: '.mb_substr($e->getMessage(), 0, 200);
        $available = [];
    }
    $wanted = $mapper::allChirpVoices($locale);
    $out['voice_list'][$locale] = [
        'http_status' => $status,
        'chirp_voices_available' => count(array_filter($available, fn ($n) => str_contains($n, 'Chirp3-HD'))),
        'mapped_voices_missing' => array_values(array_diff($wanted, $available)),
    ];
}

// 2. Synthesize through the real provider. Chirp voices go in as already
//    mapped names on the HD path, which sends them to Google unchanged.
$arShort = 'مرحباً بكم في منصة إسمع. نحوّل نصوصكم إلى صوت طبيعي وواضح خلال ثوانٍ.';
$enShort = 'Welcome to Listen Studio. We turn your text into clear, natural speech in seconds.';
$arLong = trim(str_repeat('هذه فقرة تجريبية لقياس سرعة القراءة الطويلة، وتحتوي على جمل متتابعة بعلامات ترقيم مختلفة. ', 30));

$cases = [];
foreach (['ar-XA' => ['A', 'B', 'C', 'D'], 'en-US' => ['C', 'D']] as $locale => $letters) {
    foreach ($letters as $letter) {
        $wavenet = "{$locale}-Wavenet-{$letter}";
        $lang = substr($locale, 0, 2);
        $cases[] = ['name' => "{$wavenet} → Chirp", 'lang' => $lang, 'voice' => $mapper::chirpVoiceFor($wavenet, $lang), 'text' => $lang === 'ar' ? $arShort : $enShort, 'keep_audio' => true];
        $cases[] = ['name' => "{$wavenet} (WaveNet today)", 'lang' => $lang, 'voice' => $wavenet, 'text' => $lang === 'ar' ? $arShort : $enShort, 'keep_audio' => true, 'tier' => 'standard'];
    }
}
$aoede = $mapper::chirpVoiceFor('ar-XA-Wavenet-A', 'ar');
$cases[] = ['name' => 'Chirp slow speed 0.8', 'lang' => 'ar', 'voice' => $aoede, 'text' => $arShort, 'speed' => 0.8, 'keep_audio' => true];
$cases[] = ['name' => 'Chirp fast speed 1.3', 'lang' => 'ar', 'voice' => $aoede, 'text' => $arShort, 'speed' => 1.3, 'keep_audio' => true];
$cases[] = ['name' => 'Chirp with pitch +5 (ignored)', 'lang' => 'ar', 'voice' => $aoede, 'text' => $arShort, 'pitch' => 5.0];
$cases[] = ['name' => 'Chirp with unknown gender', 'lang' => 'ar', 'voice' => $aoede, 'text' => $arShort, 'gender' => ''];
$cases[] = ['name' => 'Chirp long Arabic ('.mb_strlen($arLong).' chars)', 'lang' => 'ar', 'voice' => $aoede, 'text' => $arLong];
$cases[] = ['name' => 'WaveNet long Arabic ('.mb_strlen($arLong).' chars)', 'lang' => 'ar', 'voice' => 'ar-XA-Wavenet-A', 'text' => $arLong, 'tier' => 'standard'];
// Every mapped voice must actually speak.
foreach (['ar-XA', 'en-US'] as $locale) {
    foreach ($mapper::allChirpVoices($locale) as $voice) {
        $cases[] = ['name' => "speaks: {$voice}", 'lang' => substr($locale, 0, 2), 'voice' => $voice, 'text' => str_starts_with($locale, 'ar') ? 'اختبار الصوت.' : 'Voice test.'];
    }
}

foreach ($cases as $case) {
    $started = microtime(true);
    $row = ['name' => $case['name'], 'voice' => $case['voice'], 'chars' => mb_strlen($case['text'])];
    try {
        $result = $provider->synthesize($case['text'], $case['lang'], array_filter([
            'voice_name' => $case['voice'],
            'tier' => $case['tier'] ?? 'hd',
            'gender' => array_key_exists('gender', $case) ? $case['gender'] : null,
            'speed' => $case['speed'] ?? null,
            'pitch' => $case['pitch'] ?? null,
        ], fn ($v) => $v !== null));
        $bytes = Storage::disk('public')->get($result['audio_path']);
        Storage::disk('public')->delete($result['audio_path']);
        $row += [
            'ok' => is_string($bytes) && strlen($bytes) > 1000,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'bytes' => strlen((string) $bytes),
            'cost_estimate_usd' => $result['cost_estimate_usd'] ?? null,
        ];
        if (! empty($case['keep_audio'])) {
            $row['audio_base64'] = base64_encode((string) $bytes);
        }
    } catch (Throwable $e) {
        $row += [
            'ok' => false,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'error' => mb_substr($e->getMessage(), 0, 500),
        ];
    }
    $out['cases'][] = $row;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
