<?php

/*
 * For the "Developer API smoke test" workflow, on STAGING only: sets up, and
 * afterwards takes away, the test member its two real requests run as.
 *
 *   MODE=start   the member staging-api-check@example.com on the cheapest
 *                subscription plan with the API, set the way the team sets
 *                a plan but with model events off, so no welcome email is
 *                queued. Revokes its old keys and issues one new key.
 *                Prints the key (KEY=…, masked by the workflow), the docs'
 *                two sample voices and requests, and the member's credits.
 *   MODE=finish  prints what the requests left (generations, credits, the
 *                key's usage and the request log), revokes the member's keys
 *                and puts it back on the free plan, again with events off.
 *
 * The member has no Stripe customer, so nothing here or in the requests
 * reaches Stripe. Refuses to run on the live site.
 *
 *   ssh user@host 'cd ~/public_html && MODE=start php' < scripts/api/staging_smoke_member.php
 */

use App\Models\ApiKey;
use App\Models\ApiKeyUsage;
use App\Models\ApiRequestLog;
use App\Models\AudioGeneration;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Models\Voice;
use App\Services\PlanAccessService;
use App\Support\Api\ApiFeatureGate;
use App\Support\CreditPeriod;
use App\Support\VoiceCatalog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

ini_set('display_errors', 'stderr');

$root = getcwd();
if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "Run this from the Laravel app root.\n");
    exit(1);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (str_contains(strtolower((string) config('app.url')), 'listenai.studio')) {
    fwrite(STDERR, "This is the live site: the smoke test runs on staging only.\n");
    exit(1);
}

$email = 'staging-api-check@example.com';
// The member's current credit period (their plan's own month where the app has them).
$periodKey = fn (User $user): string => class_exists(CreditPeriod::class)
    ? CreditPeriod::current((int) $user->id)['key']
    : now()->format('Y-m');
$mode = (string) getenv('MODE');
$access = app(PlanAccessService::class);

if ($mode === 'start') {
    $plan = ApiFeatureGate::apiPlans()->first(fn ($plan): bool => ! $plan->isPack());
    if (! $plan) {
        fwrite(STDERR, "No subscription plan on sale includes the API.\n");
        exit(1);
    }

    // Created with its free plan (no email for that); the paid plan is set with events off.
    $user = User::query()->firstOrCreate(['email' => $email], ['name' => 'Staging API check', 'password' => bcrypt(Str::random(40))]);
    Subscription::withoutEvents(fn () => $access->assignPlan($user, $plan));
    ApiKey::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    $plain = ApiKey::issue($user, 'Staging API check')['plain'];

    echo 'KEY=', $plain, "\n";
    echo "Test member {$user->id} on plan {$plan->slug}.\n";
    foreach (require database_path('seeders/data/developer_docs_samples.php') as $language => $sample) {
        $voice = Voice::query()->where('internal_voice_id', $sample['voice'])->where('is_active', true)->first();
        if (! $voice) {
            fwrite(STDERR, "No active voice {$sample['voice']}.\n");
            exit(1);
        }
        echo 'VOICE_', strtoupper($language), '=', VoiceCatalog::keyFromId((int) $voice->id), "\n";
        echo 'BODY_', strtoupper($language), '=', json_encode($sample['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    }
    $counter = UsageCounter::query()->where('user_id', $user->id)->where('period_ym', $periodKey($user))->first();
    echo 'Credits used this month before the test: ', (int) ($counter?->total_chars ?? 0), "\n";
    exit(0);
}

if ($mode === 'finish') {
    $user = User::query()->where('email', $email)->first();
    if (! $user) {
        echo "No test member.\n";
        exit(0);
    }

    echo "== The test member's API generations in the last two hours ==\n";
    $generations = AudioGeneration::query()
        ->where('user_id', $user->id)
        ->whereNotNull('api_key_id')
        ->where('created_at', '>=', now()->subHours(2))
        ->orderBy('id')
        ->get();
    foreach ($generations as $generation) {
        $reserved = (array) (($generation->settings_snapshot ?? [])['reservation'] ?? []);
        $line = [
            "#{$generation->id} {$generation->status}: {$generation->duration_seconds} s".($generation->duration_measured ? ' (measured)' : ' (estimated)'),
            'reserved '.(int) ($reserved['credits'] ?? 0).' credits',
        ];
        if ($generation->tts_fallback_used) {
            $line[] = 'made by the backup engine';
        }
        if ($generation->error_message) {
            $line[] = 'error: '.Str::limit((string) $generation->error_message, 160);
        }
        echo '  ', implode(', ', $line), "\n";
    }
    if ($generations->isEmpty()) {
        echo "  none\n";
    }

    $counter = UsageCounter::query()->where('user_id', $user->id)->where('period_ym', $periodKey($user))->first();
    echo '== Credits used this month: ', (int) ($counter?->total_chars ?? 0), ', requests: ', (int) ($counter?->total_requests ?? 0), " ==\n";

    $keyIds = ApiKey::query()->where('user_id', $user->id)->pluck('id');
    foreach (ApiKeyUsage::query()->whereIn('api_key_id', $keyIds)->where('period_ym', $periodKey($user))->get() as $usage) {
        echo "  key {$usage->api_key_id}: ", (int) $usage->requests, ' requests, ', (int) $usage->credits, " credits\n";
    }

    echo "== The test member's requests in the log ==\n";
    $logs = ApiRequestLog::query()->where('user_id', $user->id)->where('created_at', '>=', now()->subHours(2))->orderBy('id')->get();
    foreach ($logs as $log) {
        $credits = $log->credits !== null ? ", {$log->credits} credits" : '';
        echo "  {$log->method} {$log->path} → {$log->status}", $log->error_code ? " {$log->error_code}" : '', "{$credits}, {$log->duration_ms} ms\n";
    }
    if ($logs->isEmpty()) {
        echo "  none\n";
    }

    ApiKey::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    $free = $access->freePlan();
    if ($free) {
        Subscription::withoutEvents(fn () => $access->assignPlan($user, $free));
    }
    echo "The test member's keys are revoked and it is back on the free plan.\n";
    exit(0);
}

fwrite(STDERR, "Set MODE to start or finish.\n");
exit(1);
