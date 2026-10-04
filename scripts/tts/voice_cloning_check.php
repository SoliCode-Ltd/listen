<?php

/*
 * Voice cloning health check for STAGING. Piped into the app's own PHP over SSH:
 *
 *   ssh host 'cd app && php' < scripts/tts/voice_cloning_check.php
 *
 * The database session is READ ONLY and the cache is in memory, so nothing is
 * written to the app. Output is JSON with ids, counts and statuses only: no
 * names, emails, keys or recordings. With CLONE_SYNTH=1 each ready cloned voice
 * also speaks short Arabic (Fusha, Saudi, Egyptian) and English (US, UK) lines
 * plus long two-piece scripts; Gemini transcribes every clip so the words can
 * be checked, and the audio comes back (base64) to listen to. The files the
 * synthesis writes are deleted again.
 */

use App\Models\ClonedVoice;
use App\Services\VoiceCloningService;
use App\Support\VoiceCloningAccess;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

ini_set('display_errors', 'stderr');

$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['cache.default' => 'array']);
if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}

$service = app(VoiceCloningService::class);
$out = [];

$disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));
$ffmpeg = null;
foreach (array_filter([trim((string) config('services.gemini_tts.ffmpeg_path', '')), '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg']) as $candidate) {
    if (@is_file($candidate) && @is_executable($candidate)) {
        $ffmpeg = $candidate;
        break;
    }
}
$out['environment'] = [
    'php' => PHP_VERSION,
    'exec_enabled' => function_exists('exec') && ! in_array('exec', $disabled, true),
    'ffmpeg_found' => $ffmpeg !== null,
    'cli_upload_max_filesize' => ini_get('upload_max_filesize'),
    'cli_post_max_size' => ini_get('post_max_size'),
    'queue_connection' => config('queue.default'),
    'audio_processing' => config('listen.audio.processing'),
    'gemini_keys' => $service->configuredKeyCount(),
    'voice_proxy' => trim((string) config('services.gemini_tts.voice_proxy', '')) !== '',
    'cloning_enabled' => VoiceCloningService::isEnabled(),
    'audience' => VoiceCloningAccess::audience(),
    'sample_seconds' => [VoiceCloningService::minSampleDuration(), VoiceCloningService::maxSampleDuration()],
    'max_upload_mb' => VoiceCloningService::maxUploadSizeMb(),
    'has_stall_sweep' => method_exists(VoiceCloningService::class, 'failIfStalled'),
];

$probe = $service->probeVoicesApi();
$out['voices_api'] = ['ok' => $probe['ok'], 'status' => $probe['status'], 'body' => $probe['ok'] ? null : mb_substr($probe['body'], 0, 600)];

// Database state.
$rows = ClonedVoice::withTrashed()->get();
$now = now();
$out['db'] = [
    'total' => $rows->count(),
    'trashed' => $rows->filter->trashed()->count(),
    'by_status' => $rows->reject->trashed()->countBy('status')->all(),
    'failure_codes' => $rows->reject->trashed()->where('status', 'failed')
        ->countBy(fn ($v) => (string) (data_get($v->metadata, 'failure_code') ?: 'none'))->all(),
    'stuck_over_20_min' => $rows->reject->trashed()
        ->filter(fn ($v) => $v->isProcessing() && $v->updated_at && $v->updated_at->lt($now->copy()->subMinutes(20)))
        ->map(fn ($v) => ['id' => $v->id, 'status' => $v->status, 'minutes_idle' => (int) $v->updated_at->diffInMinutes($now, true)])
        ->values()->all(),
    'source_extensions' => $rows->countBy(fn ($v) => strtolower(pathinfo((string) $v->source_audio_path, PATHINFO_EXTENSION)) ?: 'none')->all(),
    'sample_seconds_range' => [$rows->min('source_audio_duration_seconds'), $rows->max('source_audio_duration_seconds')],
    'recordings_missing' => $rows->reject->trashed()
        ->filter(fn ($v) => ! filled($v->source_audio_path) || ! Storage::disk('local')->exists((string) $v->source_audio_path)
            || ! filled($v->consent_audio_path) || ! Storage::disk('local')->exists((string) $v->consent_audio_path))
        ->pluck('id')->values()->all(),
    'trashed_with_recordings_left' => $rows->filter->trashed()
        ->filter(fn ($v) => (filled($v->source_audio_path) && Storage::disk('local')->exists((string) $v->source_audio_path))
            || (filled($v->consent_audio_path) && Storage::disk('local')->exists((string) $v->consent_audio_path)))
        ->pluck('id')->values()->all(),
];

