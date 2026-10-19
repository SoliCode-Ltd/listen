<?php

namespace App\Jobs;

use App\Exceptions\TranslationFailedException;
use App\Models\ApiKeyUsage;
use App\Models\AudioGeneration;
use App\Models\ClonedVoice;
use App\Models\Plan;
use App\Models\TtsLog;
use App\Models\TextItem;
use App\Models\UsageCounter;
use App\Services\Api\ApiUsageMeter;
use App\Services\GenerationErrorRecorder;
use App\Services\Billing\CharPackService;
use App\Services\PlanAccessService;
use App\Services\TranslationService;
use App\Services\TtsService;
use App\Services\VoiceCloningService;
use App\Support\AudioDuration;
use App\Support\AudioEffects;
use App\Support\CreditPeriod;
use App\Support\Credits;
use App\Support\DialogueSpeakers;
use App\Support\GenerationMessage;
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
        // Locked, so the stuck-generation sweep (developer API) cannot fail it while it starts.
        $generation = DB::transaction(function (): ?AudioGeneration {
            $generation = AudioGeneration::query()->whereKey($this->audioGenerationId)->lockForUpdate()->first();
            // A developer API generation that failed has had its credits given back
            // (perhaps by the sweep): it is never made again.
            if (! $generation || $generation->status === 'done' || ($generation->api_key_id && $generation->status === 'failed')) {
                return null;
            }

            // Touched even when it was left "processing" by a try that died, so the
            // sweep's clock starts again with each try.
            $generation->fill([
                'status' => 'processing',
                'error_message' => null,
            ])->touch();

            return $generation;
        });
        if (! $generation) {
            return;
        }

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
            // The real length of the saved file; the provider's figure is only a guess from the text.
            // Measured before any effect, so an echo's tail is never charged.
            $measuredSeconds = AudioDuration::wholeSeconds(AudioDuration::ofStoredFile($audio['audio_path'] ?? null));

            // A sound environment for the whole recording, added to a copy of the clean voice.
            $effect = (string) (($generation->settings_snapshot ?? [])['audio_effect'] ?? '');
            if ($effect !== '' && ! empty($audio['audio_path']) && ($effected = AudioEffects::apply((string) $audio['audio_path'], $effect)) !== null) {
                $audio['audio_path'] = $effected;
            }
            $actualSeconds = $measuredSeconds ?? (int) ($audio['duration_seconds'] ?? $actualEstimate['seconds']);
            $actualChars = $actualEstimate['chars'];
            // Members pay for the audio they got: 15 credits a second.
            $actualCredits = Credits::forSeconds($actualSeconds);

            // Done and charged together: a failure in between leaves neither, so a retry
            // never charges twice. A generation failed meanwhile (swept as stuck, its
            // credits already given back) is not delivered or charged.
            $settled = DB::transaction(function () use ($generation, $audio, $actualSeconds, $measuredSeconds, $actualChars, $actualCredits, $reserved): bool {
                $locked = AudioGeneration::query()->whereKey($generation->id)->lockForUpdate()->first();
                if (! $locked || $locked->status !== 'processing') {
                    return false;
                }

                $locked->update([
                    'audio_path' => $audio['audio_path'],
                    'duration_seconds' => $actualSeconds,
                    'duration_measured' => $measuredSeconds !== null,
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

                $this->applyUsageDelta(
                    $locked,
                    0,
                    $actualCredits - $reserved['credits'],
                    $actualSeconds - $reserved['seconds'],
                );

                return true;
            });
            if (! $settled) {
                Log::info('A generation finished after it was failed; its audio was not delivered.', ['generation_id' => $generation->id]);

                return;
            }
            $generation->refresh();

            // Write TTS log entry
            $this->writeTtsLog($generation, $audio, 'success');

            if ($generation->text_item_id) {
                TextItem::whereKey($generation->text_item_id)->update(['status' => 'done']);
            }
        } catch (\Throwable $e) {
            $sync = $this->connection === 'sync';
            // Its last try: made inline (the dashboard), called directly, or out of tries on the queue.
            $final = $sync || $this->job === null || $this->attempts() >= $this->tries;
            $message = $this->failureMessage($generation, $e);

            if ($sync && $generation->api_key_id) {
                // A developer API request waiting for the file: failed now, and its credits
                // given back here, exactly once (the request does not refund it again).
                app(ApiUsageMeter::class)->failAndRefund((int) $generation->id, $message, ['processing']);
            } else {
                // A queued job that will be tried again is not failed yet: it waits for the
                // next try. A developer API one always waits for failed(), which ends it and
                // gives its credits back in one locked step.
                $failNow = $final && ! $generation->api_key_id;
                DB::transaction(function () use ($generation, $failNow, $message): void {
                    $locked = AudioGeneration::query()->whereKey($generation->id)->lockForUpdate()->first();
                    if (! $locked || $locked->status !== 'processing') {
                        return;
                    }
                    $locked->update($failNow
                        ? ['status' => 'failed', 'error_message' => $message]
                        : ['status' => 'queued', 'error_message' => null]);
                });

                if ($failNow && $generation->text_item_id) {
                    TextItem::whereKey($generation->text_item_id)->update(['status' => 'failed']);
                }
            }
            $generation->refresh();

            // Log TTS failure telemetry
            $this->writeTtsLog($generation, [], 'failed', $e);

            // A sync run is not retried, so its first failure is the last.
            app(GenerationErrorRecorder::class)->audioGenerationFailed(
                $generation,
                $e,
                $this->attempts(),
                $sync || $this->job === null ? 1 : $this->tries,
                $message,
            );

            throw $e;
        }
    }

    /**
     * What the member is told, kept in English as the developer API shows it
     * (the studio and the extension show it in the member's language, with
     * GenerationMessage). A cloned voice has no other engine to fall back on,
     * so a used-up daily limit is said plainly.
     */
    private function failureMessage(AudioGeneration $generation, \Throwable $e): string
    {
        if ($e instanceof TranslationFailedException) {
            return $e->getMessage();
        }
        if (str_starts_with((string) $generation->voice_name, 'cloned_')
            && preg_match('/rate limit|quota|RESOURCE_EXHAUSTED|\b429\b/i', $e->getMessage())) {
            return GenerationMessage::stored('ui.backend.cloned_voice_daily_limit');
        }

        return GenerationMessage::stored('ui.backend.dashboard_voice_generation_failed_try_again');
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

        $message = $e ? $this->failureMessage($generation, $e) : GenerationMessage::stored('ui.backend.dashboard_voice_generation_failed_try_again');

        // A developer API generation is failed and refunded in one locked step, so
        // a request, this hook and the stuck-generation sweep never all refund it.
        if ($generation->api_key_id) {
            if ($this->connection !== 'sync') {
                app(ApiUsageMeter::class)->failAndRefund((int) $generation->id, $message);
            }
            if (! $e || $e instanceof MaxAttemptsExceededException || $e instanceof TimeoutExceededException) {
                app(GenerationErrorRecorder::class)->audioGenerationFailed(
                    $generation->refresh(),
                    $e ?? new \RuntimeException('The generation job failed without an error.'),
                    $this->tries,
                    $this->tries,
                    $message,
                );
            }

            return;
        }

        $generation->update([
            'status' => 'failed',
            'error_message' => $message,
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
                -$reserved['credits'],
                -$reserved['seconds'],
            );
        }

        if ($generation->text_item_id) {
            TextItem::whereKey($generation->text_item_id)->update(['status' => 'failed']);
        }
    }

    /**
     * What the controller reserved when the generation was queued: the
     * estimate from the text (the real length is only known once it is done).
     *
     * @return array{credits:int,seconds:int}
     */
    private function reservedUsage(AudioGeneration $generation): array
    {
        // The developer API stores what it reserved (it allows for a slower speed).
        $stored = ApiUsageMeter::reservationOf($generation);
        if ($stored !== null) {
            return ['credits' => $stored['credits'], 'seconds' => $stored['seconds']];
        }

        $estimate = UsageEstimator::estimate((string) $generation->input_text);

        return [
            'credits' => $estimate['credits'],
            'seconds' => $estimate['seconds'],
        ];
    }

    private function usagePeriod(AudioGeneration $generation): string
    {
        return CreditPeriod::ofGeneration($generation);
    }

    private function applyUsageDelta(AudioGeneration $generation, int $requestsDelta, int $charsDelta, int $secondsDelta): void
    {
        if ($requestsDelta === 0 && $charsDelta === 0 && $secondsDelta === 0) {
            return;
        }

        $userId = (int) $generation->user_id;
        $periodYm = $this->usagePeriod($generation);

        // A developer API generation also counts against its key's own monthly budget.
        if ($generation->api_key_id) {
            ApiKeyUsage::apply((int) $generation->api_key_id, $periodYm, $requestsDelta, $charsDelta);
        }

        // Pack characters were spent from the pack, so the correction goes there too.
        if ($generation->billed_to_pack) {
            app(CharPackService::class)->adjust($userId, $charsDelta);

            return;
        }

        DB::transaction(function () use ($userId, $periodYm, $requestsDelta, $charsDelta, $secondsDelta) {
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
                    'total_seconds' => 0,
                    'total_chars' => 0,
                    'total_requests' => 0,
                ]);
            }

            $nextSeconds = max(0, (int) $counter->total_seconds + $secondsDelta);

            $counter->update([
                'total_requests' => max(0, (int) $counter->total_requests + $requestsDelta),
                'total_chars' => max(0, (int) $counter->total_chars + $charsDelta),
                'total_seconds' => $nextSeconds,
                'total_minutes' => UsageCounter::minutesFor($nextSeconds),
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
