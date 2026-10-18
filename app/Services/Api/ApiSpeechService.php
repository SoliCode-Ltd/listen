<?php

namespace App\Services\Api;

use App\Jobs\GenerateAudioJob;
use App\Models\ApiKey;
use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\User;
use App\Services\AudioGenerationRecorder;
use App\Services\BlockedGenerationLogger;
use App\Services\PlanAccessService;
use App\Services\ProfanityFilter;
use App\Support\Api\ApiError;
use App\Support\Api\ApiFeatureGate;
use App\Support\Api\ApiModels;
use App\Support\Api\ApiPlanAccess;
use App\Support\Api\ApiVoiceCatalog;
use App\Support\AudioEffects;
use App\Support\AudioFormats;
use App\Support\Credits;
use App\Support\DialogueSpeakers;
use App\Support\GeminiVocalTags;
use App\Support\PlanFeatures;
use App\Support\PlanUsageGuard;
use App\Support\SpeechLanguages;
use App\Support\TtsStudioConfig;
use App\Support\UsageEstimator;
use App\Support\VoiceCatalog;
use App\Support\VoiceCloningAccess;
use App\Support\VoiceTier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Text to speech for the developer API. Validates a request against the
 * member's plan, then runs it through the dashboard's pipeline: the same
 * voices, studio defaults, profanity check, metering (ApiUsageMeter) and
 * GenerateAudioJob, so an API generation costs and sounds exactly like one
 * made in the dashboard.
 */
class ApiSpeechService
{
    /** Longest text the API accepts at all (as the dashboard and extension). */
    public const MAX_TEXT = 50000;

    /**
     * Longest text for a request that waits for the file: it is made while the
     * connection stays open, so it must finish well inside the server's time
     * and memory limits.
     */
    public const SYNC_MAX_TEXT = 5000;

    /** Longest text for a background request (/async): what one generation finishes inside the job's time limit. */
    public const ASYNC_MAX_TEXT = 10000;

    /** Longest dialogue: a dialogue is made in one piece, not split like a single voice. */
    public const DIALOGUE_MAX_TEXT = 2500;

    /** File types the API can return. */
    public const OUTPUT_FORMATS = ['mp3', 'wav', 'm4a', 'ogg', 'flac'];

    public function __construct(
        private readonly AudioGenerationRecorder $audioGenerationRecorder,
        private readonly ApiUsageMeter $meter,
        private readonly ProfanityFilter $profanityFilter,
        private readonly BlockedGenerationLogger $blockedGenerationLogger,
    ) {}

