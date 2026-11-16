<?php

/*
 * For the "Server deploy" workflow's stt-queue mode, on STAGING only. It uses no
 * speech quota: nothing is sent to the model.
 *
 *  1. A three-hour file made ready the way a transcript's first two steps do it,
 *     timed: the clean copy, its waveform's peaks and the measuring. Each step
 *     must stay well inside the 140 s a queue step may take.
 *  2. Speech to text's own workers: four minutes of silence for a throwaway
 *     member, started through the real queue (over three minutes, so it is not
 *     worked through right away), must be picked up by the workers the schedule
 *     starts (TranscribeJob::QUEUE), made clean with its peaks, measured, and
 *     refused as silence with its minutes given back. The seconds to each step
 *     are printed.
 *  3. The throwaway member deleted the way the admin deletes an account: the
 *     transcript's folder must go with it.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/stt_queue_check.php
 */

use App\Jobs\TranscribeJob;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Transcription;
use App\Models\User;
use App\Services\Transcription\Transcriber;
use App\Services\Transcription\TranscriptionFlow;
use App\Services\UserDeletionService;
use App\Support\AudioFormats;
use App\Support\LiveServer;
use App\Support\Transcription\TranscriptionSettings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_time_limit(0);
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";

if (LiveServer::allowsCustomerActions() || str_contains(strtolower((string) config('app.url')), (string) config('listen.live_host', 'listenai.studio'))) {
    echo "This is the live site: stt-queue runs on staging only.\n";
    exit(1);
}
$ffmpeg = (string) AudioFormats::ffmpegPath();
if ($ffmpeg === '') {
    echo "No ffmpeg on this server.\n";
    exit(1);
}
$failed = 0;
$check = function (bool $ok, string $what) use (&$failed): void {
    echo '  ', $ok ? 'ok  ' : 'FAIL', '  ', $what, "\n";
    $failed += $ok ? 0 : 1;
};
$tmp = sys_get_temp_dir().'/stt-queue-'.bin2hex(random_bytes(4));
@mkdir($tmp, 0700, true);
$transcriber = app(Transcriber::class);

// ---------------------------------------------------------------- 1. a three-hour file
echo "\n== A three-hour file made ready (ffmpeg only, nothing sent) ==\n";
try {
    $at = microtime(true);
    // Speech-like sound: 4 s of tone, then 0.9 s of quiet, for three hours.
    (new Process([$ffmpeg, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=220:duration=4', '-f', 'lavfi', '-i', 'anullsrc=r=16000:cl=mono',
        '-filter_complex', '[0:a]aformat=sample_rates=16000:channel_layouts=mono,volume=0.5[s];[1:a]atrim=0:0.9[q];[s][q]concat=n=2:v=0:a=1', $tmp.'/one.wav']))->setTimeout(120)->mustRun();
    (new Process([$ffmpeg, '-v', 'error', '-y', '-stream_loop', '2210', '-i', $tmp.'/one.wav', '-t', '10800', '-c:a', 'libmp3lame', '-b:a', '64k', $tmp.'/long.mp3']))->setTimeout(900)->mustRun();
    printf("  test file made: %.0f MB in %.0f s\n", filesize($tmp.'/long.mp3') / 1048576, microtime(true) - $at);
    $at = microtime(true);
    $transcriber->clean($tmp.'/long.mp3', $tmp.'/audio.mp3');
    $clean = microtime(true) - $at;
    $at = microtime(true);
    Transcriber::peaks($tmp.'/audio.mp3', $tmp.'/'.Transcriber::PEAKS_FILE);
    $peaks = microtime(true) - $at;
    printf("  step 1: the clean copy %.0f s and its waveform's peaks %.0f s (%d peaks): %.0f s together\n", $clean, $peaks, filesize($tmp.'/'.Transcriber::PEAKS_FILE), $clean + $peaks);
    $check($clean + $peaks < 110, 'the first step stays inside the 140 s a queue step may take');
    $check(abs(filesize($tmp.'/'.Transcriber::PEAKS_FILE) - 10800 * Transcriber::PEAKS_PER_SECOND) < 200, 'a peak for every 10 ms of the three hours');
    $at = microtime(true);
    $state = $transcriber->prepared($tmp.'/audio.mp3', ['language' => 'auto']);
    $measure = microtime(true) - $at;
    printf("  step 2, measured and planned: %.0f s (%.0f s long, %d parts)\n", $measure, $state['seconds'], count($state['plan']));
    $check($measure < 110, 'the second step stays inside it too');
} catch (Throwable $e) {
    $check(false, 'the three-hour file: '.mb_substr($e->getMessage(), 0, 200));
}

