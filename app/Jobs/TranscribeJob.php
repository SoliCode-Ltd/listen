<?php

namespace App\Jobs;

use App\Models\Transcription;
use App\Services\Dubbing\SpeechNotWrittenOut;
use App\Services\Transcription\Transcriber;
use App\Services\Transcription\TranscriptionFlow;
use App\Support\Transcription\TranscriptionSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * One step of a transcript, then the next queued: first the recording made
 * ready (Transcriber::prepare(): cleaned, measured, its pauses found, its
 * parts planned), then one part heard per run (Transcriber::step()), its
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

    public function __construct(public int $transcriptionId) {}

    /** The next step on the queue its plan gives it (priority first). */
    public static function queueFor(Transcription $transcription): void
    {
        static::dispatch($transcription->id)->onQueue($transcription->priority ? 'high' : 'default');
    }

    public function handle(Transcriber $transcriber, TranscriptionFlow $flow): void
    {
        $t = Transcription::withTrashed()->find($this->transcriptionId);
        if (! $t || ! $t->isWorking()) {
            return;
        }
        $disk = Storage::disk(TranscriptionFlow::DISK);
        $folder = TranscriptionFlow::folder($t);
        $clean = $disk->path($folder.'/audio.mp3');

        try {
            if ($t->work === null) {
                $source = (string) $t->audio_path;
                if ($source === '' || ! $disk->exists($source)) {
                    $flow->fail($t, 'unreadable', __('transcribe.failed.unreadable'));

                    return;
                }
                $state = $transcriber->prepare($disk->path($source), $clean, [
                    'language' => $t->language_requested, 'dialect' => (string) $t->dialect, 'review' => TranscriptionSettings::review(),
                ]);
                if ($source !== $folder.'/audio.mp3') {
                    $disk->delete($source);
                }
                $t->forceFill(['status' => Transcription::PROCESSING, 'started_at' => $t->started_at ?? now(), 'work' => $state,
                    'parts' => count($state['plan']), 'parts_done' => 0, 'audio_path' => $folder.'/audio.mp3'])->save();
                static::queueFor($t);

                return;
            }

            $state = $t->work;
            $more = $transcriber->step($clean, $state);
            $t->forceFill(['work' => $state, 'parts_done' => (int) $state['n'], 'requests' => (int) $state['run']['requests'], 'usage' => $state['run']['usage']])->save();
            if ($more) {
                static::queueFor($t);

                return;
            }
            $flow->finish($t, Transcriber::result($state));
        } catch (SpeechNotWrittenOut $e) {
            Log::warning('Transcribe: not written out.', ['transcription' => $t->id, 'finish' => $e->finish]);
            $flow->fail($t, 'not_written', __('transcribe.failed.not_written'));
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'could not be read') || str_contains($e->getMessage(), 'ffmpeg')) {
                $flow->fail($t, 'unreadable', __('transcribe.failed.unreadable'));

                return;
            }
            throw $e;
        }
    }

    /** Every try used up: the transcript fails and its minutes are given back. */
    public function failed(?\Throwable $e): void
    {
        $t = Transcription::withTrashed()->find($this->transcriptionId);
        if ($t) {
            Log::error('Transcribe: failed.', ['transcription' => $t->id, 'error' => $e?->getMessage()]);
            app(TranscriptionFlow::class)->fail($t, 'default', __('transcribe.failed.default'));
        }
    }
}