    /**
     * Checks the request and works out everything the generation needs.
     * Every studio option of the dashboard is available, and the ones only
     * some plans include are refused with the plans that include them.
     *
     * @return array<string, mixed>
     */
    public function prepare(Request $request, User $user, ?Plan $plan, ?Plan $quotaPlan, string $voiceId, bool $async = false): array
    {
        if ($request->isJson() && trim((string) $request->getContent()) !== '' && ! is_array(json_decode((string) $request->getContent(), true))) {
            throw ApiError::invalid('invalid_json', 'The request body is not valid JSON.', null, 400);
        }

        $validator = Validator::make($request->all(), [
            'text' => ['required', 'string', 'max:'.self::MAX_TEXT],
            'model_id' => ['nullable', 'string', 'in:'.implode(',', ApiModels::ids())],
            'language_code' => ['nullable', 'string', 'in:'.implode(',', SpeechLanguages::codes())],
            'dialect' => ['nullable', 'string', 'max:20'],
            'style' => ['nullable', 'string', 'max:40'],
            'emotion' => ['nullable', 'string', 'max:40'],
            'style_prompt' => ['nullable', 'string', 'max:2000'],
            'voice_settings' => ['nullable', 'array'],
            'voice_settings.speed' => ['nullable', 'numeric', 'min:0.5', 'max:2'],
            'voice_settings.pitch' => ['nullable', 'numeric', 'min:-10', 'max:10'],
            'audio_effect' => ['nullable', 'string', 'max:40'],
            'dialogue' => ['nullable', 'array'],
            'dialogue.speakers' => ['required_with:dialogue', 'array', 'min:2', 'max:6'],
            'dialogue.speakers.*.name' => ['required', 'string', 'max:50'],
            'dialogue.speakers.*.voice_id' => ['required', 'string', 'max:40'],
            'dialogue.speakers.*.dialect' => ['nullable', 'string', 'max:20'],
            'translate' => ['nullable', 'boolean'],
            'title' => ['nullable', 'string', 'max:255'],
            'output_format' => ['nullable', 'string', 'in:'.implode(',', self::OUTPUT_FORMATS)],
        ], [
            'text.required' => 'The text field is required.',
            'model_id.in' => 'model_id must be '.ApiModels::PREMIUM.'.',
            'language_code.in' => 'language_code must be one of: '.implode(', ', SpeechLanguages::codes()).'.',
            'output_format.in' => 'output_format must be one of: '.implode(', ', self::OUTPUT_FORMATS).'.',
            'dialogue.speakers.required_with' => 'dialogue.speakers needs from 2 to 6 speakers.',
            'dialogue.speakers.min' => 'dialogue.speakers needs from 2 to 6 speakers.',
            'dialogue.speakers.max' => 'dialogue.speakers needs from 2 to 6 speakers.',
        ]);
        $validated = $validator->validate();

        $planTier = app(PlanAccessService::class)->resolveTierForPlan($plan);
        if (str_starts_with($voiceId, 'cloned_') && ! VoiceCloningAccess::allows($user, $plan)) {
            throw ApiFeatureGate::refusal(ApiFeatureGate::CLONED_VOICES, 'voice_id');
        }
        $voice = ApiVoiceCatalog::find($user, $plan, $planTier, $voiceId, false);
        if ($voice === null) {
            throw ApiError::notFound('voice', 'voice_id');
        }
        $tier = VoiceTier::HD;

        $text = trim((string) $validated['text']);
        if (! GeminiVocalTags::hasWords($text)) {
            throw ApiError::invalid('text_has_no_words', 'The text has nothing to read aloud. Tags and sounds need words with them.', 'text');
        }

        $language = $this->language($validated, $voice, $text);
        $studio = $this->studioOptions($validated, $language, $plan);
        $audioEffect = $this->audioEffect($validated, $user, $plan);
        $dialogue = $this->dialogue($validated, $user, $plan, $planTier, $language, $text);

        $estimate = self::estimate($text, (float) $studio['speed']);
        $this->assertLength($estimate['chars'], $quotaPlan, $async, $dialogue !== null);
        ApiCapacity::assertRoom($estimate['chars']);

        $format = strtolower((string) ($validated['output_format'] ?? $request->query('output_format', 'mp3')));
        $this->assertOutputFormat($format, $plan);

        return [
            'voice' => $voice,
            'voice_name' => (string) $voice['_internal_name'],
            'tier' => $tier,
            'model_id' => ApiModels::PREMIUM,
            'language' => $language,
            'source_language' => SpeechLanguages::detect($text),
            'text' => $text,
            'title' => $validated['title'] ?? null,
            'translate' => (bool) ($validated['translate'] ?? false),
            'studio' => $studio,
            'audio_effect' => $audioEffect,
            'dialogue_speakers' => $dialogue,
            'estimate' => $estimate,
            'output_format' => $format,
        ];
    }

    /**
     * What the request reserves: the dashboard's estimate from the text, made
     * longer for a slower speed (speed 0.5 makes audio about twice as long), so
     * the plan's limit holds for the audio the request really makes.
     *
     * @return array{chars:int,words:int,seconds:int,minutes:int,credits:int}
     */
    public static function estimate(string $text, float $speed = 1.0): array
    {
        $estimate = UsageEstimator::estimate($text);
        if ($speed > 0 && $speed < 1) {
            $estimate['seconds'] = (int) ceil($estimate['seconds'] / $speed);
            $estimate['minutes'] = max(1, (int) ceil($estimate['seconds'] / 60));
            $estimate['credits'] = Credits::forSeconds($estimate['seconds']);
        }

        return $estimate;
    }

