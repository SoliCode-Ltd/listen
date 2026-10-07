<?php

/*
 * Read-only: the Gemini keys this server rotates between. For each key: its
 * name, whether Google accepts it (a free model-list call, no speech), and
 * whether it is marked out for today on the speech model. Keys are masked.
 */

use App\Services\Tts\GeminiKeyManager;
use App\Support\GeminiTtsModel;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

ini_set('display_errors', 'stderr');
$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$manager = app(GeminiKeyManager::class);
$model = GeminiTtsModel::resolve();
$base = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
$out = ['speech_model' => $model, 'cache_store' => config('cache.default'), 'config_key_set' => trim((string) config('services.gemini_tts.api_key')) !== '', 'keys' => []];
foreach ($manager->getAllKeys() as $k) {
    $r = Http::timeout(20)->withHeaders(['x-goog-api-key' => $k['key']])->get($base.'/models', ['pageSize' => 1]);
    $out['keys'][] = [
        'name' => $k['name'],
        'masked' => substr($k['key'], 0, 6).'...'.substr($k['key'], -4),
        'google_accepts' => $r->ok(),
        'status' => $r->status(),
        'out_for_today' => method_exists($manager, 'isOutForToday') ? $manager->isOutForToday($k['name'], $model) : 'n/a',
        'local_requests_today' => $manager->getKeyStats($k['name'])['rpd_used'],
    ];
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
