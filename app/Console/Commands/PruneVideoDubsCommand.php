<?php

namespace App\Console\Commands;

use App\Http\Controllers\VideoDubController;
use App\Models\VideoDub;
use App\Services\Dubbing\DriveArchive;
use App\Services\Dubbing\VideoDubRunner;
use App\Support\VideoDubbing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Dub my video: fails the dubs still working long after they started (their
 * process died) and gives back what they were charged; moves finished videos
 * to the site's Google Drive (a paid one that could not go when it was made,
 * a free one once its member's day is over); with Drive off, deletes the
 * server copies older than the admin's days whose members' time is over; and
 * deletes the pieces of videos whose sending was never finished.
 * The rows stay, so a free dub stays used after its files are gone.
 */
class PruneVideoDubsCommand extends Command
{
    protected $signature = 'dubs:prune';

    protected $description = 'Fail stuck video dubs, move finished videos to Google Drive, delete old server copies.';

    /** Videos sent to Drive in one run, so a long backlog never holds the scheduler. */
    private const ARCHIVE_PER_RUN = 10;

    public function handle(VideoDubRunner $runner): int
    {
        $stuck = VideoDub::query()->whereIn('status', [VideoDub::QUEUED, VideoDub::PROCESSING])
            ->where('updated_at', '<', now()->subMinutes(max(5, (int) config('listen.dubbing.stuck_minutes', 20))))
            ->pluck('id');
        foreach ($stuck as $id) {
            $runner->fail((int) $id, 'ui.dub.error_generic');
        }

        $archived = 0;
        if (DriveArchive::active()) {
            VideoDub::query()->where('status', VideoDub::READY)->where('has_clean', true)
                ->whereNull('drive_file_id')->whereNull('files_deleted_at')->whereNotNull('folder')
                ->where(fn ($q) => $q->whereNotNull('reservation')->orWhere('created_at', '<', now()->subHours(VideoDubbing::freeKeepHours())))
                ->orderBy('id')->limit(self::ARCHIVE_PER_RUN)->get()
                ->each(function (VideoDub $dub) use ($runner, &$archived): void {
                    $archived += $runner->archive($dub) ? 1 : 0;
                });
        }

        $deleted = 0;
        VideoDub::query()->whereNull('files_deleted_at')->whereNull('drive_file_id')->whereNotNull('folder')
            ->whereNotIn('status', [VideoDub::QUEUED, VideoDub::PROCESSING])
            ->where('created_at', '<', now()->subHours(VideoDubbing::freeKeepHours()))
            ->chunkById(100, function ($dubs) use (&$deleted): void {
                foreach ($dubs as $dub) {
                    if (! $this->mayDelete($dub)) {
                        continue;
                    }
                    Storage::disk('local')->deleteDirectory($dub->folder);
                    $dub->update(['files_deleted_at' => now()]);
                    $deleted++;
                }
            });

        // Videos whose sending was never finished (the page was closed half way).
        $abandoned = 0;
        $disk = Storage::disk('local');
        foreach ($disk->files(VideoDubController::UPLOADS) as $part) {
            if ($disk->lastModified($part) < now()->subHours(VideoDubController::UPLOAD_HOURS)->getTimestamp()) {
                $disk->delete($part);
                $abandoned++;
            }
        }

        $this->info(sprintf('%d stuck dub(s) failed, %d video(s) moved to Google Drive, files of %d old dub(s) deleted, %d unfinished upload(s) deleted.',
            $stuck->count(), $archived, $deleted, $abandoned));

        return self::SUCCESS;
    }

    /**
     * A failed dub's leftovers go after a day. A finished video waits for
     * Google Drive while it is on; with it off, it stays on the server for
     * the admin's days (0: always), and never while its member can still
     * watch it.
     */
    private function mayDelete(VideoDub $dub): bool
    {
        if ($dub->status !== VideoDub::READY) {
            return true;
        }
        if (DriveArchive::active()) {
            return false;
        }
        $days = VideoDubbing::serverKeepDays();

        return $days > 0 && $dub->created_at?->lt(now()->subDays($days)) && ! VideoDubbing::available($dub);
    }
}
