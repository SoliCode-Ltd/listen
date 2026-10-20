<?php

namespace App\Services\Dubbing;

use App\Jobs\GenerateAudioJob;
use App\Models\VideoDub;
use App\Services\Billing\MemberUsageMeter;
use App\Support\VideoDubbing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Dubs a video a member sent (App\Http\Controllers\VideoDubController), after
 * the reply has been sent, in the same PHP process, as the studio makes long
 * texts: no queue worker is needed and the web server's time limit never cuts
 * it short. A dub that fails gives back what its plan was charged, once. A
 * paid one then goes to the site's Google Drive, when it is switched on.
 */
class VideoDubRunner
{
    /** A minute of video takes about two minutes; this leaves room for a slow day. */
    public const SECONDS = 600;

    public function __construct(private VideoDubber $dubber, private MemberUsageMeter $meter) {}

    public static function runAfterResponse(int $dubId): void
    {
        dispatch(function () use ($dubId): void {
            ignore_user_abort(true);
            $limit = (int) ini_get('max_execution_time');
            if ($limit > 0 && $limit < self::SECONDS && function_exists('set_time_limit')) {
                @set_time_limit(self::SECONDS);
            }
            GenerateAudioJob::raiseMemoryLimit('512M');
            app(self::class)->run($dubId);
        })->afterResponse();
    }

    public function run(int $dubId): void
    {
        // Claimed once: a second run of the same dub does nothing.
        if (VideoDub::query()->whereKey($dubId)->where('status', VideoDub::QUEUED)->update(['status' => VideoDub::PROCESSING, 'updated_at' => now()]) !== 1) {
            return;
        }
        $dub = VideoDub::query()->findOrFail($dubId);
        $disk = Storage::disk('local');
        $source = collect($disk->files($dub->folder))->first(fn (string $f): bool => str_starts_with(basename($f), 'source.'));

        try {
            if ($source === null) {
                throw new \RuntimeException('The uploaded video is missing.');
            }
            $report = $this->dubber->dub($disk->path($source), [
                'language' => $dub->language ?: 'ar',
                'dialect' => $dub->dialect,
                'voice' => $dub->voice,
                // The other people in the video get voices of their own.
                'voices' => VideoDubbing::voicePool($dub->dialect, $dub->language ?: 'ar'),
                // Empty: each line as the video says it.
                'style' => $dub->performance_style,
                'emotion' => $dub->emotion,
                'background' => $dub->keep_background === false ? 'replace' : 'keep',
                // Only a free dub is watched with the mark; a paid one is watched as it is downloaded.
                'preview' => $dub->is_free,
                'out_dir' => $disk->path($dub->folder),
                'name' => 'dub',
                'progress' => fn (string $stage, int $done, int $total) => $this->progress($dub, $stage, $done, $total),
            ]);
            unset($report['output'], $report['preview']);
            $dub->update([
                'status' => VideoDub::READY,
                'stage' => 'ready',
                'progress' => 100,
                'has_clean' => $disk->exists($dub->path('dub.mp4')),
                'has_preview' => $disk->exists($dub->path('dub-preview.mp4')),
                'report' => $report,
            ]);
        } catch (\Throwable $e) {
            Log::warning('A video dub failed.', ['video_dub_id' => $dub->id, 'error' => $e->getMessage()]);
            $this->fail($dub->id, self::messageFor($e));

            return;
        } finally {
            if ($source !== null) {
                $disk->delete($source);
            }
        }

        // A paid dub goes to Google Drive now; a free one once its member's day is over (dubs:prune).
        $dub = $dub->fresh();
        if (is_array($dub?->reservation)) {
            $this->archive($dub);
        }
    }

