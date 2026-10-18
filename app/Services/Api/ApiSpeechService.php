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
use App\Support\Api\ApiModels;
use App\Support\Api\ApiVoiceCatalog;
use App\Support\AudioFormats;
use App\Support\GeminiVocalTags;
use App\Support\PlanFeatures;
use App\Support\PlanUsageGuard;
use App\Support\SpeechLanguages;
use App\Support\TtsStudioConfig;
use App\Support\UsageEstimator;
use App\Support\VoiceCatalog;
use App\Support\VoiceTier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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
            'translate' => ['nullable', 'boolean'],
            'title' => ['nullable', 'string', 'max:255'],
            'output_format' => ['nullable', 'string', 'in:'.implode(',', self::OUTPUT_FORMATS)],
        ], [
            'text.required' => 'The text field is required.',
            'model_id.in' => 'model_id must be one of: '.implode(', ', ApiModels::ids()).'.',
            'language_code.in' => 'language_code must be one of: '.implode(', ', SpeechLanguages::codes()).'.',
            'output_format.in' => 'output_format must be one of: '.implode(', ', self::OUTPUT_FORMATS).'.',
        ]);
        $validated = $validator->validate();

        $planTier = app(PlanAccessService::class)->resolveTierForPlan($plan);
        $voice = ApiVoiceCatalog::find($user, $plan, $planTier, $voiceId, false);
        if ($voice === null) {
            throw ApiError::notFound('voice', 'voice_id');
        }
        $tier = (string) $voice['_tier'];
        if (VoiceTier::isHigherThan($tier, $planTier)) {
            throw ApiError::featureNotInPlan('This voice uses the '.ApiModels::PREMIUM.' model, which your plan does not include. Upgrade your plan or choose a '.ApiModels::STANDARD.' voice.', 'voice_id');
        }

        $modelId = ApiModels::forTier($tier);
        if (isset($validated['model_id']) && $validated['model_id'] !== $modelId) {
            throw ApiError::invalid('model_voice_mismatch', "This voice belongs to the {$modelId} model. Leave model_id out or send \"{$modelId}\".", 'model_id');
        }

        $text = trim((string) $validated['text']);
        if (! GeminiVocalTags::hasWords($text)) {
            throw ApiError::invalid('text_has_no_words', 'The text has nothing to read aloud. Tags and sounds need words with them.', 'text');
        }

        $language = $this->language($validated, $voice, $text);
        if (SpeechLanguages::premiumOnly($language) && $tier !== VoiceTier::HD) {
            throw ApiError::invalid('language_not_supported_by_model', "This language is only spoken by {$modelId} voices.", 'language_code');
        }

        $studio = $this->studioOptions($validated, $tier, $language, $plan);

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
            'model_id' => $modelId,
            'language' => $language,
            'source_language' => SpeechLanguages::detect($text),
            'text' => $text,
            'title' => $validated['title'] ?? null,
            'translate' => (bool) ($validated['translate'] ?? false),
            'studio' => $studio,
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

        [$reservation, $recorded] = $this->meter->reserve($userId, $apiKey, $quotaPlan, $estimate, fn (array $reservation): array => $this->audioGenerationRecorder->createForUser(
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
                'is_dialogue' => false,
                'dialogue_metadata' => null,
                'billed_to_pack' => $reservation['billed_to_pack'],
                'api_key_id' => (int) $apiKey->id,
            ],
        ));

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
    private function studioOptions(array $validated, string $tier, string $language, ?Plan $plan): array
    {
        $speed = $validated['voice_settings']['speed'] ?? null;
        $pitch = $validated['voice_settings']['pitch'] ?? null;

        if ($tier !== VoiceTier::HD) {
            foreach (['dialect', 'style', 'emotion', 'style_prompt'] as $param) {
                if (filled($validated[$param] ?? null)) {
                    throw ApiError::invalid('parameter_not_supported', "{$param} is only available on ".ApiModels::PREMIUM.' voices.', $param);
                }
            }
            foreach (['speed' => $speed, 'pitch' => $pitch] as $param => $value) {
                if ($value !== null) {
                    throw ApiError::invalid('parameter_not_supported', "voice_settings.{$param} is only available on ".ApiModels::PREMIUM.' voices.', "voice_settings.{$param}");
                }
            }

            return [
                'dialect' => null,
                'performance_style' => 'neutral',
                'emotion' => 'neutral',
                'style_prompt' => null,
                'speed' => 1.0,
                'pitch' => 0.0,
            ];
        }

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
        if ($stylePrompt !== '' && ! PlanFeatures::planOffers($plan, PlanFeatures::PROMPT)) {
            throw ApiError::featureNotInPlan('Custom voice direction (style_prompt) is not included in your plan.', 'style_prompt');
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
            throw ApiError::featureNotInPlan("The {$format} output format is not included in your plan.", 'output_format');
        }
        if (! in_array($format, self::formatsFor($plan), true)) {
            throw new ApiError(503, ApiError::TYPE_API, 'output_format_unavailable', "The {$format} output format is temporarily unavailable. Use mp3.", 'output_format');
        }
    }
}