    /**
     * Most characters one request may carry on a plan: the plan's own limit,
     * and never more than the API can reliably make in one request.
     */
    public static function maxCharsFor(?Plan $quotaPlan, bool $async = false, bool $dialogue = false): int
    {
        $max = min(PlanUsageGuard::maxCharsPerRequest($quotaPlan), $async ? self::ASYNC_MAX_TEXT : self::SYNC_MAX_TEXT);

        return $dialogue ? min($max, self::DIALOGUE_MAX_TEXT) : $max;
    }

    private function assertLength(int $chars, ?Plan $quotaPlan, bool $async, bool $dialogue): void
    {
        $max = self::maxCharsFor($quotaPlan, $async, $dialogue);
        if ($chars <= $max) {
            return;
        }

        $extra = ['max_characters' => $max];
        if ($dialogue && $max === self::DIALOGUE_MAX_TEXT) {
            $message = "The dialogue is {$chars} characters; one dialogue request can carry up to {$max}. Split it into shorter requests.";
        } elseif (! $async && $chars <= self::maxCharsFor($quotaPlan, true, $dialogue)) {
            $asyncMax = self::maxCharsFor($quotaPlan, true, $dialogue);
            $extra['max_characters_async'] = $asyncMax;
            $message = "The text is {$chars} characters; a request that waits for the file can carry up to {$max}. Send it to POST /v1/text-to-speech/{voice_id}/async (up to {$asyncMax} characters), or split it into shorter requests.";
        } else {
            $message = "The text is {$chars} characters; one request can carry up to {$max} on your plan. Split it into shorter requests.";
        }

        throw new ApiError(422, ApiError::TYPE_INVALID_REQUEST, 'text_too_long', $message, 'text', [], $extra);
    }

    /**
     * Reserves the credits, records the generation and runs it: now, or on
     * the queue when $async. A generation that fails gives the credits back,
     * exactly once (ApiUsageMeter::failAndRefund). $onRecorded runs inside the
     * reservation, as soon as the generation exists (the Idempotency-Key is
     * tied to it there).
     *
     * @param  array<string, mixed>  $spec  from prepare()
     * @param  (callable(AudioGeneration): void)|null  $onRecorded
     */
    public function generate(User $user, ApiKey $apiKey, ?Plan $plan, ?Plan $quotaPlan, array $spec, bool $async, ?callable $onRecorded = null): AudioGeneration
    {
        $userId = (int) $user->id;
        $estimate = $spec['estimate'];
        $status = $async ? 'queued' : 'processing';
        $studio = $spec['studio'];

        // Checked before anything is reserved, so a refused text costs nothing.
        $match = $this->profanityFilter->firstMatch($spec['text']);
        if ($match !== null) {
            $this->blockedGenerationLogger->logFromValidation($spec['text'], $match, 'text');

            throw new ApiError(422, ApiError::TYPE_INVALID_REQUEST, 'content_policy_violation',
                'The text contains words that are not allowed. Change the text and try again.', 'text');
        }

        [, $recorded] = $this->meter->reserve($userId, $apiKey, $quotaPlan, $estimate, function (array $reservation) use ($userId, $apiKey, $plan, $async, $spec, $estimate, $status, $studio, $onRecorded): array {
            $this->assertQueueRoom($userId, $plan, $async);

            $recorded = $this->audioGenerationRecorder->createForUser(
                $userId,
                [
                    'source_type' => 'api',
                    'title' => $spec['title'],
                    'content' => $spec['text'],
                    'language' => $spec['source_language'],
                    'status' => $status,
                    'received_at' => now(),
                ],
                [
                    'input_text' => $spec['text'],
                    'source_language' => $spec['source_language'],
                    'target_language' => $spec['language'],
                    'voice_tier' => $spec['tier'],
                    'voice_name' => $spec['voice_name'],
                    'tts_provider' => str_starts_with($spec['voice_name'], 'cloned_') ? 'gemini' : VoiceCatalog::providerForName($spec['voice_name']),
                    'translate' => $spec['translate'],
                    'duration_seconds' => $estimate['seconds'],
                    'status' => $status,
                    'dialect' => $studio['dialect'],
                    'performance_style' => $studio['performance_style'],
                    'style_prompt' => $studio['style_prompt'],
                    'emotion' => $studio['emotion'],
                    'speed' => $studio['speed'],
                    'pitch' => $studio['pitch'],
                    'audio_effect' => $spec['audio_effect'],
                    'is_dialogue' => $spec['dialogue_speakers'] !== null,
                    'dialogue_metadata' => $spec['dialogue_speakers'] !== null ? ['speakers' => $spec['dialogue_speakers']] : null,
                    'billed_to_pack' => $reservation['billed_to_pack'],
                    'api_key_id' => (int) $apiKey->id,
                ],
            );

            // What was reserved, kept on the generation: the job corrects and refunds exactly this.
            $job = $recorded['job'];
            $job->forceFill(['settings_snapshot' => array_merge((array) ($job->settings_snapshot ?? []), ['reservation' => $reservation])])->save();
            if ($onRecorded !== null) {
                $onRecorded($job);
            }

            return $recorded;
        });

        $job = $recorded['job'];
        ApiCapacity::record((int) $spec['estimate']['chars']);

        if ($async) {
            // The same queues as the browser extension's background generations.
            $queue = strtolower((string) ($plan?->processing_priority ?? 'standard')) === 'high' ? 'high' : 'default';
            try {
                GenerateAudioJob::dispatch($job->id)->onQueue($queue);
            } catch (\Throwable $e) {
                // With a synchronous queue the job ran here, and its failed() hook gave the
                // credits back. Otherwise it never reached the queue: give them back now.
                if ($job->refresh()->status === 'queued') {
                    report($e);
                    $this->meter->failAndRefund((int) $job->id, 'Voice generation could not be started. Please try again.', ['queued']);

                    throw new ApiError(503, ApiError::TYPE_API, 'queue_unavailable',
                        'Background generation is temporarily unavailable. No credits were charged; please try again shortly.',
                        null, ['Retry-After' => '30']);
                }
            }

            return $job->refresh();
        }

        try {
            GenerateAudioJob::dispatchSync($job->id);
        } catch (\Throwable) {
            // The status says what happened; a failed run has given the credits back itself.
        }

        // A run that never reached the job's own error handling still gives the credits back.
        $this->meter->failAndRefund((int) $job->id, 'Voice generation failed. Please try again.', ['processing']);

        return $job->refresh();
    }

