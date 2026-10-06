<?php

/*
 * Read-only: the latest generations whose text has tags, and what the
 * server would send to the voice model for them. Nothing is written.
 */

use App\Models\AudioGeneration;
use App\Models\SiteSetting;
use Illuminate\Contracts\Console\Kernel;

ini_set('display_errors', 'stderr');
$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$out = [
    'meta' => [
        'generated_at' => now()->toIso8601String(),
        'gemini_tts_model_setting' => SiteSetting::get('gemini_tts_model', null),
        'gemini_tts_model_config' => config('services.gemini_tts.model'),
        'resolved_model' => class_exists(\App\Support\GeminiTtsModel::class) ? \App\Support\GeminiTtsModel::resolve() : null,
        'code_has_vocal_tags' => class_exists(\App\Support\GeminiVocalTags::class),
        'code_has_parts' => class_exists(\App\Support\GeminiVocalTags::class) && method_exists(\App\Support\GeminiVocalTags::class, 'parts'),
        'code_has_voice_studio' => is_file($root.'/public/js/voice-studio.js'),
        'git_head' => trim((string) @shell_exec('git -C '.escapeshellarg($root).' log -1 --format=%h\ %s 2>/dev/null')),
    ],
    'generations' => [],
];

$rows = AudioGeneration::query()
    ->where(function ($q) {
        $q->where('input_text', 'like', '%<%')->orWhere('input_text', 'like', '%[%');
    })
    ->latest('id')->limit(6)->get();

foreach ($rows as $g) {
    $text = (string) $g->input_text;
    $row = [
        'id' => $g->id,
        'created_at' => (string) $g->created_at,
        'status' => $g->status,
        'voice_tier' => $g->voice_tier,
        'voice_name' => $g->voice_name,
        'dialect' => $g->dialect,
        'translate' => (bool) $g->translate,
        'provider' => $g->tts_provider,
        'model' => $g->tts_model,
        'error' => $g->error_message ? mb_substr($g->error_message, 0, 200) : null,
        'input_text' => $text,
        'snapshot' => $g->settings_snapshot,
    ];
    if (class_exists(\App\Support\GeminiVocalTags::class) && method_exists(\App\Support\GeminiVocalTags::class, 'parts')) {
        $row['parts_sent'] = \App\Support\GeminiVocalTags::parts($text);
    }
    $out['generations'][] = $row;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
