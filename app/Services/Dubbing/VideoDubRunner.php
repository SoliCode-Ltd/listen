<?php

namespace App\Services\Dubbing;

use App\Jobs\GenerateAudioJob;
use App\Models\VideoDub;
use App\Services\Billing\MemberUsageMeter;
use App\Support\VideoDubbing;
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
        } finally {
            if ($source !== null) {
                $disk->delete($source);
            }
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
