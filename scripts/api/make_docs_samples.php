<?php

/*
 * Makes the recordings at the top of the developer docs: sends each request
 * of database/seeders/data/developer_docs_samples.php to this app's own API
 * (served at the address in the first argument, default
 * http://127.0.0.1:8000), as a member on a plan with the API, and saves the
 * audio it returns under public/. Only missing files are made; each one is a
 * single speech request against the configured key's quota.
 *
 * Meant for a throwaway copy of the app (the "Developer docs samples"
 * workflow): it applies the live speech settings given as JSON in the second
 * argument (from scripts/api/print_tts_settings.php), and turns the API on
 * for one premium plan when the seeded plans have none.
 *
 *   php scripts/api/make_docs_samples.php http://127.0.0.1:8000 settings.json
 */

use App\Models\ApiKey;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Voice;
use App\Services\PlanAccessService;
use App\Support\Api\ApiPlanAccess;
use App\Support\VoiceCatalog;
use App\Support\VoiceTier;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
$samples = require database_path('seeders/data/developer_docs_samples.php');

$missing = array_filter($samples, fn (array $sample): bool => ! is_file(public_path($sample['audio'])));
if ($missing === []) {
    echo "Every recording is already there.\n";
    exit(0);
}

// The same speech settings as the live site's API.
$settingsFile = $argv[2] ?? null;
if ($settingsFile !== null) {
    $live = json_decode((string) file_get_contents($settingsFile), true);
    if (! is_array($live) || ! is_array($live['settings'] ?? null)) {
        fwrite(STDERR, "The settings file is not valid.\n");
        exit(1);
    }
    foreach ($live['settings'] as $setting) {
        SiteSetting::query()->updateOrCreate(['key' => (string) $setting['key']], ['value' => (string) $setting['value'], 'group' => (string) $setting['group']]);
    }
    echo 'Applied '.count($live['settings']).' speech settings (tts_mode '.SiteSetting::query()->where('key', 'tts_mode')->value('value').").\n";
}

$plans = Plan::query()->subscriptionPlans()->where('is_active', true)->orderBy('price_cents')->get();
$plan = $plans->first(fn (Plan $plan): bool => ApiPlanAccess::enabled($plan));
if (! $plan instanceof Plan) {
    // Seeded plans have commercial use off: give the API to the cheapest premium paid plan of this copy.
    $plan = $plans->first(fn (Plan $plan): bool => (int) $plan->price_cents > 0 && VoiceTier::normalize((string) $plan->voice_tier) === VoiceTier::HD);
    $plan?->update(['commercial_use_allowed' => true, 'api_access_enabled' => true]);
}
if (! $plan instanceof Plan || ! ApiPlanAccess::enabled($plan->fresh())) {
    fwrite(STDERR, "No plan includes the developer API.\n");
    exit(1);
}
$user = User::query()->firstOrCreate(['email' => 'docs-samples@listen.local'], ['name' => 'Docs samples', 'password' => bcrypt(Str::random(40))]);
app(PlanAccessService::class)->assignPlan($user, $plan);
$key = ApiKey::issue($user, 'Docs samples')['plain'];

$failed = false;
foreach ($missing as $language => $sample) {
    $voice = Voice::query()->where('internal_voice_id', $sample['voice'])->where('is_active', true)->first();
    if (! $voice instanceof Voice) {
        fwrite(STDERR, "{$language}: no active voice {$sample['voice']}.\n");
        $failed = true;

        continue;
    }

    $url = $base.'/v1/text-to-speech/'.VoiceCatalog::keyFromId((int) $voice->id).'?output_format=mp3';
    $response = Http::timeout(300)->withHeaders(['X-Listen-Api-Key' => $key])->post($url, $sample['body']);
    if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'audio/mpeg')) {
        fwrite(STDERR, "{$language}: HTTP {$response->status()} ".Str::limit((string) $response->body(), 300)."\n");
        $failed = true;

        continue;
    }

    $target = public_path($sample['audio']);
    if (! is_dir(dirname($target))) {
        mkdir(dirname($target), 0775, true);
    }
    file_put_contents($target, $response->body());
    echo "{$language}: saved {$sample['audio']} (".strlen($response->body()).' bytes, '.$response->header('X-Audio-Duration-Seconds')." s, plan {$plan->slug}).\n";
}

exit($failed ? 1 : 0);