    /**
     * A dub goes to the site's Google Drive (DriveArchive) and off the
     * server; when Drive is off or refuses, it stays here and dubs:prune
     * tries again.
     */
    public function archive(?VideoDub $dub): bool
    {
        if (! $dub || ! DriveArchive::active()) {
            return false;
        }
        // One upload of a video at a time: the run that made it and the sweep may both try.
        $lock = Cache::lock('video-dub-drive:'.$dub->id, 1800);
        if (! $lock->get()) {
            return false;
        }
        try {
            $fresh = $dub->fresh();

            return $fresh !== null && app(DriveArchive::class)->archive($fresh);
        } catch (\Throwable $e) {
            Log::warning('A dubbed video could not be put in Google Drive; it stays on the server for now.', ['video_dub_id' => $dub->id, 'error' => $e->getMessage()]);

            return false;
        } finally {
            $lock->release();
        }
    }

    /**
     * How far along the dub is, for the page's progress bar: listening is the
     * first tenth, voicing runs to four fifths line by line, then the mix
     * and, for a free dub, the mark.
     */
    private function progress(VideoDub $dub, string $stage, int $done, int $total): void
    {
        $percent = match ($stage) {
            'listening' => 8,
            'voicing' => 15 + (int) round(65 * $done / max(1, $total)),
            'mixing' => 85,
            'marking' => 92,
            default => (int) $dub->progress,
        };
        $dub->forceFill(['stage' => $stage, 'progress' => max((int) $dub->progress, min(99, $percent))])->save();
    }

    /**
     * An admin takes a finished (or failed) dub away to let the member dub
     * again: its videos are deleted and what it took is given back, exactly
     * once: the free dub, or the month's video and the credits (a failed
     * dub gave its credits back already). The row stays, marked removed,
     * so the figures still show it. A dub still working is left alone.
     */
    public function remove(int $dubId): bool
    {
        $removed = DB::transaction(function () use ($dubId): ?VideoDub {
            $dub = VideoDub::query()->whereKey($dubId)->lockForUpdate()->first();
            if (! $dub || $dub->isWorking() || $dub->status === VideoDub::REMOVED) {
                return null;
            }
            if (is_array($dub->reservation) && $dub->status !== VideoDub::FAILED) {
                $this->meter->giveBack((int) $dub->user_id, $dub->reservation);
            }
            $dub->update(['status' => VideoDub::REMOVED, 'stage' => 'removed', 'files_deleted_at' => now()]);

            return $dub;
        }, 3);
        if ($removed?->folder) {
            Storage::disk('local')->deleteDirectory($removed->folder);
        }
        if ($removed?->drive_file_id) {
            $this->deleteFromDrive($removed);
        }

        return $removed !== null;
    }

    /** Its copy in Google Drive, deleted (an admin removed the dub, or deleted its videos). */
    public function deleteFromDrive(VideoDub $dub): void
    {
        try {
            app(DriveArchive::class)->delete((string) $dub->drive_file_id);
            $dub->forceFill(['drive_file_id' => null])->save();
        } catch (\Throwable $e) {
            Log::warning('A dubbed video could not be deleted from Google Drive.', ['video_dub_id' => $dub->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Marks a dub failed and gives back what its plan was charged, exactly
     * once however many places try (the run, the stuck-dub sweep).
     */
    public function fail(int $dubId, string $message): bool
    {
        return DB::transaction(function () use ($dubId, $message): bool {
            $dub = VideoDub::query()->whereKey($dubId)->lockForUpdate()->first();
            if (! $dub || ! $dub->isWorking()) {
                return false;
            }
            $dub->update(['status' => VideoDub::FAILED, 'stage' => 'failed', 'error_message' => mb_substr($message, 0, 500)]);
            if (is_array($dub->reservation)) {
                $this->meter->giveBack((int) $dub->user_id, $dub->reservation);
            }

            return true;
        }, 3);
    }

    /**
     * What the member is told, kept as its translation key (VideoDub::errorText()
     * says it in the language of whoever reads it): the dubber's own words when
     * they are about the video, else a plain apology.
     */
    private static function messageFor(\Throwable $e): string
    {
        return match (true) {
            str_contains($e->getMessage(), 'No speech') => 'ui.dub.error_no_speech',
            str_contains($e->getMessage(), 'could not be read') => 'ui.dub.error_unreadable',
            default => 'ui.dub.error_generic',
        };
    }
}
