<?php

/*
 * For the "Server deploy" workflow's member-generations mode, on either site: a member's
 * voices, to send them a link that opens the studio with exactly what one of them was made
 * with (the dashboard's ?reuse=, the member's own history number), when they cannot find it.
 *
 * MEMBER: a member id, an email, or a name ("alaa nassar"; alternatives split by "|", each
 * word must be in the name or the email). DURATION: the voice's length in seconds, when the
 * owner has the audio: the voices within 3 seconds of it are shown in full, with what the
 * link will bring back on the member's plan today (DashboardController::reuseForPlan) and
 * what it leaves out. Reads in a READ ONLY database session and writes nothing; prints
 * member ids and masked emails, never a text (only its length and its first words' count).
 *
 *   ssh user@host 'cd ~/public_html && MEMBER=... DURATION=136 php' < scripts/support/member_generations.php
 */

use App\Http\Controllers\DashboardController;
use App\Models\AudioGeneration;
use App\Models\ClonedVoice;
use App\Models\DesignedVoice;
use App\Models\User;
use App\Services\PlanAccessService;
use App\Support\PlanFeatures;
use App\Support\VoiceCatalog;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}

$query = trim((string) base64_decode((string) getenv('MEMBER_B64'), true));
$duration = (float) getenv('DURATION');
$site = rtrim((string) config('app.url'), '/');
$mask = function (?string $email): string {
    [$name, $domain] = array_pad(explode('@', (string) $email, 2), 2, '');

    return mb_substr($name, 0, 2).str_repeat('*', max(1, mb_strlen($name) - 2)).'@'.$domain;
};
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";
if ($query === '') {
    echo "No member given.\n";
    exit(0);
}

if (ctype_digit($query)) {
    $members = User::query()->whereKey((int) $query)->get();
} elseif (str_contains($query, '@')) {
    $members = User::query()->where('email', $query)->get();
} else {
    $members = User::query()->where(function ($any) use ($query): void {
        foreach (array_filter(array_map('trim', explode('|', $query))) as $alternative) {
            $any->orWhere(function ($all) use ($alternative): void {
                foreach (preg_split('/\s+/u', mb_strtolower($alternative)) as $word) {
                    $all->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', ['%'.$word.'%'])->orWhereRaw('LOWER(email) LIKE ?', ['%'.$word.'%']));
                }
            });
        }
    })->orderBy('id')->limit(10)->get();
}
if ($members->isEmpty()) {
    echo "No member matches.\n";
    exit(0);
}

$plans = app(PlanAccessService::class);
echo '== ', $members->count(), " member(s) match ==\n";
foreach ($members as $m) {
    $plan = $plans->resolveAccessPlanForUserId((int) $m->id);
    $count = AudioGeneration::query()->where('user_id', $m->id)->count();
    $last = AudioGeneration::query()->where('user_id', $m->id)->max('created_at');
    echo '  member ', $m->id, ': ', $mask($m->email), ', signed up ', $m->created_at?->utc()->format('Y-m-d'),
        ', plan now: ', $plan?->slug ?? 'none', ' (', $plans->resolveTierForPlan($plan), ')',
        ', voices made: ', $count, ', last: ', $last ? substr((string) $last, 0, 16) : '-', "\n";
}
if ($members->count() > 3) {
    echo "More than 3 members match: run again with one member id.\n";
    exit(0);
}

$controller = app(DashboardController::class);
$call = function (string $method, array $args) use ($controller) {
    $m = new ReflectionMethod(DashboardController::class, $method);
    $m->setAccessible(true);

    return $m->invokeArgs($controller, $args);
};
$voiceLabel = function (?string $name): string {
    $meta = VoiceCatalog::voiceMeta($name);
    if ($meta === null) {
        return (string) $name.(str_starts_with((string) $name, 'cloned_') || str_starts_with((string) $name, DesignedVoice::KEY_PREFIX) ? ' (own voice)' : ' (no longer in the voice list)');
    }

    return $meta['label'].' / '.$meta['label_ar'].' ['.$meta['key'].', '.$meta['tier'].($meta['language'] ? ', '.$meta['language'] : '').']';
};

