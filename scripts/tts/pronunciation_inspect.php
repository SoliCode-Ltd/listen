<?php

/*
 * Read-only, on staging: the admin account's latest voices, its pronunciations,
 * what each voice would be sent now, whether the server runs the code that
 * applies them, and a transcript of each voice (a text model listens to the
 * file; no audio is made). Nothing is written.
 */

use App\Models\AudioGeneration;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

ini_set('display_errors', 'stderr');
$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$shell = function (string $command): ?string {
    return function_exists('shell_exec') ? trim((string) @shell_exec($command.' 2>/dev/null')) : null;
};
$job = $root.'/app/Jobs/GenerateAudioJob.php';
$out = ['meta' => [
    'generated_at' => now()->toIso8601String(),
    'job_file_changed_at' => is_file($job) ? date('c', filemtime($job)) : null,
    'job_file_applies_pronunciations' => is_file($job) && str_contains((string) file_get_contents($job), 'SpeechText::forVoice'),
    'provider_file_names_words' => str_contains((string) @file_get_contents($root.'/app/Services/Tts/GeminiTtsProvider.php'), 'Pronunciations::direction'),
    'pronunciations_table' => Schema::hasTable('pronunciations'),
    'smoothing_on' => class_exists(\App\Support\SpeechText::class) ? \App\Support\SpeechText::enabled() : null,
    'queue_connection' => config('queue.default'),
    'dialect_rewrite' => config('services.gemini_tts.dialect_rewrite', true),
    'speech_model' => class_exists(\App\Support\GeminiTtsModel::class) ? \App\Support\GeminiTtsModel::resolve() : null,
    'php_cli' => PHP_VERSION,
    'opcache_ini_files' => $shell('ls /etc/php/*/fpm/conf.d/ 2>/dev/null | grep -i opcache; grep -rhs "opcache.validate_timestamps\|opcache.revalidate_freq" /etc/php/*/fpm/ /etc/php/*/mods-available/ | sort -u'),
    'fpm_started' => $shell("ps -eo lstart,args | grep -E 'php-fpm: master' | grep -v grep | head -3"),
    'workers' => $shell("ps -eo lstart,args | grep -E 'queue:(work|listen)' | grep -v grep | head -5"),
]];

$admins = User::query()->where('is_admin', true)->pluck('id');
$userId = (int) AudioGeneration::query()->whereIn('user_id', $admins)->latest('id')->value('user_id');
$out['user_id'] = $userId;
$out['pronunciations'] = Schema::hasTable('pronunciations')
    ? \App\Models\Pronunciation::query()->where('user_id', $userId)->get(['id', 'word', 'word_key', 'spoken', 'created_at', 'updated_at'])
        ->map(fn ($p) => $p->toArray() + ['spoken_codepoints' => implode(' ', array_map(fn ($c) => sprintf('U+%04X', mb_ord($c)), mb_str_split((string) $p->spoken)))])->all()
    : null;
// Which deploy is on the server: the panel script's newest feature and the last-letter note.
$out['meta']['panel_has_letters'] = str_contains((string) @file_get_contents($root.'/public/js/voice-studio.js'), 'pronLetters');

$key = (string) (app(\App\Services\Tts\GeminiKeyManager::class)->getAvailableKey()['key'] ?? '');
$base = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');

foreach (AudioGeneration::query()->where('user_id', $userId)->latest('id')->limit(5)->get() as $g) {
    $text = (string) $g->input_text;
    $row = [
        'id' => $g->id,
        'created_at' => (string) $g->created_at,
        'updated_at' => (string) $g->updated_at,
        'status' => $g->status,
        'voice_tier' => $g->voice_tier,
        'voice_name' => $g->voice_name,
        'dialect' => $g->dialect,
        'emotion' => $g->emotion,
        'performance_style' => $g->performance_style,
        'style_prompt' => $g->style_prompt,
        'speed' => $g->speed,
        'pitch' => $g->pitch,
        'provider' => $g->tts_provider,
        'model' => $g->tts_model,
        'duration' => $g->duration_seconds,
        'input_text' => $text,
        'snapshot' => $g->settings_snapshot,
    ];
    if (class_exists(\App\Support\SpeechText::class)) {
        $now = \App\Support\SpeechText::forVoice($text, $g->target_language, $userId);
        $row['would_read_now'] = $now['text'];
        $row['would_keep'] = $now['keep'];
        $row['would_direct'] = \App\Support\Pronunciations::direction($now['text'], $now['keep']);
    }
    $path = (string) $g->audio_path;
    if ($key !== '' && $path !== '' && Storage::disk('public')->exists($path)) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = ['mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4'][$ext] ?? 'audio/mpeg';
        try {
            $response = Http::timeout(90)->withHeaders(['x-goog-api-key' => $key])
                ->post($base.'/models/gemini-3.5-flash:generateContent', [
                    'contents' => [['role' => 'user', 'parts' => [
                        ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode(Storage::disk('public')->get($path))]],
                        ['text' => 'Listen to this Arabic audio. Return JSON with: "transcript" (exactly what is said, word for word, '
                            .'with full Arabic vowel marks on every word as it was pronounced), "malak_word" (how the word written ملك '
                            .'was pronounced: "malak" (a name, مَلَك), "malik" (king, مَلِك), "mulk", "milk" or "other"), and '
                            .'"delivery" (one short line: tone, pace and energy of the voice).'],
                    ]]],
                    'generationConfig' => ['responseMimeType' => 'application/json'],
                ]);
            $raw = (string) $response->json('candidates.0.content.parts.0.text');
            $row['heard'] = json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', $raw)), true) ?? $raw;
        } catch (\Throwable $e) {
            $row['heard'] = 'transcription failed: '.get_class($e);
        }
    }
    $out['generations'][] = $row;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