    /** Most background generations one member may have waiting or running, for each generation they may run at once. */
    public const QUEUED_PER_SLOT = 5;

    /** Background generations one member may have waiting or running at once. */
    public static function queueLimit(?Plan $plan): int
    {
        return max(10, ApiPlanAccess::concurrency($plan) * self::QUEUED_PER_SLOT);
    }

    /**
     * Background generations share the queue with the extension, so one
     * member may only have a few waiting at a time. Runs inside the metering
     * transaction (the member's requests are taken one at a time), so the
     * count cannot be raced.
     */
    private function assertQueueRoom(int $userId, ?Plan $plan, bool $async): void
    {
        if (! $async) {
            return;
        }

        $limit = self::queueLimit($plan);
        // A generation that got stuck is failed and refunded by the sweep
        // (api:expire-stuck-generations), which frees its place.
        $waiting = AudioGeneration::query()
            ->where('user_id', $userId)
            ->whereNotNull('api_key_id')
            ->whereIn('status', ['queued', 'processing'])
            ->count();

        if ($waiting >= $limit) {
            throw new ApiError(429, ApiError::TYPE_RATE_LIMIT, 'queue_limit_exceeded',
                "You already have {$waiting} background generations waiting or running (your limit is {$limit}). Wait for some to finish, then try again.",
                null, ['Retry-After' => '10'], ['queue_limit' => $limit]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $voice
     */
    private function language(array $validated, array $voice, string $text): string
    {
        $voiceLanguage = $voice['language'] ?? null;
        $requested = $validated['language_code'] ?? null;

        if ($requested === null) {
            return is_string($voiceLanguage) && $voiceLanguage !== ''
                ? $voiceLanguage
                : (SpeechLanguages::detect($text) === 'ar' ? 'ar' : 'en');
        }

        if (is_string($voiceLanguage) && $voiceLanguage !== '' && SpeechLanguages::voiceLanguage($requested) !== $voiceLanguage) {
            throw ApiError::invalid('language_mismatch', "This voice speaks \"{$voiceLanguage}\"; it cannot read \"{$requested}\". Choose a voice for that language.", 'language_code');
        }

        return $requested;
    }

    /**
     * Studio options, with the site's defaults for the language where none
     * is given (as the dashboard and extension do).
     *
     * @param  array<string, mixed>  $validated
     * @return array{dialect:?string, performance_style:string, emotion:string, style_prompt:?string, speed:float, pitch:float}
     */
    private function studioOptions(array $validated, string $language, ?Plan $plan): array
    {
        $speed = $validated['voice_settings']['speed'] ?? null;
        $pitch = $validated['voice_settings']['pitch'] ?? null;

        $dialect = trim((string) ($validated['dialect'] ?? ''));
        if ($dialect !== '' && ! in_array($dialect, TtsStudioConfig::dialectValues($language), true)) {
            throw ApiError::invalid('invalid_dialect', "Unknown dialect \"{$dialect}\" for language \"{$language}\". GET /v1/voice-options lists them.", 'dialect');
        }
        if ($dialect === '' && ! SpeechLanguages::premiumOnly($language)) {
            $dialect = TtsStudioConfig::defaultDialect($language);
        }

        $style = trim((string) ($validated['style'] ?? ''));
        if ($style !== '' && ! in_array($style, TtsStudioConfig::performanceStyleValues(), true)) {
            throw ApiError::invalid('invalid_style', "Unknown style \"{$style}\". GET /v1/voice-options lists them.", 'style');
        }

        $emotion = trim((string) ($validated['emotion'] ?? ''));
        if ($emotion !== '' && ! in_array($emotion, TtsStudioConfig::emotionValues(), true)) {
            throw ApiError::invalid('invalid_emotion', "Unknown emotion \"{$emotion}\". GET /v1/voice-options lists them.", 'emotion');
        }
        $defaults = TtsStudioConfig::withDefaultChoices(['performance_style' => $style, 'emotion' => $emotion], $language);

        $stylePrompt = trim((string) ($validated['style_prompt'] ?? ''));
        if ($stylePrompt !== '' && ! ApiFeatureGate::planOffers($plan, ApiFeatureGate::CUSTOM_DIRECTION)) {
            throw ApiFeatureGate::refusal(ApiFeatureGate::CUSTOM_DIRECTION, 'style_prompt');
        }

        return [
            'dialect' => $dialect !== '' ? $dialect : null,
            'performance_style' => $defaults['performance_style'],
            'emotion' => $defaults['emotion'],
            'style_prompt' => $stylePrompt !== '' ? $stylePrompt : null,
            'speed' => $speed !== null ? (float) $speed : 1.0,
            'pitch' => $pitch !== null ? (float) $pitch : 0.0,
        ];
    }

    /**
     * A sound environment for the whole recording, on plans (or members) that have them.
     *
     * @param  array<string, mixed>  $validated
     */
    private function audioEffect(array $validated, User $user, ?Plan $plan): ?string
    {
        $effect = trim((string) ($validated['audio_effect'] ?? ''));
        if ($effect === '') {
            return null;
        }
        if (! AudioEffects::isValid($effect)) {
            throw ApiError::invalid('invalid_audio_effect', "Unknown audio_effect \"{$effect}\". GET /v1/voice-options lists them.", 'audio_effect');
        }
        if (! PlanFeatures::userHas($user, $plan, PlanFeatures::EFFECTS)) {
            throw ApiFeatureGate::refusal(ApiFeatureGate::SOUND_ENVIRONMENTS, 'audio_effect');
        }
        if (! AudioEffects::available()) {
            throw new ApiError(503, ApiError::TYPE_API, 'feature_unavailable', 'Sound environments are temporarily unavailable. Try again later, or leave audio_effect out.', 'audio_effect');
        }

        return $effect;
    }

    /**
     * Dialogue with several voices: each speaker's name starts their lines in
     * the text ("Sara: …"), as in the studio's dialogue mode.
     *
     * @param  array<string, mixed>  $validated
     * @return array<int, array{name:string, voice:string, dialect?:string}>|null
     */
    private function dialogue(array $validated, User $user, ?Plan $plan, string $planTier, string $language, string $text): ?array
    {
        if (empty($validated['dialogue'])) {
            return null;
        }
        if (! PlanFeatures::userHas($user, $plan, PlanFeatures::DIALOGUE)) {
            throw ApiFeatureGate::refusal(ApiFeatureGate::DIALOGUE, 'dialogue');
        }

        $speakers = [];
        foreach ((array) $validated['dialogue']['speakers'] as $index => $speaker) {
            $voiceId = trim((string) $speaker['voice_id']);
            $voice = ApiVoiceCatalog::find($user, $plan, $planTier, $voiceId, false);
            if ($voice === null || ($voice['category'] ?? '') !== 'premade') {
                throw ApiError::invalid('invalid_dialogue', "dialogue.speakers.{$index}.voice_id must be a studio voice id from GET /v1/voices.", "dialogue.speakers.{$index}.voice_id");
            }
            if (($voice['language'] ?? null) !== SpeechLanguages::voiceLanguage($language)) {
                throw ApiError::invalid('invalid_dialogue', "dialogue.speakers.{$index}.voice_id speaks \"{$voice['language']}\"; every speaker must speak \"{$language}\".", "dialogue.speakers.{$index}.voice_id");
            }
            $entry = ['name' => trim((string) $speaker['name']), 'voice' => $voiceId];
            $dialect = trim((string) ($speaker['dialect'] ?? ''));
            if ($dialect !== '') {
                if (! in_array($dialect, TtsStudioConfig::dialectValues($language), true)) {
                    throw ApiError::invalid('invalid_dialect', "Unknown dialect \"{$dialect}\" for language \"{$language}\".", "dialogue.speakers.{$index}.dialect");
                }
                $entry['dialect'] = $dialect;
            }
            $speakers[] = $entry;
        }

        try {
            DialogueSpeakers::ensureUsable(VoiceTier::HD, $speakers, $text, 'text');
        } catch (ValidationException $e) {
            $field = (string) array_key_first($e->errors());
            throw ApiError::invalid('invalid_dialogue', (string) ($e->errors()[$field][0] ?? 'The dialogue is not valid.'), $field === 'text' ? 'text' : 'dialogue.speakers');
        }

        return $speakers;
    }

    /**
     * MP3 always; other types when the plan includes them and this server can
     * make them (WAV needs ffmpeg; M4A, OGG and FLAC also need its encoder).
     *
     * @return array<int, string>
     */
    public static function formatsFor(?Plan $plan): array
    {
        $formats = array_values(array_unique(array_merge(['mp3'], AudioFormats::chosenFor($plan))));

        return array_values(array_filter($formats, static fn (string $format): bool => match ($format) {
            'mp3' => true,
            'wav' => AudioFormats::serverCanConvert(),
            default => AudioFormats::serverSupports($format),
        }));
    }

    /**
     * The formats besides MP3 that at least one plan with the API can ask for,
     * in a fixed order (for the documentation).
     *
     * @return array<int, string>
     */
    public static function formatsOffered(): array
    {
        $offered = ApiFeatureGate::apiPlans()->flatMap(fn (Plan $plan): array => self::formatsFor($plan))->unique()->all();

        return array_values(array_filter(array_keys(AudioFormats::ALL), fn (string $format): bool => $format !== 'mp3' && in_array($format, $offered, true)));
    }

    public function assertOutputFormat(string $format, ?Plan $plan): void
    {
        if (! in_array($format, self::OUTPUT_FORMATS, true)) {
            throw ApiError::invalid('invalid_output_format', 'output_format must be one of: '.implode(', ', self::OUTPUT_FORMATS).'.', 'output_format');
        }
        if ($format !== 'mp3' && ! in_array($format, AudioFormats::chosenFor($plan), true)) {
            throw ApiFeatureGate::formatRefusal($format);
        }
        if (! in_array($format, self::formatsFor($plan), true)) {
            throw new ApiError(503, ApiError::TYPE_API, 'output_format_unavailable', "The {$format} output format is temporarily unavailable. Use mp3.", 'output_format');
        }
    }
}