foreach ($members as $m) {
    auth()->setUser($m);
    $plan = $plans->resolveAccessPlanForUserId((int) $m->id);
    $tier = $plans->resolveTierForPlan($plan);
    $catalog = VoiceCatalog::publicCatalogByTier()[$tier] ?? VoiceCatalog::publicCatalog($tier, VoiceCatalog::preferredProviderForTier($tier));
    $dialogue = (bool) ($plan?->dialogue_enabled ?? false) || PlanFeatures::userHas($m, $plan, PlanFeatures::DIALOGUE);
    $own = [
        ...ClonedVoice::forUser((int) $m->id)->get()->filter(fn (ClonedVoice $v) => $v->isReady())->map(fn (ClonedVoice $v) => 'cloned_'.$v->id)->values()->all(),
        ...DesignedVoice::query()->where('user_id', $m->id)->whereNotNull('kept_at')->get()->map(fn (DesignedVoice $v) => $v->voiceKey())->all(),
    ];

    $rows = AudioGeneration::query()->where('user_id', $m->id)->latest('id')->limit(400)
        ->get(['id', 'user_sequence_number', 'user_id', 'input_text', 'target_language', 'voice_tier', 'voice_name', 'translate', 'duration_seconds', 'duration_measured',
            'status', 'dialect', 'performance_style', 'style_prompt', 'emotion', 'speed', 'pitch', 'is_dialogue', 'dialogue_metadata', 'settings_snapshot', 'billable_chars', 'api_key_id', 'audio_path', 'created_at']);
    $near = $duration > 0 ? $rows->filter(fn ($g) => abs((float) $g->duration_seconds - $duration) <= 3) : collect();

    echo "\n== Member {$m->id}: the latest 20 voices (history number, made UTC, status, length, characters, language, voice) ==\n";
    foreach ($rows->take(20) as $g) {
        echo '  #', $g->displayNumber(), '  ', substr((string) $g->created_at, 0, 16), '  ', $g->status, '  ', (int) $g->duration_seconds, ' s', $g->duration_measured ? '' : ' (estimate)',
            '  ', (int) $g->billable_chars, ' chars  ', $g->target_language, '  ', $g->voice_tier, '  ', $voiceLabel($g->voice_name), $g->api_key_id ? '  (developer API)' : '', "\n";
    }

    if ($duration > 0) {
        echo "\n== Member {$m->id}: voices within 3 s of ", $duration, " s: ", $near->count(), " ==\n";
    }
    foreach ($near as $g) {
        $settings = $g->reusableSettings();
        echo "\n  #", $g->displayNumber(), ' (id ', $g->id, '), made ', substr((string) $g->created_at, 0, 16), ' UTC, ', $g->status, ', ', (int) $g->duration_seconds, ' s, ',
            mb_strlen((string) $g->input_text), ' letters in ', count(preg_split('/\s+/u', trim((string) $g->input_text), -1, PREG_SPLIT_NO_EMPTY)), ' words', $g->audio_path ? '' : ', NO AUDIO KEPT', "\n";
        echo '    voice: ', $voiceLabel($g->voice_name), "\n";
        // To tell apart voices of one length: the audio's exact length, level and where its
        // pauses start (ffmpeg only reads the file; the owner runs the same on the audio they have).
        $file = $g->audio_path ? Illuminate\Support\Facades\Storage::disk('public')->path((string) $g->audio_path) : '';
        if ($file !== '' && is_file($file)) {
            $ffmpeg = is_executable('storage/app/bin/ffmpeg') ? 'storage/app/bin/ffmpeg' : 'ffmpeg';
            $run = Symfony\Component\Process\Process::fromShellCommandline($ffmpeg.' -hide_banner -nostats -i "$F" -af silencedetect=noise=-35dB:d=0.35,volumedetect -f null - 2>&1');
            $run->setTimeout(60)->run(null, ['F' => $file]);
            $out = $run->getOutput();
            preg_match_all('/silence_start: ([\d.]+)/', $out, $starts);
            preg_match('/Duration: (\d+):(\d+):([\d.]+)/', $out, $d);
            preg_match('/mean_volume: (-?[\d.]+) dB/', $out, $mean);
            echo '    audio: ', $d ? round($d[1] * 3600 + $d[2] * 60 + $d[3], 2).' s' : '?', ', mean level ', $mean[1] ?? '?', ' dB, pauses start at: ',
                implode(' ', array_map(fn ($s) => number_format((float) $s, 2, '.', ''), $starts[1])), "\n";
        } else {
            echo "    audio: no file kept\n";
        }
        if ($settings === null) {
            echo "    reuse: nothing to bring back (no voice saved with it)\n";

            continue;
        }
        foreach ($settings as $key => $value) {
            if ($key === 'style_prompt') {
                $value = '(written direction, '.mb_strlen((string) $value).' letters)';
            }
            echo '    ', $key, ': ', is_scalar($value) ? var_export($value, true) : json_encode($value, JSON_UNESCAPED_UNICODE), "\n";
        }
        $locks = $call('featureLocks', [$plan, $tier, false, $dialogue]);
        $today = $call('reuseForPlan', [$settings, $tier, $locks, $catalog, $own, (bool) $plan?->allowsCustomPrompt()]);
        // As the dashboard counts it: only a real choice is left out, not "off" nor normal speed and pitch.
        $dropped = array_keys(array_filter(array_diff_key($settings, $today), fn ($value, $key) => match ($key) {
            'speed' => (float) $value !== 1.0,
            'pitch' => (float) $value !== 0.0,
            default => ! in_array($value, [null, false, '', []], true),
        }, ARRAY_FILTER_USE_BOTH));
        echo '    on the plan today (', $plan?->slug ?? 'none', ', ', $tier, '): ', $dropped === [] ? 'everything comes back' : 'LEFT OUT: '.implode(', ', $dropped), "\n";
        echo '    link: ', $site, '/ar/dashboard?reuse=', $g->displayNumber(), '   (English: ', $site, '/en/dashboard?reuse=', $g->displayNumber(), ")\n";
    }
}
