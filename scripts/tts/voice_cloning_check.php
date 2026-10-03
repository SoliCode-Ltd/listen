<?php

/*
 * Voice cloning health check for STAGING. Piped into the app's own PHP over SSH:
 *
 *   ssh host 'cd app && php' < scripts/tts/voice_cloning_check.php
 *
 * The database session is READ ONLY and the cache is in memory, so nothing is
 * written to the app. Output is JSON with ids, counts and statuses only: no
 * names, emails, keys or recordings. With CLONE_SYNTH=1 it also speaks two
 * short lines with the newest ready cloned voice and returns the audio
 * (base64) so it can be listened to; the files it writes are deleted again.
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

// Optional live synthesis with the newest ready voice.
$out['synthesis'] = [];
$ready = $rows->reject->trashed()->filter(fn ($v) => $v->isReady())->sortByDesc('id')->first();
if (getenv('CLONE_SYNTH') === '1' && $ready && $probe['ok']) {
    $arabic = str_starts_with(strtolower((string) $ready->language_code), 'ar');
    $short = $arabic ? 'مرحباً، هذا اختبار قصير لصوتك المستنسخ.' : 'Hello, this is a short test of your cloned voice.';
    $long = $arabic
        ? str_repeat('هذه جملة لاختبار تقسيم النص الطويل إلى أجزاء ثم دمجها في ملف واحد بصوت متصل. ', 4)
        : str_repeat('This sentence checks that a long script is split into pieces and joined into one smooth file. ', 4);
    $cases = [
        'short' => [$short, null],
        'two_pieces' => [$long, 200],
    ];
    foreach ($cases as $name => [$text, $maxChars]) {
        $case = ['name' => $name, 'voice_row' => $ready->id, 'language' => $ready->language_code, 'chars' => mb_strlen($text)];
        $previous = config('services.gemini_tts.max_chars_per_request');
        if ($maxChars !== null) {
            config(['services.gemini_tts.max_chars_per_request' => $maxChars]);
        }
        try {
            $style = $service->deliveryStyle(null, 'neutral', 'neutral');
            $result = $service->synthesizeWithClonedVoice($text, (string) $ready->language_code, (string) $ready->provider_voice_key, $style);
            $bytes = $result['audio_content'];
            $case += [
                'ok' => strlen($bytes) > 1000,
                'latency_ms' => $result['latency_ms'],
                'bytes' => strlen($bytes),
                'format' => str_starts_with($bytes, 'RIFF') ? 'wav' : (str_starts_with($bytes, 'ID3') || str_starts_with($bytes, "\xFF") ? 'mp3' : 'other'),
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

    if ($arabic) {
        try {
            $plain = 'نحن سعداء جداً بانضمامك إلينا اليوم.';
            $rewritten = $service->speakableText($plain, 'ar-EG');
            $out['dialect_rewrite'] = ['ok' => $rewritten !== '', 'changed' => $rewritten !== $plain, 'chars' => mb_strlen($rewritten)];
        } catch (\Throwable $e) {
            $out['dialect_rewrite'] = ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 300)];
        }
    }
} else {
    $out['synthesis_skipped'] = $ready ? 'CLONE_SYNTH is not 1' : 'no ready cloned voice on this server';
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
