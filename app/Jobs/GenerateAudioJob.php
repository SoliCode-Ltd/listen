<?php

namespace App\Jobs;

use App\Models\AudioGeneration;
use App\Models\ClonedVoice;
use App\Models\Plan;
use App\Models\TtsLog;
use App\Models\TextItem;
use App\Models\UsageCounter;
use App\Services\GenerationErrorRecorder;
use App\Services\Billing\CharPackService;
use App\Services\PlanAccessService;
use App\Services\TranslationService;
use App\Services\TtsService;
use App\Services\VoiceCloningService;
use App\Support\DialogueSpeakers;
use App\Support\UsageEstimator;
use App\Support\VoiceCloningAccess;
use App\Support\VoiceCatalog;
use App\Support\VoiceTier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateAudioJob implements ShouldQueue
{
    use Queueable;

    /** @var array<int, string> */
    private const PERFORMANCE_STYLE_VALUES = [
        'neutral',
        'educational',
        'commercial',
        'promo',
        'podcast',
        'assistant',
        'narrative',
        'comedic',
        'corporate',
        'conversational',
        'inspirational',
        'cinematic',
        'documentary',
        'storytelling',
        'ad_style',
        'news',
        'sports_commentator',
        'meditation',
        'audiobook',
        'children_story',
        'radio_host',
    ];

    /** @var array<int, string> */
    private const EMOTION_VALUES = [
        'neutral',
        'warm',
        'sad',
        'excited',
        'formal',
        'calm',
        'angry',
        'hopeful',
        'serious',
        'playful',
        'mysterious',
        'confident',
    ];

    public int $tries = 3;
    public int $timeout = 150;

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

            // Use advanced synthesis with plan-aware routing
            $planAccessService = app(PlanAccessService::class);
            $plan = $generation->user_id ? $planAccessService->resolveAccessPlanForUserId((int) $generation->user_id) : null;

            $requestedTier = VoiceTier::normalize((string) ($generation->voice_tier ?: VoiceTier::STANDARD));
            $studioOptions = $this->sanitizeStudioOptionsForTier($requestedTier, [
                'dialect' => $generation->dialect,
                'performance_style' => $generation->performance_style,
                'style_prompt' => $generation->style_prompt,
                'emotion' => $generation->emotion ?? 'neutral',
                'speed' => $generation->speed ?? 1.0,
                'pitch' => $generation->pitch ?? 0.0,
            ], $plan);
            // === Cloned voice detection ===
            // If voice_name is "cloned_{id}", use VoiceCloningService for synthesis
            // instead of the normal TTS routing pipeline.
            $isClonedVoice = str_starts_with((string) $generation->voice_name, 'cloned_');
            $audio = null;

            if ($isClonedVoice) {
                $clonedVoiceId = (int) str_replace('cloned_', '', $generation->voice_name);
                $clonedVoice = ClonedVoice::where('id', $clonedVoiceId)
                    ->where('user_id', $generation->user_id)
                    ->ready()
                    ->first();

                if (! $clonedVoice || ! VoiceCloningAccess::allows($clonedVoice->user, $plan)) {
                    throw new \RuntimeException('Cloned voice not found or not ready.');
                }

                $voiceCloningService = app(VoiceCloningService::class);
                $textForSpeech = $voiceCloningService->speakableText(
                    $textForSpeech,
                    $studioOptions['dialect'] ?? null,
                );
                // Any of the site's keys with room today (each keeps its own copy of the voice).
                $audio = $voiceCloningService->synthesizeForVoice(
                    $clonedVoice,
                    $textForSpeech,
                    $voiceCloningService->deliveryStyle(
                        $studioOptions['dialect'] ?? null,
                        (string) ($studioOptions['performance_style'] ?? 'neutral'),
                        (string) ($studioOptions['emotion'] ?? 'neutral'),
                        $studioOptions['style_prompt'] ?? null,
                        (float) ($studioOptions['speed'] ?? 1),
                        (float) ($studioOptions['pitch'] ?? 0),
                    ),
                );
            } else {
                $voiceMeta = VoiceCatalog::voiceMeta($generation->voice_name);

                $voiceOptions = [
                    'voice_name' => $generation->voice_name,
                    'provider' => $generation->tts_provider ?: VoiceCatalog::providerForName($generation->voice_name),
                    'tier' => $requestedTier,
                    'gender' => $voiceMeta['gender'] ?? null,
                    'performance_style' => $studioOptions['performance_style'],
                    'style' => $studioOptions['performance_style'],
                    'style_prompt' => $studioOptions['style_prompt'],
                    'dialect' => $studioOptions['dialect'],
                    'emotion' => $studioOptions['emotion'],
                    'speed' => $studioOptions['speed'],
                    'pitch' => $studioOptions['pitch'],
                ];

                // Add dialogue speakers if applicable
                if (
                    $requestedTier !== VoiceTier::STANDARD
                    && $generation->is_dialogue
                    && is_array($generation->dialogue_metadata)
                ) {
                    $provider = is_string($voiceOptions['provider']) ? $voiceOptions['provider'] : null;
                    $voiceOptions['speakers'] = DialogueSpeakers::forSpeech(
                        $generation->dialogue_metadata['speakers'] ?? [],
                        $generation->target_language,
                        $requestedTier,
                        $provider,
                    );
                }

                $audio = $ttsService->synthesizeAdvanced(
                    $textForSpeech,
                    $generation->target_language,
                    $voiceOptions,
                    $plan,
                    (int) $generation->user_id,
                );
            }

            $actualEstimate = UsageEstimator::estimate($textForSpeech);
            $actualSeconds = (int) ($audio['duration_seconds'] ?? $actualEstimate['seconds']);
            $actualMinutes = max(1, (int) ceil($actualSeconds / 60));
            $actualChars = $actualEstimate['chars'];

            $generation->update([
                'audio_path' => $audio['audio_path'],
                'duration_seconds' => $actualSeconds,
                'status' => 'done',
                'error_message' => null,
                // TTS telemetry
                'tts_provider' => $audio['provider'] ?? null,
                'tts_model' => $audio['model'] ?? null,
                'tts_latency_ms' => $audio['latency_ms'] ?? null,
                'tts_fallback_used' => $audio['fallback_used'] ?? false,
                'tts_cost_estimate_usd' => $audio['cost_estimate_usd'] ?? null,
                'billable_chars' => $actualChars,
            ]);

            // Write TTS log entry
            $this->writeTtsLog($generation, $audio, 'success');

            $this->applyUsageDelta(
                $generation,
                0,
                $actualChars - $reserved['chars'],
                $actualMinutes - $reserved['minutes'],
            );

            if ($generation->text_item_id) {
                TextItem::whereKey($generation->text_item_id)->update(['status' => 'done']);
            }
        } catch (\Throwable $e) {
            $generation->update([
                'status' => 'failed',
                'error_message' => $this->failureMessage($generation, $e),
            ]);

            // Log TTS failure telemetry
            $this->writeTtsLog($generation, [], 'failed', $e);

            // A sync run is not retried, so its first failure is the last.
            app(GenerationErrorRecorder::class)->audioGenerationFailed(
                $generation,
                $e,
                $this->attempts(),
                $this->connection === 'sync' ? 1 : $this->tries,
                $generation->error_message,
            );

            if ($generation->text_item_id) {
                TextItem::whereKey($generation->text_item_id)->update(['status' => 'failed']);
            }

            throw $e;
        }
    }

    /**
     * What the member is told. A cloned voice has no other engine to fall
     * back on, so a used-up daily limit is said plainly.
     */
    private function failureMessage(AudioGeneration $generation, \Throwable $e): string
    {
        if (str_starts_with((string) $generation->voice_name, 'cloned_')
            && preg_match('/rate limit|quota|RESOURCE_EXHAUSTED|\b429\b/i', $e->getMessage())) {
            return __('ui.backend.cloned_voice_daily_limit');
        }

        return 'Voice generation failed. Please try again.';
    }

    /**
     * Write a TTS telemetry log entry.
     */
    private function writeTtsLog(AudioGeneration $generation, array $result, string $status, ?\Throwable $error = null): void
    {
        try {
            // Determine failure reason: explicit fallback_reason, or error message
            $failureReason = $result['fallback_reason'] ?? null;
            if ($error) {
                $failureReason = mb_substr($error->getMessage(), 0, 500);
            }

            TtsLog::create([
                'user_id' => $generation->user_id,
                'audio_generation_id' => $generation->id,
                'provider' => $result['provider'] ?? 'unknown',
                'model' => $result['model'] ?? null,
                'routing_mode' => $result['routing_mode'] ?? config('listen.tts.mode', 'legacy'),
                'plan_slug' => $result['plan_slug'] ?? null,
                'target_language' => $generation->target_language,
                'char_count' => $result['char_count'] ?? $generation->billable_chars ?? 0,
                'latency_ms' => $result['latency_ms'] ?? null,
                'cost_estimate_usd' => $result['cost_estimate_usd'] ?? null,
                'fallback_used' => $result['fallback_used'] ?? false,
                'failure_reason' => $failureReason,
                'status' => $status,
            ]);
        } catch (\Throwable $logError) {
            Log::warning('Failed to write TTS log entry', [
                'generation_id' => $generation->id,
                'error' => $logError->getMessage(),
            ]);
        }
    }

    public function failed(?\Throwable $e): void
    {
        $generation = AudioGeneration::find($this->audioGenerationId);
        if (! $generation || $generation->status === 'done') {
            return;
        }

        $generation->update([
            'status' => 'failed',
            'error_message' => $e ? $this->failureMessage($generation, $e) : 'Voice generation failed. Please try again.',
        ]);

        // Failures thrown from handle() are recorded there; a timeout or a
        // worker killed mid-run only ever reaches this point.
        if (! $e || $e instanceof MaxAttemptsExceededException || $e instanceof TimeoutExceededException) {
            app(GenerationErrorRecorder::class)->audioGenerationFailed(
                $generation,
                $e ?? new \RuntimeException('The generation job failed without an error.'),
                $this->tries,
                $this->tries,
                $generation->error_message,
            );
        }

        // Only release usage for async (queued) jobs.
        // For sync dispatches, the controller handles the refund
        // after checking job status — avoiding double-refund.
        if ($this->connection !== 'sync') {
            $reserved = $this->reservedUsage($generation);

            $this->applyUsageDelta(
                $generation,
                -1,
                -$reserved['chars'],
                -$reserved['minutes'],
            );
        }

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

    private function applyUsageDelta(AudioGeneration $generation, int $requestsDelta, int $charsDelta, int $minutesDelta): void
    {
        if ($requestsDelta === 0 && $charsDelta === 0 && $minutesDelta === 0) {
            return;
        }

        $userId = (int) $generation->user_id;
        $periodYm = $this->usagePeriod($generation);

        // Pack characters were spent from the pack, so the correction goes there too.
        if ($generation->billed_to_pack) {
            app(CharPackService::class)->adjust($userId, $charsDelta);

            return;
        }

        DB::transaction(function () use ($userId, $periodYm, $requestsDelta, $charsDelta, $minutesDelta) {
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

            $nextRequests = max(0, (int) $counter->total_requests + $requestsDelta);
            $nextChars = max(0, (int) $counter->total_chars + $charsDelta);
            $nextMinutes = max(0, (int) $counter->total_minutes + $minutesDelta);

            $counter->update([
                'total_requests' => $nextRequests,
                'total_chars' => $nextChars,
                'total_minutes' => $nextMinutes,
            ]);
        });
    }

    /**
     * @param  array{dialect:mixed,performance_style:mixed,style_prompt:mixed,emotion:mixed,speed:mixed,pitch:mixed}  $options
     * @return array{dialect:?string,performance_style:string,style_prompt:?string,emotion:string,speed:float,pitch:float}
     */
    private function sanitizeStudioOptionsForTier(string $tier, array $options, ?Plan $plan = null): array
    {
        $normalizedTier = VoiceTier::normalize($tier);

        if ($normalizedTier === VoiceTier::STANDARD) {
            return [
                'dialect' => null,
                'performance_style' => 'neutral',
                'style_prompt' => null,
                'emotion' => 'neutral',
                'speed' => 1.0,
                'pitch' => 0.0,
            ];
        }

        $performanceStyle = isset($options['performance_style']) && is_string($options['performance_style'])
            ? trim($options['performance_style'])
            : '';
        $emotion = isset($options['emotion']) && is_string($options['emotion'])
            ? trim($options['emotion'])
            : '';

        // Backward compatibility for old records that stored style in emotion.
        if ($performanceStyle === '' && in_array($emotion, self::PERFORMANCE_STYLE_VALUES, true) && ! in_array($emotion, self::EMOTION_VALUES, true)) {
            $performanceStyle = $emotion;
            $emotion = 'neutral';
        }

        // Style prompt is only allowed if the plan permits it (custom_prompt_enabled)
        $stylePrompt = null;
        if ($plan === null || (bool) ($plan->custom_prompt_enabled ?? false)) {
            $stylePrompt = isset($options['style_prompt']) && is_string($options['style_prompt'])
                ? trim($options['style_prompt']) ?: null
                : null;
        }

        return [
            'dialect' => isset($options['dialect']) && is_string($options['dialect']) ? trim($options['dialect']) ?: null : null,
            'performance_style' => $performanceStyle !== '' ? $performanceStyle : 'neutral',
            'style_prompt' => $stylePrompt,
            'emotion' => $emotion !== '' ? $emotion : 'neutral',
            'speed' => isset($options['speed']) ? (float) $options['speed'] : 1.0,
            'pitch' => isset($options['pitch']) ? (float) $options['pitch'] : 0.0,
        ];
    }
}
