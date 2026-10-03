<?php

/*
 * Read-only look at how recent Standard-quality demo clips were made.
 *
 * Reports the saved standard voice engine, whether the deployed code has it,
 * and for the newest Standard demo clips: voice, recorded cost per 1M
 * characters (Chirp 3 HD is recorded at $30, WaveNet at $16) and the MP3
 * format of the saved file (Google returns Chirp 3 HD at 32 kbps / 24 kHz
 * and WaveNet at 64 kbps / 24 kHz in this app). No personal data is printed
 * and nothing is written: the MySQL session is READ ONLY.
 *
 *   ssh host 'cd app && DEMO_AUDIO=20261003_171553_eFTV7M26Ldus php' < scripts/tts/demo_audio_inspect.php
 */

use App\Models\SiteSetting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

ini_set('display_errors', 'stderr');

$root = getcwd();
if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "Run this from the Laravel app root.\n");
    exit(1);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}

/**
 * Average bitrate, sample rate and duration, found by walking every MPEG
 * audio frame (the first frame is often a metadata frame with its own rate).
 */
function mp3Format(?string $bytes): ?array
{
    if (! is_string($bytes) || strlen($bytes) < 10) {
        return null;
    }
    $i = 0;
    if (str_starts_with($bytes, 'ID3')) {
        $i = 10 + ((ord($bytes[6]) << 21) | (ord($bytes[7]) << 14) | (ord($bytes[8]) << 7) | ord($bytes[9]));
    }
    $bitrates = [
        3 => [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320],
        2 => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
    ];
    $rates = [3 => [44100, 48000, 32000], 2 => [22050, 24000, 16000], 0 => [11025, 12000, 8000]];
    $length = strlen($bytes);
    $frames = 0;
    $seconds = 0.0;
    $bits = 0;
    $sampleRate = null;
    $counts = [];

    while ($i + 4 <= $length) {
        $b1 = ord($bytes[$i + 1]);
        $b2 = ord($bytes[$i + 2]);
        $version = ($b1 >> 3) & 0x03;
        $bitrateIndex = ($b2 >> 4) & 0x0F;
        $rateIndex = ($b2 >> 2) & 0x03;
        $valid = ord($bytes[$i]) === 0xFF && ($b1 & 0xE0) === 0xE0 && $version !== 1
            && (($b1 >> 1) & 0x03) === 1 && $bitrateIndex > 0 && $bitrateIndex < 15 && $rateIndex !== 3;
        if (! $valid) {
            $i++;

            continue;
        }
        $kbps = $bitrates[$version === 3 ? 3 : 2][$bitrateIndex];
        $rate = $rates[$version][$rateIndex];
        $samples = $version === 3 ? 1152 : 576;
        $frameBytes = intdiv($samples / 8 * $kbps * 1000, $rate) + (($b2 >> 1) & 0x01);
        if ($frameBytes < 4) {
            $i++;

            continue;
        }
        $frames++;
        $seconds += $samples / $rate;
        $bits += $frameBytes * 8;
        $sampleRate = $rate;
        $counts[$kbps] = ($counts[$kbps] ?? 0) + 1;
        $i += $frameBytes;
    }

    if ($frames === 0) {
        return null;
    }
    arsort($counts);

    return [
        'avg_kbps' => (int) round($bits / max($seconds, 0.001) / 1000),
        'most_common_kbps' => array_key_first($counts),
        'sample_rate' => $sampleRate,
        'seconds' => round($seconds, 1),
        'bytes' => $length,
    ];
}

$out = [
    'app_env' => app()->environment(),
    'deployed_commit' => trim((string) @shell_exec('git -C '.escapeshellarg($root).' rev-parse --short HEAD 2>/dev/null')) ?: null,
    'code_has_engine_switch' => class_exists('App\Support\StandardVoiceEngine'),
    'saved_standard_engine' => SiteSetting::get('tts_standard_voice_engine', '(not set: wavenet)'),
    'tts_mode' => SiteSetting::get('tts_mode', '(not set)'),
    'clips' => [],
];

$wanted = trim((string) getenv('DEMO_AUDIO'));
$rows = DB::table('demo_voice_generations')
    ->select(['id', 'created_at', 'voice_tier', 'voice_name', 'voice_provider', 'tts_model', 'billable_chars', 'tts_cost_estimate_usd', 'audio_path'])
    ->where(fn ($q) => $q->where('voice_tier', 'standard')->orWhere('audio_path', 'like', '%'.($wanted !== '' ? $wanted : '__none__').'%'))
    ->orderByDesc('id')
    ->limit(6)
    ->get();

foreach ($rows as $row) {
    $bytes = null;
    foreach (['public', 'local'] as $disk) {
        if ($row->audio_path && Storage::disk($disk)->exists($row->audio_path)) {
            $bytes = Storage::disk($disk)->get($row->audio_path);
            break;
        }
    }
    $chars = (int) $row->billable_chars;
    $out['clips'][] = [
        'id' => $row->id,
        'created_at' => (string) $row->created_at,
        'is_the_linked_clip' => $wanted !== '' && str_contains((string) $row->audio_path, $wanted),
        'tier' => $row->voice_tier,
        'voice_name' => $row->voice_name,
        'provider' => $row->voice_provider,
        'model' => $row->tts_model,
        'chars' => $chars,
        'recorded_cost_per_million_chars' => $chars > 0 && $row->tts_cost_estimate_usd !== null
            ? round((float) $row->tts_cost_estimate_usd / $chars * 1_000_000, 1)
            : null,
        'mp3' => mp3Format($bytes),
    ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