// Gemini side: voices this app created carry "· L{id}-" in their display name.
if ($probe['ok']) {
    $stored = $service->listStoredVoices(10);
    $storedIds = array_column($stored, 'id');
    $byRow = [];
    $untagged = 0;
    foreach ($stored as $voice) {
        if (preg_match('/ · L(\d+)-[a-z0-9]{5}$/u', $voice['display_name'], $m)) {
            $byRow[(int) $m[1]][] = $voice['id'];
        } else {
            $untagged++;
        }
    }
    $orphans = [];
    foreach ($byRow as $rowId => $voiceIds) {
        $row = $rows->firstWhere('id', $rowId);
        foreach ($voiceIds as $voiceId) {
            $inUse = $row && ! $row->trashed() && $row->provider_voice_key === $voiceId;
            if (! $inUse) {
                $orphans[] = ['row' => $rowId, 'row_state' => $row ? ($row->trashed() ? 'deleted' : $row->status) : 'missing'];
            }
        }
    }
    $out['gemini_side'] = [
        'stored_voices' => count($stored),
        'untagged' => $untagged,
        'rows_with_more_than_one_voice' => count(array_filter($byRow, fn ($ids) => count($ids) > 1)),
        'orphans' => $orphans,
        'ready_rows_missing_on_gemini' => $rows->reject->trashed()
            ->filter(fn ($v) => $v->status === 'ready' && filled($v->provider_voice_key) && ! in_array($v->provider_voice_key, $storedIds, true))
            ->pluck('id')->values()->all(),
    ];
}

// Optional live synthesis: every ready voice speaks Arabic and English in
// several dialects, the way a generation does (dialect rewrite, then delivery
// style), and Gemini transcribes each clip back so the words can be compared.
$out['synthesis'] = [];
$readyVoices = $rows->reject->trashed()->filter(fn ($v) => $v->isReady())->sortByDesc('id')->take(3);
$out['ready_voice_languages'] = $readyVoices->pluck('language_code')->values()->all();

$requestMethod = new ReflectionMethod($service, 'request');
$requestMethod->setAccessible(true);
$transcribe = function (string $audio, string $mime) use ($service, $requestMethod): array {
    $prompt = 'Transcribe the speech in this audio exactly, in the script it is spoken in. '
        .'Reply with JSON only: {"transcript": "...", "language": "...", "accent_or_dialect": "..."}';
    foreach (['gemini-3.5-flash', 'gemini-3.6-flash'] as $model) {
        try {
            $response = $requestMethod->invoke($service, 'POST', '/models/'.$model.':generateContent', [
                'contents' => [['role' => 'user', 'parts' => [
                    ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($audio)]],
                    ['text' => $prompt],
                ]]],
                'generationConfig' => ['temperature' => 0, 'responseMimeType' => 'application/json'],
            ], 60);
        } catch (\Throwable $e) {
            return ['error' => mb_substr($e->getMessage(), 0, 200)];
        }
        if ($response->successful()) {
            $raw = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');
            $json = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', $raw)), true);

            return is_array($json) ? $json + ['model' => $model] : ['raw' => mb_substr($raw, 0, 400), 'model' => $model];
        }
        if ($response->status() !== 404) {
            return ['error' => 'HTTP '.$response->status().': '.mb_substr((string) $response->body(), 0, 200)];
        }
    }

    return ['error' => 'no transcription model available'];
};

