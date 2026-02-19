<?php

namespace App\Jobs;

use App\Models\AudioGeneration;
use App\Models\TextItem;
use App\Models\UsageCounter;
use App\Services\TranslationService;
use App\Services\TtsService;
use App\Support\UsageEstimator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

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
            $reserved = $this->reservedUsage($generation);

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

            $actualEstimate = UsageEstimator::estimate($textForSpeech);
            $actualSeconds = (int) ($audio['duration_seconds'] ?? $actualEstimate['seconds']);
            $actualMinutes = max(1, (int) ceil($actualSeconds / 60));
            $actualChars = $actualEstimate['chars'];

            $generation->update([
                'audio_path' => $audio['audio_path'],
                'duration_seconds' => $actualSeconds,
                'status' => 'done',
                'error_message' => null,
            ]);

            $this->applyUsageDelta(
                (int) $generation->user_id,
                $this->usagePeriod($generation),
                $actualChars - $reserved['chars'],
                $actualMinutes - $reserved['minutes'],
            );

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

    public function failed(?\Throwable $e): void
    {
        $generation = AudioGeneration::find($this->audioGenerationId);
        if (! $generation || $generation->status === 'done') {
            return;
        }

        $reserved = $this->reservedUsage($generation);

        $this->applyUsageDelta(
            (int) $generation->user_id,
            $this->usagePeriod($generation),
            -$reserved['chars'],
            -$reserved['minutes'],
        );

        if ($generation->text_item_id) {
            TextItem::whereKey($generation->text_item_id)->update(['status' => 'failed']);
        }
    }

    /**
     * @return array{chars:int,minutes:int}
     */
    private function reservedUsage(AudioGeneration $generation): array
    {
        $estimate = UsageEstimator::estimate((string) $generation->input_text);

        $seconds = (int) ($generation->duration_seconds ?? $estimate['seconds']);

        return [
            'chars' => $estimate['chars'],
            'minutes' => max(1, (int) ceil($seconds / 60)),
        ];
    }

    private function usagePeriod(AudioGeneration $generation): string
    {
        return $generation->created_at?->format('Y-m') ?? now()->format('Y-m');
    }

    private function applyUsageDelta(int $userId, string $periodYm, int $charsDelta, int $minutesDelta): void
    {
        if ($charsDelta === 0 && $minutesDelta === 0) {
            return;
        }

        DB::transaction(function () use ($userId, $periodYm, $charsDelta, $minutesDelta) {
            $counter = UsageCounter::query()
                ->where('user_id', $userId)
                ->where('period_ym', $periodYm)
                ->lockForUpdate()
                ->first();

            if (! $counter) {
                $counter = UsageCounter::create([
                    'user_id' => $userId,
                    'period_ym' => $periodYm,
                    'total_minutes' => 0,
                    'total_chars' => 0,
                    'total_requests' => 0,
                ]);
            }

            $nextChars = max(0, (int) $counter->total_chars + $charsDelta);
            $nextMinutes = max(0, (int) $counter->total_minutes + $minutesDelta);

            $counter->update([
                'total_chars' => $nextChars,
                'total_minutes' => $nextMinutes,
            ]);
        });
    }
}
