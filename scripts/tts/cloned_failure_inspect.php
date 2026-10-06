<?php

/*
 * Read-only: the latest cloned-voice generations on this server, with their
 * status, error, studio settings, and the log lines around their failures.
 * Nothing is written and no voice request is made.
 */

use App\Models\AudioGeneration;
use Illuminate\Contracts\Console\Kernel;

ini_set('display_errors', 'stderr');
$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$out = ['meta' => [
    'generated_at' => now()->toIso8601String(),
    'git_head' => trim((string) @shell_exec('git -C '.escapeshellarg($root).' log -1 --format=%h\ %s 2>/dev/null')),
], 'generations' => [], 'log' => []];

$rows = AudioGeneration::query()->where('voice_name', 'like', 'cloned_%')->latest('id')->limit(12)->get();
foreach ($rows as $g) {
    $out['generations'][] = [
        'id' => $g->id, 'created_at' => (string) $g->created_at, 'status' => $g->status,
        'error' => mb_substr((string) $g->error_message, 0, 600),
        'voice' => $g->voice_name, 'language' => $g->target_language, 'dialect' => $g->dialect,
        'style' => $g->performance_style, 'emotion' => $g->emotion, 'speed' => $g->speed, 'pitch' => $g->pitch,
        'style_prompt' => mb_substr((string) $g->style_prompt, 0, 200), 'text' => mb_substr((string) $g->input_text, 0, 300),
    ];
}

$log = storage_path('logs/laravel.log');
if (is_file($log)) {
    $lines = [];
    $fh = fopen($log, 'r');
    fseek($fh, max(0, filesize($log) - 4_000_000));
    while (($line = fgets($fh)) !== false) {
        if (preg_match('/[Cc]loned|interactions|voice synthesis|GenerateAudioJob|dialect rewrite/', $line)) {
            $lines[] = mb_substr(trim($line), 0, 700);
        }
    }
    $out['log'] = array_slice($lines, -40);
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
