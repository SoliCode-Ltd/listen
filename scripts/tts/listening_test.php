<?php

/*
 * For the "Listening test" workflow, on STAGING only: makes each case the way
 * a member's studio voice is made, through the studio's own Generate request
 * (its checks, the directions, the pieces of a long text and their joins,
 * loudness, the sound environment and the MP3 members download), as a test
 * member with no Stripe customer on the dearest plan, and prints one JSON
 * line per case with the recording. It uses the speech quota staging shares
 * with live, so it runs by hand only. Refuses to run on the live site.
 *
 *   ssh user@host 'cd ~/public_html && php' < listen.php   (the workflow puts the cases in)
 */

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voice;
use App\Models\VoiceSample;
use App\Services\PlanAccessService;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

ini_set('display_errors', 'stderr');

$root = getcwd();
if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "Run this from the Laravel app root.\n");
    exit(1);
}

$cases = json_decode(base64_decode('__CASES__'), true, 512, JSON_THROW_ON_ERROR);

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->instance('request', Request::create('/en'));
$kernel->bootstrap();

if (str_contains(strtolower((string) config('app.url')), 'listenai.studio')) {
    fwrite(STDERR, "This is the live site: the listening test runs on staging only.\n");
    exit(1);
}

// The test's own requests, one after another: past the form token and the studio's rate limit.
$app->instance(ValidateCsrfToken::class, new class($app, $app['encrypter']) extends ValidateCsrfToken
{
    public function handle($request, Closure $next)
    {
        return $next($request);
    }
});
$app->instance(ThrottleRequests::class, new class($app->make(Illuminate\Cache\RateLimiter::class)) extends ThrottleRequests
{
    public function handle($request, Closure $next, ...$limits)
    {
        return $next($request);
    }
});

$access = app(PlanAccessService::class);
$member = User::query()->firstOrCreate(
    ['email' => 'staging-listening-test@example.com'],
    ['name' => 'Staging listening test', 'password' => bcrypt(Str::random(40))],
);
$member->forceFill(['subscription_status_checked_at' => now()])->save();
// The dearest plan on sale, so every studio option is in it.
$plan = Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->orderByDesc('price_cents')->first();
if (! $plan) {
    fwrite(STDERR, "No paid plan on sale.\n");
    exit(1);
}
Subscription::withoutEvents(fn () => $access->assignPlan($member, $plan));
fwrite(STDERR, "Test member {$member->id} on {$plan->slug}, ".count($cases)." case(s).\n");

// The case's own voice, or else the first one of its language and tier (never another voice for an exact one).
$voiceKey = function (string $internal, string $language, string $tier, bool $exact = false): string {
    $active = Voice::query()->where('is_active', true);
    $voice = $internal !== '' ? (clone $active)->where('internal_voice_id', $internal)->first() : null;
    if (! $voice && $exact) {
        throw new RuntimeException("The voice {$internal} is not on sale on this site.");
    }
    $voice ??= (clone $active)->where('language', $language)->where('tier', $tier)->orderBy('id')->first();
    if (! $voice) {
        throw new RuntimeException("No active {$tier} voice for {$language}.");
    }

    return VoiceCatalog::keyFromId((int) $voice->id);
};

// The sample the voice list plays for a voice (its preview, else its own sample), to hear against a take made now.
$voiceSample = function (string $internal): ?array {
    $voice = Voice::query()->where('internal_voice_id', $internal)->first();
    $paths = [(string) $voice?->preview_audio_path];
    foreach (VoiceSample::query()->where('is_active', true)->where('voice_name', $internal)->orderByDesc('is_featured')->orderBy('sort_order')->get(['audio_path', 'metadata']) as $sample) {
        if ((array) (($sample->metadata ?? [])['turns'] ?? []) === []) {
            $paths[] = (string) $sample->audio_path;
        }
    }
    foreach ($paths as $path) {
        $path = ltrim(trim($path), '/');
        $bytes = $path === '' ? null : (Storage::disk('public')->exists($path) ? Storage::disk('public')->get($path) : (is_file(public_path($path)) ? file_get_contents(public_path($path)) : null));
        if (is_string($bytes) && $bytes !== '') {
            return ['file' => basename($path), 'bytes' => $bytes];
        }
    }

    return null;
};

$guard = $app['auth']->guard('web');
foreach ($cases as $case) {
    $line = ['id' => $case['id'], 'listen_for' => $case['listen_for'] ?? ''];
    try {
        $payload = $case['request'];
        $payload['voice_key'] = $voiceKey((string) ($case['voice'] ?? ''), (string) $payload['target_language'], (string) ($payload['voice_tier'] ?? 'hd'), ! empty($case['exact_voice']));
        if (! empty($case['compare_sample']) && ($sample = $voiceSample((string) $case['voice'])) !== null) {
            $line['sample_file'] = $sample['file'];
            $line['sample_base64'] = base64_encode($sample['bytes']);
        }
        foreach ($case['dialogue'] ?? [] as $speaker) {
            $payload['dialogue_speakers'][] = [
                'name' => $speaker['name'],
                'voice' => $voiceKey((string) $speaker['voice'], (string) $payload['target_language'], 'hd'),
                'dialect' => $speaker['dialect'] ?? null,
            ];
        }

        $guard->setUser($member);
        $last = (int) AudioGeneration::query()->where('user_id', $member->id)->max('id');
        $request = Request::create('/en/dashboard/queue', 'POST', $payload, [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $started = microtime(true);
        $response = $kernel->handle($request);
        // A long text is made after the reply: this runs it.
        $kernel->terminate($request, $response);
        $line['http'] = $response->getStatusCode();
        $line['seconds'] = round(microtime(true) - $started, 1);
        if ($response->getStatusCode() >= 400) {
            $body = json_decode((string) $response->getContent(), true);
            $line['refused'] = mb_substr(json_encode($body['errors'] ?? $body['message'] ?? $response->getContent(), JSON_UNESCAPED_UNICODE), 0, 400);
        }

        $generation = AudioGeneration::query()->where('user_id', $member->id)->where('id', '>', $last)->latest('id')->first();
        if ($generation) {
            $line += [
                'status' => $generation->status,
                'duration_seconds' => $generation->duration_seconds,
                'provider' => $generation->tts_provider,
                'model' => $generation->tts_model,
                'error' => $generation->error_message ? mb_substr((string) $generation->error_message, 0, 300) : null,
                // A long voice's pieces: how many were taken again, why, and how far the kept ones stray (PieceCheck).
                'pieces_check' => ($generation->settings_snapshot ?? [])['pieces_check'] ?? null,
            ];
            $path = (string) $generation->audio_path;
            if ($generation->status === 'done' && $path !== '' && Storage::disk('public')->exists($path)) {
                $line['file'] = basename($path);
                $line['audio_base64'] = base64_encode((string) Storage::disk('public')->get($path));
            }
        }
    } catch (Throwable $e) {
        $line['error'] = get_class($e).': '.mb_substr($e->getMessage(), 0, 300);
    }
    echo json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

// The test member goes back to the free plan.
$free = $access->freePlan();
if ($free) {
    Subscription::withoutEvents(fn () => $access->assignPlan($member, $free));
}
