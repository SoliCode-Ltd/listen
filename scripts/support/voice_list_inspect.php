<?php

/*
 * Read-only: every voice in the studio's library, one JSON line each: who it
 * is (name, language, gender, tier, the engine voice), its dialect or accent,
 * its avatar, and its preview recording (path, and whether the file is there).
 * Used to write a preview line for each speaker that fits their avatar.
 * On MySQL the session is READ ONLY before any query runs; nothing is written.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/voice_list_inspect.php
 */

use App\Models\Voice;
use App\Support\SpeakerAvatar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

ini_set('display_errors', 'stderr');

$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}

// The speech model premium voices use (always Gemini's latest).
echo json_encode(['model_in_use' => \App\Support\GeminiTtsModel::resolve()]).PHP_EOL;

$voices = Voice::query()->orderBy('language')->orderBy('tier')->orderBy('display_name')->get();
$active = 0;
foreach ($voices as $voice) {
    $preview = ltrim(trim((string) $voice->preview_audio_path), '/');
    $active += $voice->is_active ? 1 : 0;
    echo json_encode([
        'id' => $voice->id,
        'name' => $voice->display_name,
        'language' => $voice->language,
        'tier' => $voice->tier,
        'gender' => $voice->gender,
        'engine' => $voice->provider_voice_id,
        'internal' => $voice->internal_voice_id,
        'dialect' => $voice->dialect,
        'country' => $voice->country,
        'active' => (bool) $voice->is_active,
        'featured' => (bool) $voice->is_featured,
        'avatar' => SpeakerAvatar::url((string) $voice->display_name, (string) $voice->language) !== null
            ? SpeakerAvatar::slug((string) $voice->display_name, (string) $voice->language) : null,
        'preview' => $preview !== '' ? $preview : null,
        'preview_exists' => $preview !== '' && (Storage::disk('public')->exists($preview) || is_file(public_path($preview))),
    ], JSON_UNESCAPED_UNICODE)."\n";
}
echo "TOTAL {$voices->count()} voices, {$active} active\n";
