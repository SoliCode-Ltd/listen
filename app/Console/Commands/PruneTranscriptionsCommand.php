<?php

namespace App\Console\Commands;

use App\Models\Transcription;
use App\Services\Transcription\TranscriptionFlow;
use App\Support\Transcription\TranscriptionSettings;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Speech to text's housekeeping, every ten minutes: a transcript being written
 * out with no step saved for Speech to text → Settings' "stopped after" minutes
 * is failed and its minutes given back (one still waiting for its first steps
 * gets QUEUED_STUCK_MINUTES: on a busy day it waits for its turn); a transcript
 * its member deleted keeps no words; uploaded audio older than the days set
 * there is deleted (the text stays until the member deletes it); uploads never
 * finished are deleted after four hours (one can be finished for three); and a
 * folder no transcript points to any more (its account was deleted) goes after
 * two days.
 */
class PruneTranscriptionsCommand extends Command
{
    protected $signature = 'transcriptions:prune';

    protected $description = 'Fail stuck transcripts (minutes given back), delete old uploaded audio and unfinished uploads.';

    public const QUEUED_STUCK_MINUTES = 120;

    public function handle(TranscriptionFlow $flow): int
    {
        // Between a deploy and its migration there is nothing to look after yet.
        if (! Schema::hasTable('transcriptions')) {
            $this->line('No transcripts on this server yet.');

            return self::SUCCESS;
        }

        $stuck = 0;
        $minutes = TranscriptionSettings::stuckMinutes();
        Transcription::withTrashed()
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('status', Transcription::PROCESSING)->where('updated_at', '<', now()->subMinutes($minutes)))
                ->orWhere(fn ($w) => $w->where('status', Transcription::QUEUED)->where('updated_at', '<', now()->subMinutes(max($minutes, self::QUEUED_STUCK_MINUTES)))))
            ->lazyById()
            ->each(function (Transcription $t) use ($flow, &$stuck): void {
                $stuck += $flow->fail($t, 'stuck', __('transcribe.failed.stuck')) ? 1 : 0;
            });

        // Deleted by its member before its words were cleared at once: cleared now.
        $forgotten = 0;
        Transcription::onlyTrashed()->whereNotNull('paragraphs')->lazyById()
            ->each(function (Transcription $t) use ($flow, &$forgotten): void {
                $flow->forgetWords($t);
                $forgotten++;
            });

        $disk = Storage::disk(TranscriptionFlow::DISK);
        $deleted = 0;
        Transcription::withTrashed()->whereNull('audio_deleted_at')->whereNotIn('status', Transcription::WORKING)
            ->where(fn ($q) => $q->where('created_at', '<', now()->subDays(TranscriptionSettings::keepAudioDays()))->orWhereNotNull('deleted_at'))
            ->lazyById()
            ->each(function (Transcription $t) use ($disk, &$deleted): void {
                $disk->deleteDirectory(TranscriptionFlow::folder($t));
                $t->forceFill(['audio_path' => null, 'audio_deleted_at' => now()])->saveQuietly();
                $deleted++;
            });

        $parts = 0;
        foreach ($disk->files('transcribe-uploads') as $file) {
            if ($disk->lastModified($file) < now()->subHours(4)->getTimestamp()) {
                $disk->delete($file);
                $parts++;
            }
        }

        $orphans = $this->orphanFolders($disk);

        $this->line(sprintf('Stuck transcripts failed (minutes given back): %d. Deleted transcripts cleared: %d. Audio deleted: %d. Unfinished uploads deleted: %d. Folders with no transcript deleted: %d.',
            $stuck, $forgotten, $deleted, $parts, $orphans));

        return self::SUCCESS;
    }

    /**
     * Transcript folders (transcriptions/{day}/{code}) whose transcript is gone, two days old or more:
     * a folder is made only after its transcript's row, so one without a row was left behind.
     */
    private function orphanFolders(Filesystem $disk): int
    {
        $before = now()->subDays(2)->format('Ymd');
        $orphans = 0;
        foreach ($disk->directories('transcriptions') as $day) {
            if (preg_match('/^\d{8}$/', basename($day)) !== 1 || basename($day) >= $before) {
                continue;
            }
            $folders = $disk->directories($day);
            foreach (array_chunk($folders, 500) as $some) {
                $known = Transcription::withTrashed()->whereIn('code', array_map('basename', $some))->pluck('code')->all();
                foreach ($some as $folder) {
                    if (! in_array(basename($folder), $known, true)) {
                        $disk->deleteDirectory($folder);
                        $orphans++;
                    }
                }
            }
            if ($disk->directories($day) === [] && $disk->files($day) === []) {
                $disk->deleteDirectory($day);
            }
        }

        return $orphans;
    }
}
