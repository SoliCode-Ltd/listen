<?php

/*
 * Live check on STAGING that tags are performed, through the real services.
 * For each case: the text after the dialect rewrite (cloned voices), what
 * is sent, and what a Gemini model hears in the result (non-speech sounds
 * with times, and whether parts are whispered). Audio files the services
 * save are read back and deleted. Prints JSON with the audio as base64.
 */

use App\Models\ClonedVoice;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Services\VoiceCloningService;
use App\Support\GeminiVocalTags;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

ini_set('display_errors', 'stderr');
set_time_limit(900);
$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$key = (string) (app(GeminiKeyManager::class)->getAvailableKey()['key'] ?? '');
$base = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
$cloned = ClonedVoice::query()->whereKey((int) (getenv('CLONED_ID') ?: 13))->first();

function hear(string $bytes, string $base, string $key): array
{
    $mime = str_starts_with($bytes, 'RIFF') ? 'audio/wav' : 'audio/mpeg';
    $prompt = 'Listen carefully. Return JSON: {"transcript": "...", "sounds": [{"sound": "laugh|chuckle|scream|shout|sigh|gasp|breath|cough|cry|whisper|yawn|groan|other", "second": 0.0, "note": "..."}], "whispered_parts": "..."}. '
        .'List every NON-SPEECH vocal sound actually performed (laughing, screaming, sighing, gasping, breathing, coughing, crying, yawning), in order. '
        .'Do not list words that merely describe a sound. If a tag name is read aloud as a word (for example the word scream or <scream>), say so in note.';
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
            return json_decode($t, true) ?: ['raw' => $t];
        }
    }

    return ['error' => 'could not analyse'];
}

$cases = [
    ['name' => 'stock scream (ar)', 'kind' => 'stock', 'voice' => 'Iapetus', 'dialect' => 'ar-EG', 'text' => 'انا مش عايز وجع دماغ يا واد انت <صرخة> ده انت مستفز جدا'],
    ['name' => 'stock laugh + sigh (ar)', 'kind' => 'stock', 'voice' => 'Iapetus', 'dialect' => 'ar-EG', 'text' => 'يا سلام! <ضحكة> ما توقعت هذا أبداً. <تنهيدة> بس خير.'],
    ['name' => 'stock laugh (en)', 'kind' => 'stock', 'voice' => 'Kore', 'dialect' => 'en-US', 'lang' => 'en', 'text' => 'That was so funny. <laugh> I cannot believe it. <sigh> Anyway.'],
    ['name' => 'cloned scream, Saidi', 'kind' => 'cloned', 'dialect' => 'ar-EG-SA', 'text' => 'انا مش عايز وجع دماغ يا واد انت <صرخة> ده انت مستفز جدا'],
    ['name' => 'cloned laugh, no dialect', 'kind' => 'cloned', 'dialect' => null, 'text' => 'يا سلام! <ضحكة> ما توقعت هذا أبداً. <تنهيدة> بس خير.'],
];

$out = ['meta' => ['generated_at' => now()->toIso8601String(), 'cloned_voice' => $cloned ? ['id' => $cloned->id, 'status' => $cloned->status, 'language' => $cloned->language_code] : null], 'cases' => []];
$disk = Storage::disk('public');

foreach ($cases as $case) {
    $row = ['name' => $case['name'], 'input' => $case['text']];
    try {
        if ($case['kind'] === 'stock') {
            $result = app(GeminiTtsProvider::class)->synthesize($case['text'], $case['lang'] ?? 'ar', [
                'voice_name' => $case['voice'], 'dialect' => $case['dialect'], 'performance_style' => 'neutral', 'emotion' => 'neutral',
            ]);
            $row['sent_parts'] = GeminiVocalTags::parts($case['text']);
            $bytes = (string) $disk->get($result['audio_path']);
            $disk->delete($result['audio_path']);
        } else {
            if (! $cloned) {
                throw new RuntimeException('cloned voice not found');
            }
            $svc = app(VoiceCloningService::class);
            $text = $svc->speakableText($case['text'], $case['dialect']);
            $row['after_dialect_rewrite'] = $text;
            $row['sent_parts'] = GeminiVocalTags::parts($text);
            $result = $svc->synthesizeWithClonedVoice($text, (string) $cloned->language_code, (string) $cloned->provider_voice_key, $svc->deliveryStyle($case['dialect'], 'neutral', 'neutral', null, 1.0, 0.0));
            $bytes = (string) $result['audio_content'];
            $disk->delete($result['audio_path']);
        }
        $row['bytes'] = strlen($bytes);
        $row['heard'] = hear($bytes, $base, $key);
        $row['audio_base64'] = base64_encode($bytes);
    } catch (Throwable $e) {
        $row['error'] = mb_substr($e->getMessage(), 0, 400);
    }
    $out['cases'][] = $row;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
