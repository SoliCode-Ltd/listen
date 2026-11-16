<?php

/*
 * For the "Server deploy" workflow's stt-check mode, on STAGING only: the last
 * transcripts made on staging (speech to text is not open on live yet, so these
 * are the owner's own tries), looked at with this server's code:
 *
 *  1. How loud each recording is and how much of it is voice, and whether the
 *     silence check would now refuse it (Transcriber::heardNothing()): a
 *     recording with no voice is never sent to the model, which can make up a
 *     sentence nobody said.
 *  2. A recording the check lets through is written out again with this code
 *     (nothing saved), to compare with what was made.
 *  3. A finished transcript made before the windowed timing has its sentences
 *     placed on its recording again (its words kept as they are), and saved:
 *     the old and new start of each sentence are printed.
 *
 * It uses the Gemini quota staging shares with live (a few requests for a few
 * minutes of audio), and makes no audio. It refuses to run on the live site.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/stt_check.php
 */

use App\Models\Transcription;
use App\Services\Transcription\Transcriber;
use App\Services\Transcription\TranscriptionFlow;
use App\Support\AudioFormats;
use App\Support\LiveServer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
ini_set('memory_limit', '1024M');
set_time_limit(0);
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";

if (LiveServer::allowsCustomerActions() || str_contains(strtolower((string) config('app.url')), (string) config('listen.live_host', 'listenai.studio'))) {
    echo "This is the live site: stt-check runs on staging only.\n";
    exit(1);
}

// ---------------------------------------------------------------- what is working, waiting or stuck (read only)
echo "\n== The last transcripts, whatever their state ==\n";
foreach (Transcription::withTrashed()->latest('id')->limit(6)->get() as $t) {
    printf("  %s  %s UTC  %s  %.1f s  status %s%s  started %s  finished %s  parts %d/%d  work %s%s\n",
        $t->code, $t->created_at?->utc()->format('H:i:s'), $t->source, (float) $t->seconds, $t->status, $t->error_code ? " ({$t->error_code})" : '',
        $t->started_at?->utc()->format('H:i:s') ?? '-', $t->finished_at?->utc()->format('H:i:s') ?? '-', (int) $t->parts_done, (int) $t->parts,
        $t->work === null ? 'none' : 'saved', $t->trashed() ? ', deleted' : '');
    $notes = array_slice((array) $t->notes, -3);
    if ($notes !== []) {
        echo '      notes: ', implode(' | ', $notes), "\n";
    }
    if ($t->work !== null && isset($t->work['speech_seconds'])) {
        printf("      voice %.1f s, loudest %.1f dB\n", (float) $t->work['speech_seconds'], (float) ($t->work['loudest_db'] ?? 0));
    }
}
echo "\n== The queue ==\n";
echo '  connection: ', config('queue.default'), '; cache: ', config('cache.default'), "\n";
try {
    $lock = Cache::lock('stt-check-lock', 10);
    echo '  a cache lock: ', $lock->get() ? 'works' : 'could not be taken', "\n";
    $lock->release();
} catch (Throwable $e) {
    echo '  a cache lock: FAILS: ', mb_substr($e->getMessage(), 0, 200), "\n";
}
try {
    $waiting = DB::table('jobs')->where('payload', 'like', '%TranscribeJob%')->get(['queue', 'attempts', 'available_at', 'reserved_at']);
    echo '  speech to text jobs waiting: ', count($waiting), "\n";
    foreach ($waiting as $j) {
        printf("    queue %s, tries %d, due %s, taken %s\n", $j->queue, $j->attempts, date('H:i:s', (int) $j->available_at), $j->reserved_at ? date('H:i:s', (int) $j->reserved_at) : 'no');
    }
    echo '  all jobs waiting: ', DB::table('jobs')->count(), "\n";
    foreach (DB::table('failed_jobs')->where('payload', 'like', '%TranscribeJob%')->latest('id')->limit(3)->get(['failed_at', 'exception']) as $f) {
        echo '  failed ', $f->failed_at, ': ', mb_substr(strtok((string) $f->exception, "\n"), 0, 300), "\n";
    }
} catch (Throwable $e) {
    echo '  the jobs table could not be read: ', mb_substr($e->getMessage(), 0, 160), "\n";
}
$log = storage_path('logs/laravel.log');
if (is_file($log)) {
    $tail = array_slice(file($log, FILE_IGNORE_NEW_LINES) ?: [], -4000);
    $lines = array_values(array_filter($tail, fn ($l) => str_contains($l, 'Speech to text') || str_contains($l, 'TranscribeJob') || str_contains($l, 'Transcri')));
    echo "\n== The log: speech to text lines, last 12 ==\n";
    foreach (array_slice($lines, -12) as $l) {
        echo '  ', mb_substr($l, 0, 400), "\n";
    }
}

