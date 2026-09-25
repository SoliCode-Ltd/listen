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
 * Sends the reference audio and a separate consent recording to Gemini 3.8
 * Flash voice replication, then stores the returned voice id.
 *
 * Status transitions:
 *   pending → validating → processing → ready
 *                                     → failed
 */
class ProcessVoiceCloningJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 180;

    public function __construct(
        public int $clonedVoiceId,
    ) {}

    public function handle(VoiceCloningService $service): void
    {
        ignore_user_abort(true);
        set_time_limit($this->timeout);

        $clonedVoice = ClonedVoice::find($this->clonedVoiceId);

        if (! $clonedVoice || $clonedVoice->status === 'ready' || $clonedVoice->status === 'failed') {
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

        $result = $service->generateVoiceCloningKey($clonedVoice);

        if ($result['success'] && filled($result['voice_cloning_key'] ?? null)) {
            $clonedVoice->update([
                'status' => 'ready',
                'provider' => 'gemini',
                'provider_voice_key' => $result['voice_cloning_key'],
                'failure_reason' => null,
                'metadata' => array_merge($clonedVoice->metadata ?? [], [
                    'processed_at' => now()->toIso8601String(),
                    'model' => VoiceCloningService::MODEL,
                    'failure_code' => null,
                ]),
            ]);

            Log::info('Voice cloning succeeded.', [
                'cloned_voice_id' => $clonedVoice->id,
                'user_id' => $clonedVoice->user_id,
            ]);
        } else {
            $this->markFailed(
                $clonedVoice,
                $result['error'] ?? 'Unknown error from Gemini.',
                $result['error_code'] ?? 'provider',
                $result['http_status'] ?? null,
                (string) ($result['response_body'] ?? ''),
            );
        }
    }

    public function failed(?\Throwable $e): void
    {
        $clonedVoice = ClonedVoice::find($this->clonedVoiceId);

        if ($clonedVoice && $clonedVoice->status !== 'ready') {
            $this->markFailed(
                $clonedVoice,
                $e ? $e->getMessage() : 'Job failed after max retries.',
                'provider',
            );
        }
    }

    private function validateAudioFile(ClonedVoice $clonedVoice): bool
    {
        $minDuration = VoiceCloningService::minSampleDuration();
        $maxDuration = VoiceCloningService::maxSampleDuration();
        $duration = (int) $clonedVoice->source_audio_duration_seconds;

        if ($duration < $minDuration || $duration > $maxDuration) {
            $this->markFailed(
                $clonedVoice,
                "Audio sample must be between {$minDuration} and {$maxDuration} seconds.",
                'duration',
            );
            return false;
        }

        if (! $clonedVoice->consent_confirmed || ! filled($clonedVoice->consent_audio_path)) {
            $this->markFailed($clonedVoice, 'A separate spoken consent recording is required.', 'consent');
            return false;
        }

        return true;
    }

    private function markFailed(
        ClonedVoice $clonedVoice,
        string $reason,
        string $code = 'provider',
        ?int $httpStatus = null,
        string $responseBody = '',
    ): void {
        $clonedVoice->update([
            'status' => 'failed',
            'failure_reason' => mb_substr($reason, 0, 1000),
            'metadata' => array_merge($clonedVoice->metadata ?? [], [
                'failure_code' => $code,
                'provider_error' => mb_substr($reason, 0, 500),
                'http_status' => $httpStatus,
                'provider_response' => mb_substr($responseBody, 0, 2000),
                'failed_at' => now()->toIso8601String(),
            ]),
        ]);

        Log::warning('Voice cloning failed.', [
            'cloned_voice_id' => $clonedVoice->id,
            'user_id' => $clonedVoice->user_id,
            'code' => $code,
            'http_status' => $httpStatus,
            'reason' => $reason,
        ]);
    }
}
