<?php

/*
 * Live check of Gemini 3.8 sound tags (<laugh>, <short pause>, ...).
 *
 * Boots the Laravel app in the current directory and uses its own Gemini
 * key. Nothing is written to the database or to storage. Each case sends
 * the request the provider builds and reports the HTTP status, finish
 * reason and audio length; the audio comes back as base64 so it can be
 * listened to.
 *
 * The workflow replaces __UNDER_TEST__ with this branch's GeminiVocalTags,
 * moved to the ListenUnderTest namespace, so the check runs the new code
 * even on a server that has not deployed it.
 */

use App\Services\Tts\GeminiKeyManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

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

$underTest = base64_decode('__UNDER_TEST__', true);
if (! is_string($underTest) || ! str_contains($underTest, 'namespace ListenUnderTest;')) {
    fwrite(STDERR, "The workflow did not inject the code under test.\n");
    exit(1);
}
$file = tempnam(sys_get_temp_dir(), 'tagcheck').'.php';
file_put_contents($file, $underTest);
require $file;
unlink($file);

$tags = 'ListenUnderTest\GeminiVocalTags';
$model = 'gemini-3.8-flash-tts';
$key = app(GeminiKeyManager::class)->getAvailableKey();
$endpoint = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/')
    .'/models/'.$model.':generateContent';

$cases = [
    ['name' => 'en plain', 'voice' => 'Kore', 'text' => 'That was close. Let us go home now.'],
    ['name' => 'en tagged', 'voice' => 'Kore', 'text' => 'That was close. <phew> <long pause> Let us go home now. <laugh>'],
    ['name' => 'en bracket style', 'voice' => 'Puck', 'text' => '[excited] We won the match! [laughs] I cannot believe it. [sighs] What a day.'],
    ['name' => 'ar plain', 'voice' => 'Kore', 'text' => 'يا سلام! ما توقعت هذا أبداً. يلا بينا.'],
    ['name' => 'ar tagged', 'voice' => 'Kore', 'text' => 'يا سلام! <laugh> ما توقعت هذا أبداً. <short pause> يلا بينا. <sigh>'],
    ['name' => 'ar whisper and gasp', 'voice' => 'Charon', 'text' => '<gasp> سمعت هذا الصوت؟ <whispers> لا تتكلم، في أحد هنا.'],
];

$out = [
    'meta' => [
        'generated_at' => now()->toIso8601String(),
        'app_env' => app()->environment(),
        'model' => $model,
        'key_name' => $key['name'] ?? null,
    ],
    'cases' => [],
];

foreach ($cases as $case) {
    $prepared = $tags::prepare($case['text']);
    $style = $tags::withCues('Read naturally and clearly.', $prepared['cues']);
    $payload = [
        'contents' => [['role' => 'user', 'parts' => [['text' => $prepared['text'], 'speech_metadata' => ['style' => $style]]]]],
        'generationConfig' => [
            'responseModalities' => ['AUDIO'],
            'speechConfig' => ['voiceConfig' => ['voice' => $case['voice']]],
        ],
    ];

    $row = ['name' => $case['name'], 'voice' => $case['voice'], 'sent_text' => $prepared['text'], 'style' => $style, 'ok' => false];
    $started = microtime(true);
    try {
        $response = Http::timeout(90)
            ->withHeaders(['Content-Type' => 'application/json', 'x-goog-api-key' => (string) ($key['key'] ?? '')])
            ->post($endpoint, $payload);
        $row['http_status'] = $response->status();
        $json = $response->json() ?? [];
        $row['finish_reason'] = $json['candidates'][0]['finishReason'] ?? null;
        $inline = null;
        foreach ($json['candidates'][0]['content']['parts'] ?? [] as $part) {
            $inline ??= $part['inlineData'] ?? null;
        }
        if ($inline && ! empty($inline['data'])) {
            $bytes = base64_decode($inline['data']);
            $row['mime_type'] = $inline['mimeType'] ?? null;
            $row['bytes'] = strlen($bytes);
            $rate = preg_match('/rate=(\d+)/', (string) $row['mime_type'], $m) ? (int) $m[1] : 24000;
            $pcm = str_starts_with($bytes, 'RIFF') ? substr($bytes, 44) : $bytes;
            $row['audio_seconds'] = round(strlen($pcm) / (2 * $rate), 2);
            $row['sample_rate'] = $rate;
            $row['pcm_base64'] = base64_encode($pcm);
            $row['ok'] = $response->ok() && $row['audio_seconds'] > 0.5;
        } else {
            $row['error'] = mb_substr($response->body(), 0, 400);
        }
    } catch (\Throwable $e) {
        $row['error'] = mb_substr($e->getMessage(), 0, 400);
    }
    $row['latency_ms'] = (int) round((microtime(true) - $started) * 1000);
    $out['cases'][] = $row;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
