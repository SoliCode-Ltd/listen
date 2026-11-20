<?php

/*
 * Read-only: prints, as JSON, the speech settings this server's API generates
 * with (engine mode, model, fallback, limits; never a key or other secret),
 * so the developer docs recordings can be made with the same settings.
 * Run it from the app's folder, the script on standard input:
 *
 *   php < scripts/api/print_tts_settings.php
 */

use App\Models\SiteSetting;
use Illuminate\Contracts\Console\Kernel;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// An error anywhere stops the check as FAILED (left to Laravel, it would be shown and the run would still pass).
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, 'The check stopped on an error: '.$e::class.': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().")\nFAILED\n");
    exit(1);
});

$settings = SiteSetting::query()
    ->get(['key', 'value', 'group'])
    ->filter(fn (SiteSetting $setting): bool => (bool) preg_match('/^(tts|gemini|daily)_/', (string) $setting->key))
    ->reject(fn (SiteSetting $setting): bool => (bool) preg_match('/key|secret|token|password|credential/i', (string) $setting->key))
    ->map(fn (SiteSetting $setting): array => ['key' => (string) $setting->key, 'value' => (string) $setting->value, 'group' => (string) ($setting->group ?: 'tts')])
    ->values()
    ->all();

echo json_encode([
    'settings' => $settings,
    'dialect_rewrite' => (bool) config('services.gemini_tts.dialect_rewrite', true),
], JSON_UNESCAPED_SLASHES), "\n";
