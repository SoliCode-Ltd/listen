<?php

namespace App\Console\Commands;

use App\Models\Transcription;
use App\Services\Transcription\TranscriptionFlow;
use App\Support\Transcription\TranscriptionSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Speech to text's housekeeping, every ten minutes: a transcript still working
 * after Speech to text → Settings' "stopped after" minutes (no step saved in that
 * time) is failed and its minutes given back; uploaded audio older than the
 * days set there is deleted (the text stays until the member deletes it);
 * uploads never finished are deleted after six hours.
 */
class PruneTranscriptionsCommand extends Command
{
    protected $signature = 'transcriptions:prune';

    protected $description = 'Fail stuck transcripts (minutes given back), delete old uploaded audio and unfinished uploads.';

    public function handle(TranscriptionFlow $flow): int
    {
        $stuck = 0;
        Transcription::withTrashed()->whereIn('status', Transcription::WORKING)
            ->where('updated_at', '<', now()->subMinutes(TranscriptionSettings::stuckMinutes()))
            ->each(function (Transcription $t) use ($flow, &$stuck): void {
                $stuck += $flow->fail($t, 'stuck', __('transcribe.failed.stuck')) ? 1 : 0;
            });

        $disk = Storage::disk(TranscriptionFlow::DISK);
        $deleted = 0;
        Transcription::withTrashed()->whereNull('audio_deleted_at')->whereNotIn('status', Transcription::WORKING)
            ->where(fn ($q) => $q->where('created_at', '<', now()->subDays(TranscriptionSettings::keepAudioDays()))->orWhereNotNull('deleted_at'))
            ->each(function (Transcription $t) use ($disk, &$deleted): void {
                $disk->deleteDirectory(TranscriptionFlow::folder($t));
                $t->forceFill(['audio_path' => null, 'audio_deleted_at' => now()])->saveQuietly();
                $deleted++;
            });

        $parts = 0;
        foreach ($disk->files('transcribe-uploads') as $file) {
            if ($disk->lastModified($file) < now()->subHours(6)->getTimestamp()) {
                $disk->delete($file);
                $parts++;
            }
        }

        $this->line(sprintf('Stuck transcripts failed (minutes given back): %d. Audio deleted: %d. Unfinished uploads deleted: %d.', $stuck, $deleted, $parts));

        return self::SUCCESS;
    }
}