// ---------------------------------------------------------------- 2. the workers
echo "\n== Speech to text's own workers ==\n";
// This process only: the audience below is seen by nobody else (the workers do not ask it).
config(['cache.default' => 'array']);
Cache::put('site_setting.transcription_audience', TranscriptionSettings::AUDIENCE_EVERYONE, 3600);
$plan = Plan::query()->where('transcription_enabled', true)->where('transcription_minutes_month', '>', 0)->where('transcription_priority', false)->orderBy('price_cents')->first();
$member = null;
$folder = null;
if (! $plan) {
    $check(false, 'a plan with speech to text on the standard queue');
} else {
    $stamp = now()->format('YmdHis');
    $member = User::query()->create(['name' => 'Speech to text queue check', 'email' => "stt-queue-{$stamp}@example.invalid", 'password' => bin2hex(random_bytes(16))]);
    $member->forceFill(['email_verified_at' => now(), 'subscription_status_checked_at' => now()])->save();
    Subscription::query()->create(['user_id' => $member->id, 'plan_id' => $plan->id, 'status' => 'active',
        'current_period_start' => now()->startOfDay(), 'current_period_end' => now()->addMonth()]);
    echo "  throwaway member {$member->id}, plan {$plan->slug}\n";
    try {
        (new Process([$ffmpeg, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'anullsrc=r=16000:cl=mono', '-t', '240', '-c:a', 'libmp3lame', '-b:a', '32k', $tmp.'/quiet.mp3']))->setTimeout(120)->mustRun();
        $started = microtime(true);
        $t = app(TranscriptionFlow::class)->start($member, $tmp.'/quiet.mp3', ['title' => 'Queue check', 'source' => 'upload', 'file_name' => 'quiet.mp3']);
        $folder = TranscriptionFlow::folder($t);
        $queue = (string) DB::table('jobs')->where('payload', 'like', '%TranscribeJob%')->where('payload', 'like', '%'.$t->id.'%')->orderByDesc('id')->value('queue');
        printf("  started (%d s held), its first step queued on \"%s\"\n", $t->charged_seconds, $queue);
        $check($queue === TranscribeJob::QUEUE, 'on speech to text\'s own queue');
        $seen = [];
        $peaksFile = $folder.'/'.Transcriber::PEAKS_FILE;
        while (microtime(true) - $started < 300) {
            $t->refresh();
            $after = (int) round(microtime(true) - $started);
            if ($t->started_at !== null && ! isset($seen['picked'])) {
                $seen['picked'] = $after;
            }
            if (str_ends_with((string) $t->audio_path, '/audio.mp3') && ! isset($seen['clean'])) {
                $seen['clean'] = $after;
                $seen['peaks'] = Storage::disk(TranscriptionFlow::DISK)->exists($peaksFile);
            }
            if (! $t->isWorking()) {
                $seen['done'] = $after;
                break;
            }
            sleep(2);
        }
        printf("  picked up after %s s, clean copy after %s s, finished after %s s: %s (%s)\n",
            $seen['picked'] ?? '-', $seen['clean'] ?? '-', $seen['done'] ?? '-', $t->status, $t->error_code ?? '-');
        $check(isset($seen['picked']) && $seen['picked'] <= 75, 'a worker picked it up within a minute or so');
        $check(($seen['peaks'] ?? false) === true, 'its waveform\'s peaks were made with the clean copy');
        $check($t->status === Transcription::FAILED && $t->error_code === 'no_speech', 'silence is refused as no speech');
        $check($t->usedSeconds() === 0, 'and uses none of the minutes');
    } catch (Throwable $e) {
        $check(false, 'the queued file: '.mb_substr($e->getMessage(), 0, 200));
    }
}

// ---------------------------------------------------------------- 3. the account deleted
echo "\n== The throwaway member deleted, as the admin deletes an account ==\n";
if ($member !== null) {
    try {
        // The admin deletes an account only without a running plan: the plan goes first.
        Subscription::query()->where('user_id', $member->id)->delete();
        $refusal = app(UserDeletionService::class)->delete($member->fresh(), null);
        $check($refusal === null, 'the account is deleted'.($refusal !== null ? ' (refused: '.$refusal.')' : ''));
        if ($folder !== null) {
            $check(! Storage::disk(TranscriptionFlow::DISK)->exists($folder), 'its transcript\'s folder went with it');
        }
        $check(Transcription::withTrashed()->where('user_id', $member->id)->doesntExist(), 'and its transcript');
    } catch (Throwable $e) {
        $check(false, 'deleting the member: '.mb_substr($e->getMessage(), 0, 200));
    } finally {
        if (User::query()->whereKey($member->id)->exists()) {
            Transcription::withTrashed()->where('user_id', $member->id)->get()->each(function (Transcription $t): void {
                Storage::disk(TranscriptionFlow::DISK)->deleteDirectory(TranscriptionFlow::folder($t));
                $t->forceDelete();
            });
            Subscription::query()->where('user_id', $member->id)->delete();
            User::query()->whereKey($member->id)->delete();
            echo "  (removed by hand)\n";
        }
    }
}
foreach ((array) glob($tmp.'/*') as $f) {
    @unlink((string) $f);
}
@rmdir($tmp);

echo "\n", $failed === 0 ? 'ALL CHECKS PASSED' : $failed.' CHECK(S) FAILED', "\n";
exit($failed === 0 ? 0 : 1);
