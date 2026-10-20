<?php

namespace App\Services\Dubbing;

use App\Jobs\GenerateAudioJob;
use App\Models\VideoDub;
use App\Services\Billing\MemberUsageMeter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Dubs a video a member sent (App\Http\Controllers\VideoDubController), after
 * the reply has been sent, in the same PHP process, as the studio makes long
 * texts: no queue worker is needed and the web server's time limit never cuts
 * it short. A dub that fails gives back what its plan was charged, once.
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
                'language' => 'ar',
                'dialect' => $dub->dialect,
                'voice' => $dub->voice,
                'style' => $dub->performance_style,
                'emotion' => $dub->emotion,
                // Only a free dub is watched with the mark; a paid one is watched as it is downloaded.
                'preview' => $dub->is_free,
                'out_dir' => $disk->path($dub->folder),
                'name' => 'dub',
            ]);
            unset($report['output'], $report['preview']);
            $dub->update([
                'status' => VideoDub::READY,
                'has_clean' => $disk->exists($dub->path('dub.mp4')),
                'has_preview' => $disk->exists($dub->path('dub-preview.mp4')),
                'report' => $report,
            ]);
        } catch (\Throwable $e) {
            Log::warning('A video dub failed.', ['video_dub_id' => $dub->id, 'error' => $e->getMessage()]);
            $this->fail($dub->id, self::messageFor($e));
        } finally {
            if ($source !== null) {
                $disk->delete($source);
            }
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
            $dub->update(['status' => VideoDub::FAILED, 'error_message' => mb_substr($message, 0, 500)]);
            if (is_array($dub->reservation)) {
                $this->meter->giveBack((int) $dub->user_id, $dub->reservation);
            }

            return true;
        }, 3);
    }

    /** What the member is told: the dubber's own words when they are about the video, else a plain apology. */
    private static function messageFor(\Throwable $e): string
    {
        return match (true) {
            str_contains($e->getMessage(), 'No speech') => __('ui.dub.error_no_speech'),
            str_contains($e->getMessage(), 'could not be read') => __('ui.dub.error_unreadable'),
            default => __('ui.dub.error_generic'),
        };
    }
}
