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
    $windowed = str_contains(implode(' ', (array) $t->notes), 'window');
    if ($t->status === Transcription::READY && ! $t->trashed() && ! $windowed && (array) $t->segments !== []) {
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
