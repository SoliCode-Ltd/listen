<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateAudioJob;
use App\Models\AudioGeneration;
use App\Models\User;
use App\Rules\DoesNotContainProfanity;
use App\Services\Api\ApiSpeechService;
use App\Services\AudioGenerationRecorder;
use App\Services\Billing\MemberUsageMeter;
use App\Services\BlockedUserService;
use App\Services\BillingSyncService;
use App\Services\PlanAccessService;
use App\Models\ClonedVoice;
use App\Support\AudioFormats;
use App\Support\AudioUrl;
use App\Support\CreditPeriod;
use App\Support\Credits;
use App\Support\DialogueSpeakers;
use App\Support\GenerationMessage;
use App\Support\PlanUsageGuard;
use App\Support\VoiceCloningAccess;
use App\Support\TtsStudioConfig;
use App\Support\UsageSnapshot;
use App\Support\VoiceCatalog;
use App\Support\VoiceTier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

class MessageReaderController extends Controller
{
    public function __construct(
        private readonly PlanAccessService $planAccessService,
        private readonly BillingSyncService $billingSyncService,
        private readonly AudioGenerationRecorder $audioGenerationRecorder,
        private readonly BlockedUserService $blockedUserService,
    ) {}

    public function profile(Request $request)
    {
        $user = $this->resolveApiUser($request);
        $this->billingSyncService->syncIfStale($user);

        $activePlan = $this->planAccessService->resolveAccessPlanForUserId((int) $user->id);
        $quotaPlan = $this->planAccessService->resolveQuotaPlanForUserId((int) $user->id, $activePlan);
        $planTier = $this->planAccessService->resolveTierForPlan($activePlan);

        $counter = CreditPeriod::counter((int) $user->id);

        $usage = UsageSnapshot::for((int) $user->id, $quotaPlan, $counter);
        $requestsUnlimited = $usage['requests_unlimited'];

        // The same voices and defaults as the dashboard.
        $preferredProvider = VoiceCatalog::preferredProviderForTier($planTier);
        $catalog = VoiceCatalog::publicCatalogByTier()[$planTier] ?? VoiceCatalog::publicCatalog($planTier, $preferredProvider);
        $locale = (string) app()->getLocale();

        $defaultAr = VoiceCatalog::voiceMeta(
            VoiceCatalog::defaultForLanguageAndProvider('ar', $planTier, $preferredProvider)
        );
        $defaultEn = VoiceCatalog::voiceMeta(
            VoiceCatalog::defaultForLanguageAndProvider('en', $planTier, $preferredProvider)
        );

        return response()->json([
            'ok' => true,
            'profile' => [
                'user' => [
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                ],
                'plan' => [
                    'name' => $activePlan ? ($activePlan->getLocalizedName($locale) ?: (string) $activePlan->name) : __('ui.dashboard.free_plan'),
                    'slug' => (string) ($activePlan?->slug ?? 'free'),
                    'voice_tier' => $planTier,
                    'voice_tier_label' => VoiceTier::label($planTier),
                    'max_chars_per_request' => self::maxCharsPerRequest($quotaPlan),
                    'credits_per_second' => Credits::PER_SECOND,
                    'credits_per_minute' => Credits::PER_MINUTE,
                    'daily_requests' => PlanUsageGuard::dailyRequests($quotaPlan),
                    'custom_prompt_enabled' => (bool) $activePlan?->allowsCustomPrompt(),
                    'download_formats' => AudioFormats::chosenFor($activePlan),
                    'commercial_use_allowed' => (bool) ($activePlan?->commercial_use_allowed ?? false),
                    'processing_priority' => (string) ($activePlan?->processing_priority ?: 'standard'),
                    'requests_unlimited' => $requestsUnlimited,
                    'dialogue_enabled' => (bool) ($activePlan?->dialogue_enabled ?? false),
                    'voice_cloning_enabled' => VoiceCloningAccess::allows($user, $activePlan),
                ],
                'usage' => ['period' => $counter->period_ym] + $usage,
                'links' => [
                    'dashboard' => $this->localizedUrl('dashboard', $locale),
                    'pricing' => $this->localizedUrl('pricing', $locale),
                    'billing' => $this->localizedUrl('billing.page', $locale),
                    'manage' => $this->localizedUrl('manage-subscription.page', $locale),
                ],
                'voices' => [
                    'catalog' => $catalog,
                    'defaults' => [
                        'ar' => $defaultAr['key'] ?? null,
                        'en' => $defaultEn['key'] ?? null,
                    ],
                    'cloned' => $this->clonedVoicesFor($user, $activePlan),
                    'studio' => [
                        'performance_styles' => TtsStudioConfig::performanceStyleCatalog(),
                        'emotions' => TtsStudioConfig::emotionCatalog(),
                        'dialects' => TtsStudioConfig::dialectCatalogByLanguage(),
                        'defaults' => [
                            'ar' => [
                                'performance_style' => TtsStudioConfig::defaultPerformanceStyle('ar'),
                                'emotion' => TtsStudioConfig::defaultEmotion('ar'),
                                'dialect' => TtsStudioConfig::defaultDialect('ar'),
                            ],
                            'en' => [
                                'performance_style' => TtsStudioConfig::defaultPerformanceStyle('en'),
                                'emotion' => TtsStudioConfig::defaultEmotion('en'),
                                'dialect' => TtsStudioConfig::defaultDialect('en'),
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function queue(Request $request)
    {
        $user = $this->resolveApiUser($request);
        $this->billingSyncService->syncIfStale($user);

        $activePlan = $this->planAccessService->resolveAccessPlanForUserId((int) $user->id);
        $quotaPlan = $this->planAccessService->resolveQuotaPlanForUserId((int) $user->id, $activePlan);
        $planTier = $this->planAccessService->resolveTierForPlan($activePlan);
        $maxChars = self::maxCharsPerRequest($quotaPlan);
        $processingMode = strtolower((string) config('listen.audio.processing', 'sync'));
        $dialogueEnabled = (bool) ($activePlan?->dialogue_enabled ?? false);
        // Style and emotion left on "Default" get the site's defaults, as in the dashboard.
        if (VoiceTier::normalize((string) ($request->input('voice_tier') ?? $planTier)) === VoiceTier::HD) {
            $request->merge(TtsStudioConfig::withDefaultChoices($request->only(['performance_style', 'emotion']), $request->input('target_language')));
        }
        $performanceStyleValues = TtsStudioConfig::performanceStyleValues();
        $emotionValues = TtsStudioConfig::emotionValues();

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string', 'max:50000', 'required_without:text', new DoesNotContainProfanity(app(\App\Services\ProfanityFilter::class))],
            'text' => ['nullable', 'string', 'max:50000', 'required_without:content', new DoesNotContainProfanity(app(\App\Services\ProfanityFilter::class))],
            'target_language' => ['required', 'in:ar,en'],
            'voice_key' => ['nullable', 'string', 'max:60'],
            'voice_name' => ['nullable', 'string', 'max:120'],
            'voice_tier' => ['nullable', 'in:standard,hd'],
            'translate' => ['nullable', 'boolean'],
            'prefer_sync' => ['nullable', 'boolean'],
            // Studio parameters
            'dialect' => ['nullable', 'string', 'max:10'],
            'emotion' => ['nullable', 'string', 'in:'.implode(',', $emotionValues)],
            'performance_style' => ['nullable', 'string', 'in:'.implode(',', $performanceStyleValues)],
            'style_prompt' => ['nullable', 'string', 'max:2000'],
            'speed' => ['nullable', 'numeric', 'min:0.5', 'max:2.0'],
            'pitch' => ['nullable', 'numeric', 'min:-10', 'max:10'],
        ] + DialogueSpeakers::rules(), [
            'performance_style.in' => __('ui.backend.performance_style_invalid'),
            'emotion.in' => __('ui.backend.emotion_invalid'),
        ]);

        $requestedTier = VoiceTier::normalize((string) ($validated['voice_tier'] ?? $planTier));
        if (VoiceTier::isHigherThan($requestedTier, $planTier)) {
            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.dashboard_upgrade_for_quality'),
            ], 403);
        }

        if ($dialogueEnabled && ($validated['is_dialogue'] ?? false)) {
            DialogueSpeakers::ensureUsable($requestedTier, (array) ($validated['dialogue_speakers'] ?? []), (string) ($validated['content'] ?? $validated['text'] ?? ''));
        }

        $voiceKey = trim((string) ($validated['voice_key'] ?? ''));
        if (str_starts_with($voiceKey, 'cloned_')) {
            $voiceName = $this->clonedVoiceName($user, $activePlan, $voiceKey);
            $voiceProvider = 'gemini';
        } else {
            $voiceName = $this->normalizeVoiceSelection(
                $validated['target_language'],
                $voiceKey,
                $validated['voice_name'] ?? null,
                $requestedTier,
            );
            $voiceProvider = VoiceCatalog::providerForName($voiceName);
        }
        // No dialect chosen: the site's default for the language, as the dashboard preselects it.
        $dialect = trim((string) ($validated['dialect'] ?? '')) ?: TtsStudioConfig::defaultDialect((string) $validated['target_language']);
        $studioOptions = $this->sanitizeStudioOptionsForTier($requestedTier, [
            'dialect' => $dialect,
            'emotion' => $validated['emotion'] ?? 'neutral',
            'performance_style' => $validated['performance_style'] ?? null,
            'style_prompt' => $validated['style_prompt'] ?? null,
            'speed' => $validated['speed'] ?? null,
            'pitch' => $validated['pitch'] ?? null,
        ], $activePlan);

        $text = trim((string) ($validated['content'] ?? $validated['text'] ?? ''));
        // Moods and sounds go with words: a script of tags alone has nothing to speak.
        if (! \App\Support\GeminiVocalTags::hasWords($text)) {
            throw ValidationException::withMessages(['content' => __('ui.backend.dashboard_text_needs_words')]);
        }
        $sourceLanguage = preg_match('/[\x{0600}-\x{06FF}]/u', $text) ? 'ar' : 'en';
        // A slower speed makes longer audio, so the reservation allows for it, as in the dashboard.
        $estimate = ApiSpeechService::estimate($text, (float) $studioOptions['speed'], $requestedTier, $studioOptions);
        if ($estimate['chars'] > $maxChars) {
            throw ValidationException::withMessages([
                'content' => __('ui.backend.dashboard_request_limit', ['max' => $maxChars]),
            ]);
        }

        $runAsync = $processingMode === 'queue';
        // Otherwise a long text is made after the reply, whatever the extension asks (an
        // older one asks to wait for every text): no time limit of the web server cuts it
        // short, and it needs no queue worker. The extension checks on it.
        $afterResponse = ! $runAsync && GenerateAudioJob::runsAfterResponse($estimate['chars']);
        $initialStatus = $runAsync ? 'queued' : 'processing';

        // The limits are checked and the credits reserved in one locked step, with the
        // generation saved inside it, as in the dashboard: two requests at once cannot
        // both spend the last credits. GenerateAudioJob reconciles them, or gives them back.
        $recorded = app(MemberUsageMeter::class)->reserve((int) $user->id, $quotaPlan, $estimate, fn (array $reservation): array => $this->audioGenerationRecorder->createForUser(
            (int) $user->id,
            [
                'source_type' => 'message',
                'title' => $validated['title'] ?? null,
                'content' => $text,
                'language' => $sourceLanguage,
                'status' => $initialStatus,
                'received_at' => now(),
            ],
            [
                'input_text' => $text,
                'source_language' => $sourceLanguage,
                'target_language' => $validated['target_language'],
                'voice_tier' => $requestedTier,
                'voice_name' => $voiceName,
                'tts_provider' => $voiceProvider,
                'translate' => (bool) ($validated['translate'] ?? false),
                'duration_seconds' => $estimate['seconds'],
                'status' => $initialStatus,
                'dialect' => $studioOptions['dialect'],
                'emotion' => $studioOptions['emotion'],
                'performance_style' => $studioOptions['performance_style'],
                'style_prompt' => $studioOptions['style_prompt'],
                'speed' => $studioOptions['speed'],
                'pitch' => $studioOptions['pitch'],
                'is_dialogue' => $dialogueEnabled
                    && $requestedTier !== VoiceTier::STANDARD
                    && ($validated['is_dialogue'] ?? false),
                'dialogue_metadata' => ($dialogueEnabled
                    && $requestedTier !== VoiceTier::STANDARD
                    && ($validated['is_dialogue'] ?? false)
                    && ! empty($validated['dialogue_speakers']))
                    ? ['speakers' => $validated['dialogue_speakers']]
                    : null,
                'billed_to_pack' => $reservation['billed_to_pack'],
                'usage_period' => $reservation['period'],
            ],
        ));

        $item = $recorded['item'];
        $job = $recorded['job'];

        if ($runAsync) {
            // "Priority" and "highest" rank above "high": never the slower default queue.
            $queueName = in_array(strtolower((string) ($activePlan?->processing_priority ?? 'standard')), ['high', 'priority', 'highest'], true)
                ? 'high'
                : 'default';

            GenerateAudioJob::dispatch($job->id)->onQueue($queueName);
        } elseif ($afterResponse) {
            GenerateAudioJob::runAfterResponse((int) $job->id);
        } else {
            try {
                GenerateAudioJob::dispatchSync($job->id);
            } catch (\Throwable) {
                // Job failed — status will be checked below.
            }

            // A failed generation has given its credits back itself; one the job's own
            // error handling never reached is failed and refunded here, exactly once.
            app(MemberUsageMeter::class)->failAndRefund((int) $job->id, GenerationMessage::stored('ui.backend.dashboard_voice_generation_failed_try_again'), ['processing']);

            $job->refresh();
        }

        $isFailed = $job->status === 'failed';
        $counter = CreditPeriod::counter((int) $user->id);
        $statusCode = in_array($job->status, ['queued', 'processing'], true)
            ? 202
            : ($isFailed ? 422 : 200);

        return response()->json([
            'ok' => ! $isFailed,
            'job_id' => $job->id,
            'item_id' => $item->id,
            'status' => $job->status,
            'voice' => VoiceCatalog::voiceMeta($voiceName),
            'voice_tier' => $requestedTier,
            'voice_tier_label' => VoiceTier::label($requestedTier),
            'audio_url' => AudioUrl::fromPath($job->audio_path),
            'error_message' => GenerationMessage::forDisplay($job->error_message),
            'estimated_chars' => $estimate['chars'],
            'estimated_credits' => $estimate['credits'],
            'estimated_seconds' => $estimate['seconds'],
            'estimated_minutes' => $estimate['minutes'],
            'usage' => UsageSnapshot::for((int) $user->id, $quotaPlan, $counter),
        ], $statusCode);
    }

    public function list(Request $request)
    {
        $user = $this->resolveApiUser($request);
        $this->billingSyncService->syncIfStale($user);

        $limit = min(100, max(1, (int) $request->integer('limit', 25)));

        $jobs = AudioGeneration::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit($limit)
            ->get([
                'id',
                'source_language',
                'target_language',
                'voice_name',
                'voice_tier',
                'translate',
                'status',
                'duration_seconds',
                'audio_path',
                'error_message',
                'created_at',
            ])
            ->map(function (AudioGeneration $job) {
                return [
                    'id' => $job->id,
                    'display_number' => $job->displayNumber(),
                    'source_language' => $job->source_language,
                    'target_language' => $job->target_language,
                    'voice' => VoiceCatalog::voiceMeta($job->voice_name),
                    'voice_tier' => VoiceTier::label((string) ($job->voice_tier ?: VoiceTier::STANDARD)),
                    'translate' => $job->translate,
                    'status' => $job->status,
                    'duration_seconds' => $job->duration_seconds,
                    'audio_url' => AudioUrl::fromPath($job->audio_path),
                    'error_message' => GenerationMessage::forDisplay($job->error_message),
                    'created_at' => $job->created_at,
                ];
            });

        return response()->json([
            'ok' => true,
            'count' => $jobs->count(),
            'jobs' => $jobs,
        ]);
    }

    private function resolveApiUser(Request $request): User
    {
        // Messages and labels in the extension's language (it sends X-Listen-Locale).
        $locale = strtolower(substr(trim((string) ($request->header('X-Listen-Locale') ?: $request->getPreferredLanguage(['en', 'ar']) ?: '')), 0, 2));
        if (in_array($locale, ['ar', 'en'], true)) {
            app()->setLocale($locale);
        }

        if (Auth::check()) {
            $user = $request->user();
            if ($this->blockedUserService->isBlocked($user)) {
                abort(403, $this->blockedUserService->messageFor($user));
            }

            $request->attributes->set('listen_actor_user_id', (int) $user->id);

            return $user;
        }

        $provided = $request->header('X-Listen-Key')
            ?? $request->header('X-API-Key')
            ?? $request->input('api_key');

        $provided = is_string($provided) ? trim($provided) : '';
        if ($provided === '') {
            abort(401, __('ui.backend.api_missing_key'));
        }

        $user = User::query()
            ->where('api_key_hash', User::hashApiKey($provided))
            ->first();

        if ($user) {
            if ($this->blockedUserService->isBlocked($user)) {
                abort(403, $this->blockedUserService->messageFor($user));
            }

            $request->attributes->set('listen_actor_user_id', (int) $user->id);

            return $user;
        }

        // Backward-compatible emergency key for server-to-server integrations. It
        // acts as the owner's account, so it is taken from a header only: a key
        // in a link ends up in browser history and server logs.
        $legacyApiKey = trim((string) env('LISTEN_API_KEY', ''));
        $fromHeader = trim((string) ($request->header('X-Listen-Key') ?? $request->header('X-API-Key') ?? ''));
        if ($legacyApiKey !== '' && $fromHeader !== '' && hash_equals($legacyApiKey, $fromHeader)) {
            $user = User::query()->findOrFail(1);
            if ($this->blockedUserService->isBlocked($user)) {
                abort(403, $this->blockedUserService->messageFor($user));
            }

            $request->attributes->set('listen_actor_user_id', (int) $user->id);

            return $user;
        }

        abort(401, __('ui.backend.api_invalid_key'));
    }

    private function normalizeVoiceSelection(string $language, ?string $voiceKey, ?string $voiceName, string $tier): string
    {
        $voiceKey = trim((string) $voiceKey);
        $voiceName = trim((string) $voiceName);

        $preferredProvider = VoiceCatalog::preferredProviderForTier($tier);

        if ($voiceKey !== '') {
            $mapped = VoiceCatalog::nameFromKeyForLanguageAndProvider($language, $voiceKey, $tier, $preferredProvider);
            if (! $mapped) {
                throw ValidationException::withMessages([
                    'voice_key' => __('ui.backend.dashboard_speaker_not_available'),
                ]);
            }

            return $mapped;
        }

        if ($voiceName !== '') {
            if (! VoiceCatalog::isAllowedForLanguage($voiceName, $language, $tier)) {
                throw ValidationException::withMessages([
                    'voice_name' => __('ui.backend.dashboard_speaker_not_available'),
                ]);
            }

            return $voiceName;
        }

        $defaultVoice = VoiceCatalog::defaultForLanguageAndProvider($language, $tier, $preferredProvider);
        if ($defaultVoice === '') {
            throw ValidationException::withMessages([
                'target_language' => __('ui.backend.dashboard_language_not_available_for_tier'),
            ]);
        }

        return $defaultVoice;
    }

    /**
     * @param  array{dialect:mixed,emotion:mixed,performance_style:mixed,style_prompt:mixed,speed:mixed,pitch:mixed}  $options
     * @return array{dialect:?string,emotion:string,performance_style:?string,style_prompt:?string,speed:float,pitch:float}
     */
    private function sanitizeStudioOptionsForTier(string $tier, array $options, ?\App\Models\Plan $plan = null): array
    {
        $normalizedTier = VoiceTier::normalize($tier);

        if ($normalizedTier === VoiceTier::STANDARD) {
            return [
                'dialect' => null,
                'emotion' => 'neutral',
                'performance_style' => 'neutral',
                'style_prompt' => null,
                'speed' => 1.0,
                'pitch' => 0.0,
            ];
        }

        $performanceStyle = isset($options['performance_style']) && is_string($options['performance_style'])
            ? trim((string) $options['performance_style'])
            : '';
        $emotion = isset($options['emotion']) && is_string($options['emotion'])
            ? trim((string) $options['emotion'])
            : '';

        if (
            $performanceStyle === ''
            && in_array($emotion, TtsStudioConfig::performanceStyleValues(), true)
            && ! in_array($emotion, TtsStudioConfig::emotionValues(), true)
        ) {
            $performanceStyle = $emotion;
            $emotion = 'neutral';
        }

        return [
            'dialect' => isset($options['dialect']) && is_string($options['dialect']) ? trim($options['dialect']) ?: null : null,
            'emotion' => $emotion !== '' ? $emotion : 'neutral',
            'performance_style' => $performanceStyle !== '' ? $performanceStyle : 'neutral',
            // Custom direction only on plans that include it, as in the dashboard.
            'style_prompt' => (bool) $plan?->allowsCustomPrompt() && isset($options['style_prompt']) && is_string($options['style_prompt'])
                ? (trim($options['style_prompt']) ?: null)
                : null,
            'speed' => isset($options['speed']) ? (float) $options['speed'] : 1.0,
            'pitch' => isset($options['pitch']) ? (float) $options['pitch'] : 0.0,
        ];
    }

    /** The plan's text limit per voice, never above what the request validation accepts. */
    private static function maxCharsPerRequest(?\App\Models\Plan $plan): int
    {
        return min(PlanUsageGuard::maxCharsPerRequest($plan), 50000);
    }

    private function localizedUrl(string $route, string $locale): string
    {
        try {
            return (string) LaravelLocalization::getLocalizedURL($locale, route($route), [], true);
        } catch (\Throwable) {
            return route($route);
        }
    }

    /**
     * The member's own ready cloned voices, when their plan includes cloning.
     *
     * @return array<int, array{key:string, label:string}>
     */
    private function clonedVoicesFor(User $user, ?\App\Models\Plan $plan): array
    {
        if (! VoiceCloningAccess::allows($user, $plan)) {
            return [];
        }

        return ClonedVoice::query()->where('user_id', $user->id)->ready()->orderBy('name')->get(['id', 'name'])
            ->map(fn (ClonedVoice $voice): array => ['key' => 'cloned_'.$voice->id, 'label' => (string) $voice->name])
            ->values()->all();
    }

    private function clonedVoiceName(User $user, ?\App\Models\Plan $plan, string $voiceKey): string
    {
        if (! VoiceCloningAccess::allows($user, $plan)) {
            throw ValidationException::withMessages(['voice_key' => __('ui.backend.dashboard_speaker_not_available')]);
        }
        $id = (int) str_replace('cloned_', '', $voiceKey);
        $exists = ClonedVoice::query()->where('id', $id)->where('user_id', $user->id)->ready()->exists();
        if (! $exists) {
            throw ValidationException::withMessages(['voice_key' => __('ui.voice_cloning.processing_failed_generic')]);
        }

        return 'cloned_'.$id;
    }
}
