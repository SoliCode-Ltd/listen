<?php

/*
 * Live check on STAGING that every dialect and accent sounds like itself.
 * The workflow injects the cases (scripts/tts/studio_options_cases.json),
 * built from this branch: the dialect rewrite prompt and the exact style
 * text. Each script is rewritten into its dialect, spoken by Gemini 3.8
 * (stock voice directly, cloned voice through the server's service), and a
 * second Gemini model listens WITHOUT being told the target: it names the
 * variety, then picks the closest one from that region's list.
 * Nothing is written; saved audio is deleted. Prints JSON with the audio.
 */

use App\Models\ClonedVoice;
use App\Services\Tts\GeminiKeyManager;
use App\Services\VoiceCloningService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

ini_set('display_errors', 'stderr');
set_time_limit(3300);
$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$cases = json_decode((string) base64_decode('__CASES__', true), true);
if (! is_array($cases) || $cases === []) {
    fwrite(STDERR, "The workflow did not inject the cases.\n");
    exit(1);
}
$key = (string) (app(GeminiKeyManager::class)->getAvailableKey()['key'] ?? '');
$base = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
$cloned = ClonedVoice::query()->whereKey((int) (getenv('CLONED_ID') ?: 13))->first();

function wav(string $pcm): string
{
    return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
}

function speak(array $case, string $base, string $key): string
{
    $part = ['text' => $case['text'], 'speech_metadata' => ['style' => $case['style_text']]];
    for ($try = 0; $try < 3; $try++) {
        $r = Http::timeout(120)->withHeaders(['x-goog-api-key' => $key])->post($base.'/models/gemini-3.8-flash-tts:generateContent', [
            'contents' => [['role' => 'user', 'parts' => [$part]]],
            'generationConfig' => ['responseModalities' => ['AUDIO'], 'speechConfig' => ['voiceConfig' => ['voice' => $case['voice']]]],
        ]);
        foreach ($r->json('candidates.0.content.parts') ?? [] as $p) {
            if (! empty($p['inlineData']['data'])) {
                return wav(base64_decode($p['inlineData']['data']));
            }
        }
        if ($r->status() === 429) {
            throw new RuntimeException('quota: '.mb_substr($r->body(), 0, 200));
        }
        sleep(2);
    }
    throw new RuntimeException('no audio: HTTP '.$r->status().' '.mb_substr($r->body(), 0, 200));
}

function rewrite(string $prompt, string $base, string $key): ?string
{
    foreach (['gemini-3.6-flash', 'gemini-3.5-flash', 'gemini-2.5-flash'] as $model) {
        $r = Http::timeout(60)->withHeaders(['x-goog-api-key' => $key])->post($base.'/models/'.$model.':generateContent', [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.2],
        ]);
        $t = $r->json('candidates.0.content.parts.0.text');
        if ($r->ok() && is_string($t) && trim($t) !== '') {
            $t = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($t));

            return trim((string) $t, "\"' \n");
        }
    }

    return null;
}

function hear(string $bytes, string $base, string $key, array $candidates): array
{
    $mime = str_starts_with($bytes, 'RIFF') ? 'audio/wav' : 'audio/mpeg';
    $list = implode('; ', array_map(fn ($c) => $c['code'].' = '.$c['name'], $candidates));
    $prompt = 'You are an expert dialectologist and voice director. Listen to this recording and return JSON: '
        .'{"transcript": "...", "accent_or_dialect": "first, in your own words, as specific as you can: country and city or region", '
        .'"closest": "the code of the closest variety from the list below", "second": "the next closest code", '
        .'"confidence": "high|medium|low", "why": "the sounds and words that decided it", "emotion": "...", "delivery_style": "...", "reads_instructions_aloud": true|false}. '
        .'Varieties: '.$list.'. Judge only from the voice and words you hear. '
        .'reads_instructions_aloud is true if the speaker says words like "voice accent", "emotional tone" or "performance style".';
    foreach (['gemini-3.5-flash', 'gemini-2.5-flash'] as $model) {
        $r = Http::timeout(120)->withHeaders(['x-goog-api-key' => $key])->post($base.'/models/'.$model.':generateContent', [
            'contents' => [['role' => 'user', 'parts' => [
                ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
                ['text' => $prompt],
            ]]],
            'generationConfig' => ['responseMimeType' => 'application/json'],
        ]);
        $t = $r->json('candidates.0.content.parts.0.text');
        if ($r->ok() && is_string($t)) {
            return (json_decode($t, true) ?: ['raw' => $t]) + ['judge' => $model];
        }
    }

    return ['error' => 'could not analyse'];
}

$out = ['meta' => ['generated_at' => now()->toIso8601String(), 'cloned_voice' => $cloned ? ['id' => $cloned->id, 'language' => $cloned->language_code] : null], 'cases' => []];
$disk = Storage::disk('public');
foreach ($cases as $case) {
    $row = ['target' => array_intersect_key($case, array_flip(['kind', 'dialect', 'style', 'emotion', 'voice'])), 'text' => $case['text']];
    try {
        if (! empty($case['rewrite_prompt'])) {
            $spoken = rewrite($case['rewrite_prompt'], $base, $key);
            if ($spoken !== null && $spoken !== '') {
                $case['text'] = $spoken;
            }
            $row['spoken'] = $case['text'];
        }
        if ($case['kind'] === 'stock') {
            $bytes = speak($case, $base, $key);
        } else {
            if (! $cloned) {
                throw new RuntimeException('cloned voice not found');
            }
            $svc = app(VoiceCloningService::class);
            $result = $svc->synthesizeWithClonedVoice($case['text'], (string) $cloned->language_code, (string) $cloned->provider_voice_key, $case['style_text']);
            $bytes = (string) $result['audio_content'];
            $disk->delete($result['audio_path']);
        }
        $row['heard'] = hear($bytes, $base, $key, $case['candidates'] ?? []);
        $row['audio_base64'] = base64_encode($bytes);
    } catch (Throwable $e) {
        $row['error'] = mb_substr($e->getMessage(), 0, 400);
    }
    $out['cases'][] = $row;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
