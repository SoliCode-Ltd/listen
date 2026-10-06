<?php

/*
 * Read-only check of the sign-in page samples (voice_samples, category "hero").
 *
 * For each active sample: the stored English and Arabic text, the audio file
 * size and length, and what is actually said in the audio, transcribed by a
 * Gemini text model. Nothing is written. Prints one JSON document.
 */

use App\Models\VoiceSample;
use App\Services\Tts\GeminiKeyManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

ini_set('display_errors', 'stderr');
set_time_limit(600);

$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$key = (string) (app(GeminiKeyManager::class)->getAvailableKey()['key'] ?? '');
$base = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
$only = trim((string) getenv('ONLY_LANGUAGE'));

$out = ['meta' => ['generated_at' => now()->toIso8601String(), 'app_url' => config('app.url')], 'samples' => []];

$query = VoiceSample::query()->where('category', 'hero')->orderBy('language')->orderBy('sort_order');
if ($only !== '') {
    $query->where('language', $only);
}

foreach ($query->get() as $sample) {
    $path = ltrim((string) $sample->audio_path, '/');
    $row = [
        'id' => $sample->id,
        'active' => (bool) $sample->is_active,
        'featured' => (bool) $sample->is_featured,
        'language' => $sample->language,
        'dialect' => $sample->dialect,
        'voice' => $sample->voice_name,
        'provider' => $sample->provider,
        'audio_path' => $path,
        'updated_at' => (string) $sample->updated_at,
        'sample_text' => $sample->sample_text,
        'sample_text_ar' => $sample->sample_text_ar,
    ];

    $disk = Storage::disk('public');
    if ($path === '' || ! $disk->exists($path)) {
        $row['audio_error'] = 'file missing';
        $out['samples'][] = $row;
        continue;
    }
    $bytes = $disk->get($path);
    $row['audio_bytes'] = strlen($bytes);
    $row['audio_modified'] = date('c', $disk->lastModified($path));
    $mime = str_starts_with($bytes, 'RIFF') ? 'audio/wav' : 'audio/mpeg';

    if ($sample->is_active && $key !== '') {
        foreach (['gemini-3.5-flash', 'gemini-2.5-flash'] as $model) {
            try {
                $response = Http::timeout(90)->withHeaders(['x-goog-api-key' => $key])->post($base.'/models/'.$model.':generateContent', [
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [
                            ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
                            ['text' => 'Transcribe exactly what is said in this audio, word for word, in the language spoken. Return only the transcript.'],
                        ],
                    ]],
                ]);
                $text = $response->json('candidates.0.content.parts.0.text');
                if ($response->ok() && is_string($text) && trim($text) !== '') {
                    $row['spoken'] = trim($text);
                    $row['transcribed_by'] = $model;
                    break;
                }
                $row['transcribe_error'] = $model.': HTTP '.$response->status().' '.mb_substr($response->body(), 0, 200);
            } catch (Throwable $e) {
                $row['transcribe_error'] = $model.': '.mb_substr($e->getMessage(), 0, 200);
            }
        }
    }

    $out['samples'][] = $row;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