if (getenv('CLONE_SYNTH') === '1' && $readyVoices->isNotEmpty() && $probe['ok']) {
    $arText = 'مرحباً بكم في إسمع. اليوم نجرّب الصوت المستنسخ باللغة العربية.';
    $enText = 'Welcome to Listen. Today we are testing the cloned voice in English.';
    $cases = [
        ['name' => 'ar_fusha', 'text' => $arText, 'dialect' => 'ar-XA'],
        ['name' => 'ar_saudi', 'text' => $arText, 'dialect' => 'ar-SA'],
        ['name' => 'ar_egyptian', 'text' => $arText, 'dialect' => 'ar-EG'],
        ['name' => 'en_us', 'text' => $enText, 'dialect' => 'en-US'],
        ['name' => 'en_gb', 'text' => $enText, 'dialect' => 'en-GB'],
        // Four different sentences, so a piece dropped in the join cannot hide
        // behind a repeated one (transcribers collapse identical repeats).
        ['name' => 'ar_two_pieces', 'text' => 'في الصباح نكتب النص ونختار الصوت المناسب له. بعد ذلك نضبط السرعة والنبرة حتى يبدو الكلام طبيعياً. ثم نستمع إلى المقطع كاملاً ونصحح أي كلمة تحتاج إلى تعديل. وفي النهاية نحفظ الملف وننشره لجمهورنا. ونعود في الأسبوع القادم بحلقة جديدة وأفكار أكثر.', 'dialect' => null, 'max_chars' => 200],
        ['name' => 'en_two_pieces', 'text' => 'In the morning we write the script and choose a voice for it. Next we adjust the speed and pitch until the reading sounds natural. Then we listen to the whole clip and fix any word that needs a change. Finally we save the file and share it with our audience.', 'dialect' => null, 'max_chars' => 200],
    ];
    foreach ($readyVoices as $voice) {
        foreach ($cases as $c) {
            $case = ['name' => $c['name'], 'voice_row' => $voice->id, 'voice_language' => $voice->language_code, 'dialect' => $c['dialect']];
            $previous = config('services.gemini_tts.max_chars_per_request');
            if (isset($c['max_chars'])) {
                config(['services.gemini_tts.max_chars_per_request' => $c['max_chars']]);
            }
            try {
                $spoken = $service->speakableText($c['text'], $c['dialect']);
                $style = $service->deliveryStyle($c['dialect'], 'neutral', 'neutral');
                $result = $service->synthesizeWithClonedVoice($spoken, (string) $voice->language_code, (string) $voice->provider_voice_key, $style);
                $bytes = $result['audio_content'];
                $format = str_starts_with($bytes, 'RIFF') ? 'wav' : (str_starts_with($bytes, 'ID3') || str_starts_with($bytes, "\xFF") ? 'mp3' : 'other');
                $case += [
                    'ok' => strlen($bytes) > 1000,
                    'input_text' => $c['text'],
                    'spoken_text' => $spoken,
                    'rewritten' => $spoken !== trim($c['text']),
                    'latency_ms' => $result['latency_ms'],
                    'bytes' => strlen($bytes),
                    'format' => $format,
                    'heard' => $transcribe($bytes, $format === 'wav' ? 'audio/wav' : 'audio/mpeg'),
                    'audio_base64' => base64_encode($bytes),
                ];
                Storage::disk('public')->delete($result['audio_path']);
            } catch (\Throwable $e) {
                $case += ['ok' => false, 'error' => mb_substr(get_class($e).': '.$e->getMessage(), 0, 500)];
            } finally {
                config(['services.gemini_tts.max_chars_per_request' => $previous]);
            }
            $out['synthesis'][] = $case;
        }
    }
} else {
    $out['synthesis_skipped'] = $readyVoices->isNotEmpty() ? 'CLONE_SYNTH is not 1' : 'no ready cloned voice on this server';
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
