<?php

namespace App\Jobs;

use App\Models\AudioGeneration;
use App\Models\TextItem;
use App\Services\TranslationService;
use App\Services\TtsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateAudioJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public int $audioGenerationId)
    {
    }

    public function handle(TranslationService $translationService, TtsService $ttsService): void
    {
        $generation = AudioGeneration::find($this->audioGenerationId);
        if (! $generation || $generation->status === 'done') {
            return;
        }

        $generation->update([
            'status' => 'processing',
            'error_message' => null,
        ]);

        try {
            $textForSpeech = $translationService->maybeTranslate(
                $generation->input_text,
                $generation->source_language ?? 'en',
                $generation->target_language,
                (bool) $generation->translate,
            );

            $audio = $ttsService->synthesize(
                $textForSpeech,
                $generation->target_language,
                $generation->voice_name,
            );

            $duration = $audio['duration_seconds'] ?? $generation->duration_seconds;

            $generation->update([
                'audio_path' => $audio['audio_path'],
                'duration_seconds' => $duration,
                'status' => 'done',
                'error_message' => null,
            ]);

            if ($generation->text_item_id) {
                TextItem::whereKey($generation->text_item_id)->update(['status' => 'done']);
            }
        } catch (\Throwable $e) {
            $generation->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            if ($generation->text_item_id) {
                TextItem::whereKey($generation->text_item_id)->update(['status' => 'failed']);
            }

            throw $e;
        }
    }
}
