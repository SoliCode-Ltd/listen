<?php

namespace App\Console\Commands;

use App\Models\VideoDub;
use App\Services\Dubbing\VideoDubRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Dub my video: fails the dubs still working long after they started (their
 * process died) and gives back what they were charged, and deletes the files
 * of dubs older than listen.dubbing.keep_hours. The rows stay, so a free dub
 * stays used after its files are gone.
 */
class PruneVideoDubsCommand extends Command
{
    protected $signature = 'dubs:prune';

    protected $description = 'Fail stuck video dubs and delete the files of old ones.';

    public function handle(VideoDubRunner $runner): int
    {
        $stuck = VideoDub::query()->whereIn('status', [VideoDub::QUEUED, VideoDub::PROCESSING])
            ->where('updated_at', '<', now()->subMinutes(max(5, (int) config('listen.dubbing.stuck_minutes', 20))))
            ->pluck('id');
        foreach ($stuck as $id) {
            $runner->fail((int) $id, __('ui.dub.error_generic'));
        }

        $deleted = 0;
        VideoDub::query()->whereNull('files_deleted_at')->whereNotNull('folder')
            ->where('created_at', '<', now()->subHours(max(1, (int) config('listen.dubbing.keep_hours', 48))))
            ->whereNotIn('status', [VideoDub::QUEUED, VideoDub::PROCESSING])
            ->chunkById(100, function ($dubs) use (&$deleted): void {
                foreach ($dubs as $dub) {
                    Storage::disk('local')->deleteDirectory($dub->folder);
                    $dub->update(['files_deleted_at' => now()]);
                    $deleted++;
                }
            });

        $this->info(sprintf('%d stuck dub(s) failed, files of %d old dub(s) deleted.', $stuck->count(), $deleted));

        return self::SUCCESS;
    }
}