$transcriber = app(Transcriber::class);
$disk = Storage::disk(TranscriptionFlow::DISK);
$ffmpeg = AudioFormats::ffmpegPath() ?: 'ffmpeg';
// Deleted ones too (a member's delete keeps the row for the minutes): their recording may still be here.
$recent = Transcription::withTrashed()->whereNotNull('audio_path')->whereNull('audio_deleted_at')->latest('id')->limit(6)->get();
echo count($recent), " recent transcript(s) with their recording.\n";

foreach ($recent as $t) {
    echo "\n== {$t->code}: {$t->created_at?->utc()->toDateTimeString()} UTC, {$t->source}", $t->trashed() ? ', deleted by the member' : '', ', ', round((float) $t->seconds, 2), " s, status {$t->status}",
    $t->error_code ? " ({$t->error_code})" : '', ', language ', $t->language ?: '-', " ==\n";
    $clean = $disk->path((string) $t->audio_path);
    if (! is_file($clean)) {
        echo "  the recording is not on the disk\n";

        continue;
    }
    // 1. Loudness and voice.
    $volume = new Process([$ffmpeg, '-hide_banner', '-nostats', '-i', $clean, '-af', 'volumedetect', '-f', 'null', '-']);
    $volume->setTimeout(300)->run();
    preg_match('/mean_volume:\s*(\S+) dB/', $volume->getErrorOutput(), $mean);
    $copy = sys_get_temp_dir().'/stt-check-'.bin2hex(random_bytes(4)).'.mp3';
    $state = $transcriber->prepare($clean, $copy, ['language' => 'auto']);
    @unlink($copy);
    $refused = Transcriber::heardNothing($state);
    printf("  loudest %.1f dB, average %s dB, voice heard for %.1f s of %.1f s: the silence check %s\n",
        $state['loudest_db'], $mean[1] ?? '?', $state['speech_seconds'], $state['seconds'], $refused ? 'REFUSES it now (not sent to the model, nothing used)' : 'lets it through');
    echo '  notes: ', implode(' | ', array_slice((array) $t->notes, -4)), "\n";
    echo '  made then: ', mb_substr(preg_replace('/\s+/u', ' ', (string) collect((array) $t->paragraphs)->pluck('text')->implode(' ')) ?? '', 0, 220), "\n";

    // 2. Written out again with this code (only what the check lets through; not saved).
    if (! $refused && (float) $t->seconds <= 60) {
        try {
            $again = $transcriber->transcribe($clean, ['language' => 'auto']);
            echo '  written out again now (', $again['language'] ?: '-', '): ', mb_substr(preg_replace('/\s+/u', ' ', $again['text']) ?? '', 0, 220), "\n";
            echo '  ', Transcriber::nothingWritten($again) ? 'no words: it would fail as "no speech", nothing used' : 'words written', "\n";
        } catch (Throwable $e) {
            echo '  writing out again failed: ', mb_substr($e->getMessage(), 0, 200), "\n";
        }
    }

    // 3. Placed again in windows, for a finished transcript made before them.
    // Timed again with the code as it is now (its words kept), for a finished one up to 10 minutes.
    if ($t->status === Transcription::READY && ! $t->trashed() && (float) $t->seconds <= 600 && (array) $t->segments !== []) {
        $before = array_values((array) $t->segments);
        $new = $transcriber->retime($clean, $before);
        if ($new === null) {
            echo "  timing again: could not be timed, left as it was\n";

            continue;
        }
        $segments = array_map(fn ($s) => ['start' => round((float) $s['start'], 2), 'end' => round((float) $s['end'], 2),
            'speaker' => (string) $s['speaker'], 'text' => (string) $s['text'], 'paragraph' => (int) $s['paragraph']], $new['segments']);
        $paragraphs = array_values((array) $t->paragraphs);
        foreach ($paragraphs as $i => $p) {
            $own = array_values(array_filter($segments, fn ($s) => $s['paragraph'] === $i));
            if ($own !== []) {
                $paragraphs[$i]['start'] = $own[0]['start'];
                $paragraphs[$i]['end'] = end($own)['end'];
            }
        }
        $t->forceFill(['segments' => $segments, 'paragraphs' => $paragraphs,
            'notes' => array_slice(array_merge((array) $t->notes, $new['notes'], ['times: placed again in windows (stt-check)']), -40)])->save();
        echo '  timing again: ', implode(' | ', $new['notes']), ", {$new['requests']} request(s); saved\n";
        foreach ($segments as $i => $s) {
            printf("    %5.1f -> %5.1f  %s\n", (float) ($before[$i]['start'] ?? 0), $s['start'], mb_substr($s['text'], 0, 70));
        }
    }
}
echo "\nDone.\n";
