<?php

/*
 * Read-only: how a server is set up for the developer API.
 *
 * Boots the Laravel app in the current directory and prints the address the
 * app recorded for the check workflow's own request, compared with the
 * address this SSH session comes from (the per-address limits count by it;
 * addresses themselves are never printed), the queue and cache settings in
 * use, the daily speech ceiling, the plans and packs that include the API,
 * and counts of API keys and running API generations. It only reads (as a
 * page view does); nothing is changed.
 *
 *   ssh user@host 'cd ~/public_html && REQUEST_ID=req_… php' < scripts/api/readiness_check.php
 */

use App\Services\Api\ApiCapacity;
use App\Services\Api\ApiSpeechService;
use App\Support\Api\ApiFeatureGate;
use App\Support\Api\ApiPlanAccess;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

ini_set('display_errors', 'stderr');

$root = getcwd();
if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "Run this from the Laravel app root.\n");
    exit(1);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$say = function (string $label, mixed $value): void {
    echo '  ', $label, ': ', is_bool($value) ? ($value ? 'yes' : 'NO') : $value, "\n";
};

echo "== The address the app records for a caller ==\n";
$caller = explode(' ', trim((string) getenv('SSH_CLIENT')))[0];
$requestId = trim((string) getenv('REQUEST_ID'));
if (! Schema::hasTable('api_request_logs')) {
    $say('result', 'the request log table is missing (its migration has not been run)');
} elseif ($requestId === '') {
    $say('result', 'the check request got no X-Request-Id back');
} else {
    $seen = DB::table('api_request_logs')->where('request_id', $requestId)->value('ip');
    $public = is_string($seen) && filter_var($seen, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    $say('result', match (true) {
        $seen === null => 'the check request is not in the request log',
        $seen === $caller => "the caller's own address, so the per-address limits count each caller separately (good)",
        ! $public, in_array($seen, (array) @gethostbynamel((string) gethostname()), true) => "a local or proxy address, not the caller's: the per-address limits would count every caller together (FIX before launch)",
        default => "a public address that is not the caller's (a proxy or CDN in front of the site?): the per-address limits may count callers together",
    });
}

echo "== Queue and cache ==\n";
$connection = (string) config('queue.default');
$retryAfter = (int) config("queue.connections.{$connection}.retry_after", 0);
$say('queue connection', $connection);
$say('retry_after', $connection === 'sync'
    ? 'not used (jobs run inline)'
    : $retryAfter.' s'.($retryAfter > 150 ? ' (above the 150 s job limit: good)' : ' (must be above the 150 s job limit, or a running job can be handed to a second worker: FIX)'));
$say('cache store (rate limits, generation slots)', (string) config('cache.default'));

echo "== Developer API ==\n";
$say('switched on (Admin → API keys)', ApiPlanAccess::siteEnabled());
$ceiling = (int) config('listen.cost_guardrails.daily_gemini_char_limit', 0);
$say('site daily speech ceiling', $ceiling > 0 ? number_format($ceiling).' characters' : 'none set');
if (class_exists(ApiCapacity::class)) {
    $limit = ApiCapacity::limit();
    $say('the API\'s share of it', $limit > 0 ? number_format($limit).' characters a day' : 'no daily share (no ceiling set)');
    $say('used by the API today', number_format(ApiCapacity::usedToday()).' characters');
    $say('generations waiting for the file at once, whole site', (int) config('listen.api.site_slots', 8));
} else {
    $say('code', 'from before the API audit fixes (deploy first)');
}
$say('active API keys', Schema::hasTable('api_keys') ? DB::table('api_keys')->whereNull('revoked_at')->count() : 'table missing');
if (Schema::hasColumn('audio_generations', 'api_key_id')) {
    $say('API generations queued or running now', DB::table('audio_generations')->whereNotNull('api_key_id')->whereIn('status', ['queued', 'processing'])->count());
}
$say('Idempotency-Key table', Schema::hasTable('api_idempotency_keys') ? 'present' : 'missing (run the migrations; the header is ignored until then)');

$plans = ApiFeatureGate::apiPlans();
echo '== Plans and packs on sale with the API: ', $plans->count(), " ==\n";
foreach ($plans as $plan) {
    $line = $plan->slug.': '.ApiPlanAccess::requestsPerMinute($plan).' generations a minute, '.ApiPlanAccess::concurrency($plan).' at once';
    if (method_exists(ApiSpeechService::class, 'maxCharsFor')) {
        $line .= ', up to '.number_format(ApiSpeechService::maxCharsFor($plan)).' characters a request ('
            .number_format(ApiSpeechService::maxCharsFor($plan, true)).' in the background)';
    }
    echo '  ', $line, "\n";
    if ((int) $plan->max_chars_per_request > ApiSpeechService::MAX_TEXT) {
        $shown = number_format((int) $plan->max_chars_per_request);
        echo "    its pricing card shows {$shown} characters a request, but the studio and the extension take at most ", number_format(ApiSpeechService::MAX_TEXT), " in one request\n";
    }
}
