<?php

/*
 * Live check of the Chirp 3 HD standard voice engine against Google.
 *
 * Boots the Laravel app in the current directory and uses its own Google
 * credentials. Nothing is written to the database; the audio files the
 * provider saves are read back and deleted. Prints one JSON document with
 * the results and some of the audio (base64) so it can be listened to.
 *
 * The workflow replaces __UNDER_TEST__ with this branch's
 * StandardVoiceEngine and GoogleCloudTtsProvider, moved to the
 * ListenUnderTest namespace, so the check runs the new code even on a
 * server that has not deployed it. The engine copy reads its choice from
 * $GLOBALS['standard_engine'] instead of the database.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

ini_set('display_errors', 'stderr');
set_time_limit(900);

$root = getcwd();
if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "Run this from the Laravel app root (no artisan file in {$root}).\n");
    exit(1);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$underTest = base64_decode('__UNDER_TEST__', true);
if (! is_string($underTest) || ! str_contains($underTest, 'namespace ListenUnderTest;')) {
    fwrite(STDERR, "The workflow did not inject the code under test.\n");
    exit(1);
}
$file = tempnam(sys_get_temp_dir(), 'voicecheck').'.php';
file_put_contents($file, $underTest);
require $file;
unlink($file);

$engine = 'ListenUnderTest\StandardVoiceEngine';
$provider = new ListenUnderTest\GoogleCloudTtsProvider;

$out = [
    'meta' => [
        'generated_at' => now()->toIso8601String(),
        'app_env' => app()->environment(),
        'auth' => trim((string) config('services.google_tts.api_key', '')) !== '' ? 'api_key' : 'service_account',
        'audio_encoding' => (string) config('services.google_tts.audio_encoding', 'LINEAR16'),
        'parallel_requests' => (int) config('services.google_tts.max_parallel_requests', 4),
    ],
    'voice_list' => [],
    'cases' => [],
];

// 1. Every Chirp voice the mapping can pick must exist in Google's list.
$apiKey = trim((string) config('services.google_tts.api_key', ''));
if ($apiKey !== '') {
    $headers = ['X-Goog-Api-Key' => $apiKey];
} else {
    $token = new ReflectionMethod($provider, 'getGoogleAccessToken');
    $token->setAccessible(true);
    $headers = ['Authorization' => 'Bearer '.$token->invoke($provider)];
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
    $out['voice_list'][$locale] = [
        'http_status' => $status,
        'chirp_voices_available' => count(array_filter($available, fn ($n) => str_contains($n, 'Chirp3-HD'))),
        'mapped_voices_missing' => array_values(array_diff($engine::allChirpVoices($locale), $available)),
    ];
}

// 2. Synthesize through this branch's provider, exactly as a Standard
//    generation does: tier "standard" and the WaveNet voice the user picked.
$arShort = 'مرحباً بكم في منصة إسمع. نحوّل نصوصكم إلى صوت طبيعي وواضح خلال ثوانٍ.';
$enShort = 'Welcome to Listen Studio. We turn your text into clear, natural speech in seconds.';
$arLong = trim(str_repeat('هذه فقرة تجريبية لقياس سرعة القراءة الطويلة، وتحتوي على جمل متتابعة بعلامات ترقيم مختلفة. ', 30));
$arVeryLong = trim(str_repeat('هذه فقرة تجريبية لقياس سرعة القراءة الطويلة جداً، كما يحدث في الخطط المدفوعة. ', 160));

$cases = [];
foreach (['ar-XA' => ['A', 'B', 'C', 'D'], 'en-US' => ['C', 'D']] as $locale => $letters) {
    foreach ($letters as $letter) {
        $lang = substr($locale, 0, 2);
        $voice = "{$locale}-Wavenet-{$letter}";
        foreach (['chirp3_hd' => 'Chirp 3 HD', 'wavenet' => 'WaveNet'] as $mode => $label) {
            $cases[] = ['name' => "{$voice} via {$label}", 'engine' => $mode, 'lang' => $lang, 'voice' => $voice, 'text' => $lang === 'ar' ? $arShort : $enShort, 'keep_audio' => true];
        }
    }
}
$cases[] = ['name' => 'Chirp, speed 0.8', 'engine' => 'chirp3_hd', 'lang' => 'ar', 'voice' => 'ar-XA-Wavenet-A', 'text' => $arShort, 'speed' => 0.8, 'keep_audio' => true];
$cases[] = ['name' => 'Chirp, speed 1.3', 'engine' => 'chirp3_hd', 'lang' => 'ar', 'voice' => 'ar-XA-Wavenet-A', 'text' => $arShort, 'speed' => 1.3, 'keep_audio' => true];
$cases[] = ['name' => 'Chirp, pitch +5 (ignored)', 'engine' => 'chirp3_hd', 'lang' => 'ar', 'voice' => 'ar-XA-Wavenet-A', 'text' => $arShort, 'pitch' => 5.0];
$cases[] = ['name' => 'Chirp, blank gender', 'engine' => 'chirp3_hd', 'lang' => 'ar', 'voice' => 'ar-XA-Wavenet-A', 'text' => $arShort, 'gender' => ''];
$cases[] = ['name' => 'WaveNet, blank gender', 'engine' => 'wavenet', 'lang' => 'ar', 'voice' => 'ar-XA-Wavenet-A', 'text' => $arShort, 'gender' => ''];
$cases[] = ['name' => 'Chirp, no voice chosen (fallback)', 'engine' => 'chirp3_hd', 'lang' => 'ar', 'voice' => '', 'text' => $arShort];
$cases[] = ['name' => 'Chirp, long Arabic', 'engine' => 'chirp3_hd', 'lang' => 'ar', 'voice' => 'ar-XA-Wavenet-A', 'text' => $arLong, 'keep_audio' => true];
$cases[] = ['name' => 'WaveNet, long Arabic', 'engine' => 'wavenet', 'lang' => 'ar', 'voice' => 'ar-XA-Wavenet-A', 'text' => $arLong];
$cases[] = ['name' => 'Chirp, very long Arabic', 'engine' => 'chirp3_hd', 'lang' => 'ar', 'voice' => 'ar-XA-Wavenet-B', 'text' => $arVeryLong];
// Every mapped voice must actually speak.
foreach (['ar-XA', 'en-US'] as $locale) {
    foreach ($engine::allChirpVoices($locale) as $voice) {
        $cases[] = ['name' => 'speaks', 'engine' => 'chirp3_hd', 'tier' => 'hd', 'lang' => substr($locale, 0, 2), 'voice' => $voice, 'text' => str_starts_with($locale, 'ar') ? 'اختبار الصوت.' : 'Voice test.'];
    }
}

$sent = [];
Http::globalRequestMiddleware(function ($request) use (&$sent) {
    if (str_contains((string) $request->getUri(), 'text:synthesize')) {
        $body = json_decode((string) $request->getBody(), true);
        $sent[] = [
            'name' => $body['voice']['name'] ?? null,
            'gender' => $body['voice']['ssmlGender'] ?? null,
            'pitch' => $body['audioConfig']['pitch'] ?? null,
        ];
    }

    return $request;
});

foreach ($cases as $case) {
    $GLOBALS['standard_engine'] = $case['engine'];
    $sent = [];
    $started = microtime(true);
    $row = ['name' => $case['name'], 'engine' => $case['engine'], 'picked' => $case['voice'], 'chars' => mb_strlen($case['text'])];
    try {
        $result = $provider->synthesize($case['text'], $case['lang'], array_filter([
            'voice_name' => $case['voice'],
            'tier' => $case['tier'] ?? 'standard',
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
            'requests' => count($sent),
            'voice' => $sent[0]['name'] ?? null,
            'ssml_gender' => $sent[0]['gender'] ?? null,
            'pitch_sent' => $sent[0]['pitch'] ?? null,
            'cost_estimate_usd' => $result['cost_estimate_usd'] ?? null,
        ];
        if (! empty($case['keep_audio'])) {
            $row['audio_base64'] = base64_encode((string) $bytes);
        }
    } catch (Throwable $e) {
        $row += [
            'ok' => false,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'voice' => $sent[0]['name'] ?? null,
            'error' => mb_substr($e->getMessage(), 0, 500),
        ];
    }
    $out['cases'][] = $row;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
