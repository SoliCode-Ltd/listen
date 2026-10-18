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
    /** Longest text one request may carry, whatever the plan (as the dashboard and extension). */
    public const MAX_TEXT = 50000;

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
    public function prepare(Request $request, User $user, ?Plan $plan, ?Plan $quotaPlan, string $voiceId): array
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

        $estimate = UsageEstimator::estimate($text);
        $maxChars = min(PlanUsageGuard::maxCharsPerRequest($quotaPlan), self::MAX_TEXT);
        if ($estimate['chars'] > $maxChars) {
            throw new ApiError(422, ApiError::TYPE_INVALID_REQUEST, 'text_too_long',
                "The text is {$estimate['chars']} characters; your plan allows up to {$maxChars} per request. Split it into shorter requests.",
                'text', [], ['max_characters' => $maxChars]);
        }

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
     * Reserves the credits, records the generation and runs it: now, or on
     * the queue when $async. A synchronous run that fails gives the credits back.
     *
     * @param  array<string, mixed>  $spec  from prepare()
     */
    public function generate(User $user, ApiKey $apiKey, ?Plan $plan, ?Plan $quotaPlan, array $spec, bool $async): AudioGeneration
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

        [$reservation, $recorded] = $this->meter->reserve($userId, $apiKey, $quotaPlan, $estimate, function (array $reservation) use ($userId, $apiKey, $plan, $async, $spec, $estimate, $status, $studio): array {
            $this->assertQueueRoom($userId, $plan, $async);

            return $this->audioGenerationRecorder->createForUser(
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
        });

        $job = $recorded['job'];

        if ($async) {
            $queue = in_array(strtolower((string) ($plan?->processing_priority ?? 'standard')), ['high', 'priority', 'highest'], true) ? 'high' : 'default';
            try {
                GenerateAudioJob::dispatch($job->id)->onQueue($queue);
            } catch (\Throwable) {
                // With a synchronous queue the job ran here; its failed() hook gave the credits back.
            }

            return $job->refresh();
        }

        try {
            GenerateAudioJob::dispatchSync($job->id);
        } catch (\Throwable) {
            // The status says what happened.
        }

        $job->refresh();
        if ($job->status === 'failed') {
            // A synchronous run is not refunded by the job itself (GenerateAudioJob::failed).
            $this->meter->release($userId, $apiKey, $estimate, $reservation);
        }

        return $job;
    }

    /** Most background generations one member may have waiting or running, for each generation they may run at once. */
    public const QUEUED_PER_SLOT = 5;

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

        $limit = max(10, ApiPlanAccess::concurrency($plan) * self::QUEUED_PER_SLOT);
        $waiting = AudioGeneration::query()
            ->where('user_id', $userId)
            ->whereNotNull('api_key_id')
            ->whereIn('status', ['queued', 'processing'])
            // A generation stuck far longer than any job may run no longer holds a place.
            ->where('created_at', '>=', now()->subMinutes(30))
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
     * MP3 always; other types when the plan includes them and the server can make them.
     *
     * @return array<int, string>
     */
    public static function formatsFor(?Plan $plan): array
    {
        $formats = array_values(array_unique(array_merge(['mp3'], AudioFormats::chosenFor($plan))));

        return array_values(array_filter($formats, static fn (string $format): bool => $format === 'mp3' || AudioFormats::serverCanConvert()));
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
