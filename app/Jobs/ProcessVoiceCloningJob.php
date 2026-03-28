<?php

namespace App\Jobs;

use App\Models\ClonedVoice;
use App\Services\VoiceCloningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Processes a voice cloning request asynchronously.
 *
 * Sends the reference audio and consent audio to Google Cloud TTS v1beta1
 * generateVoiceCloningKey endpoint, then stores the returned voice_cloning_key.
 *
 * Status transitions:
 *   pending → validating → processing → ready
 *                                     → failed
 */
class ProcessVoiceCloningJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(
        public int $clonedVoiceId,
    ) {}

    public function handle(VoiceCloningService $service): void
    {
        $clonedVoice = ClonedVoice::find($this->clonedVoiceId);

        if (! $clonedVoice || $clonedVoice->status === 'ready') {
            return;
        }

        // Transition: validating
        $clonedVoice->update([
            'status' => 'validating',
            'failure_reason' => null,
        ]);

        // Basic validation checks
        if (! $this->validateAudioFile($clonedVoice)) {
            return;
        }

        // Transition: processing
        $clonedVoice->update(['status' => 'processing']);

        // Call Google Cloud TTS Chirp 3 API
        $result = $service->generateVoiceCloningKey($clonedVoice);

        if ($result['success'] && filled($result['voice_cloning_key'] ?? null)) {
            $clonedVoice->update([
                'status' => 'ready',
                'provider_voice_key' => $result['voice_cloning_key'],
                'failure_reason' => null,
                'metadata' => array_merge($clonedVoice->metadata ?? [], [
                    'processed_at' => now()->toIso8601String(),
                    'provider_region' => \App\Models\SiteSetting::get('voice_cloning_region', 'global'),
                ]),
            ]);

            Log::info('Voice cloning succeeded.', [
                'cloned_voice_id' => $clonedVoice->id,
                'user_id' => $clonedVoice->user_id,
            ]);
        } else {
            $this->markFailed($clonedVoice, $result['error'] ?? 'Unknown error from Google API.');
        }
    }

    public function failed(?\Throwable $e): void
    {
        $clonedVoice = ClonedVoice::find($this->clonedVoiceId);

        if ($clonedVoice && $clonedVoice->status !== 'ready') {
            $this->markFailed(
                $clonedVoice,
                $e ? $e->getMessage() : 'Job failed after max retries.',
            );
        }
    }

    private function validateAudioFile(ClonedVoice $clonedVoice): bool
    {
        $minDuration = VoiceCloningService::minSampleDuration();

        if ($clonedVoice->source_audio_duration_seconds < $minDuration) {
            $this->markFailed($clonedVoice, "Audio sample too short ({$clonedVoice->source_audio_duration_seconds}s). Minimum: {$minDuration}s.");
            return false;
        }

        if (! $clonedVoice->consent_confirmed) {
            $this->markFailed($clonedVoice, 'Voice ownership consent not confirmed.');
            return false;
        }

        return true;
    }

    private function markFailed(ClonedVoice $clonedVoice, string $reason): void
    {
        $clonedVoice->update([
            'status' => 'failed',
            'failure_reason' => mb_substr($reason, 0, 1000),
        ]);

        Log::warning('Voice cloning failed.', [
            'cloned_voice_id' => $clonedVoice->id,
            'user_id' => $clonedVoice->user_id,
            'reason' => $reason,
        ]);
    }
}
