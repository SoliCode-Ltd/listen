<?php

namespace App\Jobs;

use App\Models\Transcription;
use App\Services\Dubbing\SpeechNotWrittenOut;
use App\Services\Transcription\StepOutOfTime;
use App\Services\Transcription\Transcriber;
use App\Services\Transcription\TranscriptionFlow;
use App\Support\Transcription\TranscriptionSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * One step of a transcript, then the next queued: first the recording made
 * the clean MP3 the model hears (Transcriber::clean()), then measured, its
 * pauses found and its parts planned (Transcriber::prepared()), then one
 * part heard per run (Transcriber::step()), its
 * progress saved on the transcript, until the last, which saves the
 * transcript (TranscriptionFlow::finish()). Each run stays well inside the
 * queue workers' 150 s, however long the recording: a three-hour file is
 * some forty short runs. A run the worker loses is run again from the saved
 * progress; a transcript that cannot be written out is failed, and the
 * minutes held for it given back (TranscriptionFlow::fail()).
 */
class TranscribeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 140;

    /** @var array<int, int> */
    public array $backoff = [15, 45];

    /**
     * Speech to text's own queues, worked by its own workers (routes/console.php), so a long
     * transcript never keeps the studio's background voices waiting on the shared queues.
     */
    public const QUEUE = 'transcribe';

    public const QUEUE_PRIORITY = 'transcribe-high';

    /** How long one step may run, inside the queue's $timeout for it: room is left to save it. */
    public const STEP_SECONDS = 115;

    /** Short files worked through right away (runNow()) at the same time on this server: each keeps a web worker. */
    public const RIGHT_AWAY_AT_ONCE = 4;

    public function __construct(public int $transcriptionId) {}

    /** The next step on the queue its plan gives it (priority first). */
    public static function queueFor(Transcription $transcription): void
    {
        static::dispatch($transcription->id)->onQueue($transcription->priority ? self::QUEUE_PRIORITY : self::QUEUE);
    }

    public function handle(Transcriber $transcriber, TranscriptionFlow $flow): void
    {
        // One runner at a time on a transcript: while a short one is being worked through right
        // after its upload (runNow()), the queued copy comes back a little later (not as a try).
        $lock = Cache::lock(self::lockName($this->transcriptionId), $this->timeout + 30);
        if (! $lock->get()) {
            $t = Transcription::withTrashed()->find($this->transcriptionId);
            if ($t && $t->isWorking() && config('queue.default') !== 'sync') {
                static::dispatch($t->id)->onQueue($t->priority ? self::QUEUE_PRIORITY : self::QUEUE)->delay(now()->addSeconds(10));
            }

            return;
        }
        try {
            $more = $this->stepOnce($transcriber, $flow);
        } finally {
            $lock->release();
        }
        // Queued once the lock is let go (on a queue that runs at once, the next step starts here).
        $t = $more ? Transcription::withTrashed()->find($this->transcriptionId) : null;
        if ($t) {
            static::queueFor($t);
        }
    }

    /**
     * A short file worked through at once, step after step in this process (called once the
     * member's page has its answer), so it never waits for a queue worker. The queued job stays
     * as the safety net: it finds the transcript done, or carries on from the last step saved.
     */
    public static function runNow(int $transcriptionId): void
    {
        // A few at once on this server, each in the web worker that took the file; the rest wait
        // for the queue, which is their safety net anyway.
        $slot = self::rightAwaySlot();
        if ($slot === null) {
            return;
        }
        try {
            // A web request has a time limit: give it room for this. A command has none, so none is set.
            if ((int) ini_get('max_execution_time') > 0) {
                @set_time_limit(200);
            }
            $until = microtime(true) + 150;
            $job = new static($transcriptionId);
            $slips = 0;
            while (microtime(true) < $until) {
                $lock = Cache::lock(self::lockName($transcriptionId), $job->timeout + 30);
                if (! $lock->get()) {
                    return;
                }
                try {
                    if (! $job->stepOnce(app(Transcriber::class), app(TranscriptionFlow::class), $until + 10)) {
                        return;
                    }
                } catch (\Throwable $e) {
                    // A model that did not answer this once is asked again here after a breath; a second
                    // slip leaves it to the queue, which tries again later.
                    if (++$slips < 2 && ! $e instanceof StepOutOfTime) {
                        $lock->release();
                        sleep(2);

                        continue;
                    }
                    Log::warning('Speech to text: left to the queue.', ['transcription' => $transcriptionId, 'error' => mb_substr($e->getMessage(), 0, 300)]);

                    return;
                } finally {
                    $lock->release();
                }
            }
        } finally {
            $slot->release();
        }
    }

    /** A free place among the RIGHT_AWAY_AT_ONCE right-away runs, or null when all are taken. */
    private static function rightAwaySlot(): ?Lock
    {
        for ($i = 1; $i <= self::RIGHT_AWAY_AT_ONCE; $i++) {
            $lock = Cache::lock('transcribe-right-away:'.$i, 300);
            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }

    private static function lockName(int $id): string
    {
        return 'transcribe-run:'.$id;
    }

    /** How many times one step may be started (by the queue, its retries, or runNow()) before the transcript is failed. */
    public const STEP_TRIES = 5;

    /** One step done and saved, within its own time ($until: when runNow() ends, if sooner); whether another is left. */
    private function stepOnce(Transcriber $transcriber, TranscriptionFlow $flow, ?float $until = null): bool
    {
        $transcriber->until(min($until ?? INF, microtime(true) + self::STEP_SECONDS));
        try {
            return $this->stepWithin($transcriber, $flow);
        } finally {
            $transcriber->until(null);
        }
    }

    private function stepWithin(Transcriber $transcriber, TranscriptionFlow $flow): bool
    {
        $t = Transcription::withTrashed()->find($this->transcriptionId);
        if (! $t || ! $t->isWorking()) {
            return false;
        }
        $disk = Storage::disk(TranscriptionFlow::DISK);
        $folder = TranscriptionFlow::folder($t);
        $clean = $disk->path($folder.'/audio.mp3');
        $cleaned = (string) $t->audio_path === $folder.'/audio.mp3';

        // A step that keeps being started and never finishes (a worker stopped on it each time)
        // fails the transcript after a few starts, its minutes given back, instead of going round.
        $stage = $t->work === null ? ($cleaned ? 'measure' : 'clean') : 'part-'.(int) ($t->work['n'] ?? 0);
        if (self::started($t->id, $stage) > self::STEP_TRIES) {
            Log::error('Speech to text: a step never finished.', ['transcription' => $t->id, 'step' => $stage]);
            $flow->fail($t, 'default', __('transcribe.failed.default'));

            return false;
        }

        try {
            if ($t->work === null && ! $cleaned) {
                // 1. The recording made the clean MP3 the model hears; the member's file is let go.
                $source = (string) $t->audio_path;
                if ($source === '' || ! $disk->exists($source)) {
                    $flow->fail($t, 'unreadable', __('transcribe.failed.unreadable'));

                    return false;
                }
                $transcriber->clean($disk->path($source), $clean);
                try {
                    Transcriber::peaks($clean, $disk->path($folder.'/'.Transcriber::PEAKS_FILE));
                } catch (\Throwable $e) {
                    // The page then draws the waveform from the recording itself, as before.
                    Log::info('Speech to text: no waveform peaks made.', ['transcription' => $t->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
                }
                if (! self::saveIfWorking($t, ['audio_path' => $folder.'/audio.mp3', 'started_at' => $t->started_at ?? now()])) {
                    return false;
                }
                $disk->delete($source);

                return true;
            }

            if ($t->work === null) {
                // 2. Measured (length, pauses, levels) and its parts planned.
                $state = $transcriber->prepared($clean, [
                    'language' => $t->language_requested, 'dialect' => (string) $t->dialect, 'review' => TranscriptionSettings::review(),
                ]);
                // Silence, a hum, or a microphone that did not pick up: not sent to the model, which
                // can make up a sentence nobody said; nothing is used.
                if (Transcriber::heardNothing($state)) {
                    $flow->fail($t, 'no_speech', __('transcribe.failed.no_speech'));

                    return false;
                }
                // What it uses is the length it really has, now it is decoded.
                if (! $flow->settleLength($t, (float) $state['seconds'])) {
                    return false;
                }

                return self::saveIfWorking($t, ['status' => Transcription::PROCESSING, 'work' => $state, 'parts' => count($state['plan']), 'parts_done' => 0]);
            }

            // 3. One part heard, then the next, until the last saves the transcript.
            $state = $t->work;
            $more = $transcriber->step($clean, $state);
            if (! self::saveIfWorking($t, ['work' => $state, 'parts_done' => (int) $state['n'], 'requests' => (int) $state['run']['requests'], 'usage' => $state['run']['usage']])) {
                return false;
            }
            if ($more) {
                return true;
            }
            $result = Transcriber::result($state);
            if (Transcriber::nothingWritten($result)) {
                $flow->fail($t, 'no_speech', __('transcribe.failed.no_speech'));

                return false;
            }
            $flow->finish($t, $result);

            return false;
        } catch (SpeechNotWrittenOut $e) {
            Log::warning('Speech to text: not written out.', ['transcription' => $t->id, 'finish' => $e->finish]);
            $flow->fail($t, 'not_written', __('transcribe.failed.not_written'));

            return false;
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'could not be read') || str_contains($e->getMessage(), 'ffmpeg')) {
                $flow->fail($t, 'unreadable', __('transcribe.failed.unreadable'));

                return false;
            }
            throw $e;
        }
    }

    /** How many times this step of this transcript has been started (counted for an hour). */
    private static function started(int $id, string $stage): int
    {
        $key = 'transcribe-step:'.$id.':'.$stage;
        Cache::add($key, 0, now()->addHour());

        return (int) Cache::increment($key);
    }

    /**
     * A step's progress saved only while the transcript is still working: one stopped meanwhile
     * (failed by the stuck check, the admin, or deleted with its account) is never brought back.
     *
     * @param  array<string, mixed>  $values
     */
    private static function saveIfWorking(Transcription $t, array $values): bool
    {
        return DB::transaction(function () use ($t, $values): bool {
            $fresh = Transcription::withTrashed()->whereKey($t->id)->lockForUpdate()->first();
            if (! $fresh || ! $fresh->isWorking()) {
                return false;
            }
            $fresh->forceFill($values)->save();
            $t->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });
    }

    /** Every try used up: the transcript fails and its minutes are given back. */
    public function failed(?\Throwable $e): void
    {
        $t = Transcription::withTrashed()->find($this->transcriptionId);
        if ($t) {
            Log::error('Speech to text: failed.', ['transcription' => $t->id, 'error' => $e?->getMessage()]);
            app(TranscriptionFlow::class)->fail($t, 'default', __('transcribe.failed.default'));
        }
    }
}
