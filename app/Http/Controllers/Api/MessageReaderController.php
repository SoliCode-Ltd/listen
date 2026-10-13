<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateAudioJob;
use App\Models\AudioGeneration;
use App\Models\SiteSetting;
use App\Models\UsageCounter;
use App\Models\User;
use App\Rules\DoesNotContainProfanity;
use App\Services\AudioGenerationRecorder;
use App\Services\Billing\CharPackService;
use App\Services\Billing\TrialUsageRecorder;
use App\Services\BlockedUserService;
use App\Services\BillingSyncService;
use App\Services\PlanAccessService;
use App\Support\AudioUrl;
use App\Support\PlanUsageGuard;
use App\Support\TtsStudioConfig;
use App\Support\UsageEstimator;
use App\Support\UsageSnapshot;
use App\Support\VoiceCatalog;
use App\Support\VoiceTier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

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

        $period = now()->format('Y-m');
        $counter = UsageCounter::firstOrCreate(
            ['user_id' => $user->id, 'period_ym' => $period],
            ['total_minutes' => 0, 'total_chars' => 0, 'total_requests' => 0]
        );

        $usage = UsageSnapshot::for((int) $user->id, $quotaPlan, $counter);
        $requestsUnlimited = $usage['requests_unlimited'];

        // Determine the preferred provider for HD voices based on plan policy
        $preferredProvider = $this->preferredProviderForPlan($activePlan, $planTier);
        $catalog = VoiceCatalog::publicCatalog($planTier, $preferredProvider);

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
                    'name' => (string) ($activePlan?->name ?? 'FREE'),
                    'slug' => (string) ($activePlan?->slug ?? 'free'),
                    'voice_tier' => $planTier,
                    'voice_tier_label' => VoiceTier::label($planTier),
                    'max_chars_per_request' => PlanUsageGuard::maxCharsPerRequest($quotaPlan),
                    'allow_overage' => PlanUsageGuard::allowsOverage($quotaPlan),
                    'overage_price_per_million_cents' => $activePlan?->overage_price_per_million_cents !== null
                        ? (int) $activePlan->overage_price_per_million_cents
                        : null,
                    'commercial_use_allowed' => (bool) ($activePlan?->commercial_use_allowed ?? false),
                    'processing_priority' => (string) ($activePlan?->processing_priority ?: 'standard'),
                    'requests_unlimited' => $requestsUnlimited,
                    'dialogue_enabled' => (bool) ($activePlan?->dialogue_enabled ?? false),
                    'tts_provider_policy' => $activePlan?->tts_provider_policy ?? 'default',
                ],
                'usage' => ['period' => $period] + $usage,
                'voices' => [
                    'catalog' => $catalog,
                    'defaults' => [
                        'ar' => $defaultAr['key'] ?? null,
                        'en' => $defaultEn['key'] ?? null,
                    ],
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
        $maxChars = PlanUsageGuard::maxCharsPerRequest($quotaPlan);
        $processingMode = strtolower((string) config('listen.audio.processing', 'sync'));
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
            // Dialogue parameters
            'is_dialogue' => ['nullable', 'boolean'],
            'dialogue_speakers' => ['nullable', 'array', 'max:6'],
            'dialogue_speakers.*.name' => ['required_with:dialogue_speakers', 'string', 'max:50'],
            'dialogue_speakers.*.voice' => ['required_with:dialogue_speakers', 'string', 'max:100'],
        ]);

        $requestedTier = VoiceTier::normalize((string) ($validated['voice_tier'] ?? $planTier));
        if (VoiceTier::isHigherThan($requestedTier, $planTier)) {
            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.dashboard_upgrade_for_quality'),
            ], 403);
        }

        $voiceName = $this->normalizeVoiceSelection(
            $validated['target_language'],
            $validated['voice_key'] ?? null,
            $validated['voice_name'] ?? null,
            $requestedTier,
        );
        $studioOptions = $this->sanitizeStudioOptionsForTier($requestedTier, [
            'dialect' => $validated['dialect'] ?? null,
            'emotion' => $validated['emotion'] ?? 'neutral',
            'performance_style' => $validated['performance_style'] ?? null,
            'style_prompt' => $validated['style_prompt'] ?? null,
            'speed' => $validated['speed'] ?? null,
            'pitch' => $validated['pitch'] ?? null,
        ], $activePlan);

        $text = trim((string) ($validated['content'] ?? $validated['text'] ?? ''));
        $sourceLanguage = preg_match('/[\x{0600}-\x{06FF}]/u', $text) ? 'ar' : 'en';
        $estimate = UsageEstimator::estimate($text);
        if ($estimate['chars'] > $maxChars) {
            throw ValidationException::withMessages([
                'content' => __('ui.backend.dashboard_request_limit', ['max' => $maxChars]),
            ]);
        }

        $forceAsyncChars = max(0, (int) config('listen.audio.force_async_chars', 2500));
        $preferSync = (bool) ($validated['prefer_sync'] ?? false);
        $runAsync = $processingMode === 'queue'
            || (
                ! $preferSync
                && $processingMode === 'sync'
                && $forceAsyncChars > 0
                && $estimate['chars'] >= $forceAsyncChars
            );
        $initialStatus = $runAsync ? 'queued' : 'processing';

        $period = now()->format('Y-m');
        $counter = UsageCounter::firstOrCreate(
            ['user_id' => $user->id, 'period_ym' => $period],
            ['total_minutes' => 0, 'total_chars' => 0, 'total_requests' => 0]
        );

        $billToPack = CharPackService::billsToPack($quotaPlan);
        PlanUsageGuard::assertWithinDailyLimit($quotaPlan, (int) $user->id);

        if ($billToPack) {
            // Takes the pack characters now; given back if the generation fails.
            app(CharPackService::class)->reserve((int) $user->id, $estimate['credits']);
        } else {
            PlanUsageGuard::assertWithinLimits($quotaPlan, $counter, $estimate);
        }

        $dialogueEnabled = (bool) ($activePlan?->dialogue_enabled ?? false);
        try {
            $recorded = $this->audioGenerationRecorder->createForUser(
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
                    'tts_provider' => VoiceCatalog::providerForName($voiceName),
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
                    'billed_to_pack' => $billToPack,
                ],
            );
        } catch (\Throwable $e) {
            if ($billToPack) {
                app(CharPackService::class)->release((int) $user->id, $estimate['credits']);
            }
            throw $e;
        }

        $item = $recorded['item'];
        $job = $recorded['job'];

        // Reserve usage at queue time; GenerateAudioJob reconciles on success/final failure.
        if (! $billToPack) {
            $counter->increment('total_requests');
            $counter->increment('total_chars', $estimate['credits']);
            $counter->addSeconds($estimate['seconds']);
            app(TrialUsageRecorder::class)->add((int) $user->id, $estimate);
        }

        if (! $runAsync) {
            try {
                GenerateAudioJob::dispatchSync($job->id);
            } catch (\Throwable) {
                // Job failed — status will be checked below.
            }

            $job->refresh();

            // Always refund usage when the job actually failed,
            // regardless of whether the exception propagated.
            if ($job->status === 'failed') {
                if ($billToPack) {
                    app(CharPackService::class)->release((int) $user->id, $estimate['credits']);
                } else {
                    $this->releaseReservedUsage($counter, $estimate);
                }
            }
        } else {
            $queueName = strtolower((string) ($activePlan?->processing_priority ?? 'standard')) === 'high'
                ? 'high'
                : 'default';

            GenerateAudioJob::dispatch($job->id)->onQueue($queueName);
        }

        $isFailed = $job->status === 'failed';
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
            'error_message' => $job->error_message,
            'estimated_chars' => $estimate['chars'],
            'estimated_credits' => $estimate['credits'],
            'estimated_seconds' => $estimate['seconds'],
            'estimated_minutes' => $estimate['minutes'],
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
                    'error_message' => $job->error_message,
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

        // Backward-compatible emergency key for server-to-server integrations.
        $legacyApiKey = trim((string) env('LISTEN_API_KEY', ''));
        if ($legacyApiKey !== '' && hash_equals($legacyApiKey, $provided)) {
            $user = User::query()->findOrFail(1);
            if ($this->blockedUserService->isBlocked($user)) {
                abort(403, $this->blockedUserService->messageFor($user));
            }

            $request->attributes->set('listen_actor_user_id', (int) $user->id);

            return $user;
        }

        abort(401, __('ui.backend.api_invalid_key'));
    }

    /**
     * @param  array{chars:int,seconds:int}  $estimate
     */
    private function releaseReservedUsage(UsageCounter $counter, array $estimate): void
    {
        $counter->refresh();

        $counter->update([
            'total_requests' => max(0, (int) $counter->total_requests - 1),
            'total_chars' => max(0, (int) $counter->total_chars - (int) $estimate['credits']),
        ]);
        $counter->addSeconds(-(int) $estimate['seconds']);
        app(TrialUsageRecorder::class)->release((int) $counter->user_id, $estimate);
    }

    private function normalizeVoiceSelection(string $language, ?string $voiceKey, ?string $voiceName, string $tier): string
    {
        $voiceKey = trim((string) $voiceKey);
        $voiceName = trim((string) $voiceName);

        if ($voiceKey !== '') {
            $mapped = VoiceCatalog::nameFromKeyForLanguage($language, $voiceKey, $tier);
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

        $defaultVoice = VoiceCatalog::defaultForLanguage($language, $tier);
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
                'performance_style' => null,
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
            'performance_style' => $performanceStyle !== '' ? $performanceStyle : null,
            // Custom direction only on plans that include it, as in the dashboard.
            'style_prompt' => (bool) ($plan?->custom_prompt_enabled ?? false) && isset($options['style_prompt']) && is_string($options['style_prompt'])
                ? (trim($options['style_prompt']) ?: null)
                : null,
            'speed' => isset($options['speed']) ? (float) $options['speed'] : 1.0,
            'pitch' => isset($options['pitch']) ? (float) $options['pitch'] : 0.0,
        ];
    }

    private function preferredProviderForPlan(?\App\Models\Plan $plan, string $planTier): ?string
    {
        if ($planTier !== VoiceTier::HD) {
            return null;
        }

        $siteOverride = $plan
            ? $this->normalizeProviderPolicyValue(SiteSetting::get("tts_plan_provider_{$plan->slug}", null))
            : null;

        if ($siteOverride !== null) {
            return $siteOverride;
        }

        $planOverride = $this->normalizeProviderPolicyValue($plan?->tts_provider_policy);
        if ($planOverride !== null) {
            return $planOverride;
        }

        return VoiceCatalog::preferredProviderForTier($planTier);
    }

    private function normalizeProviderPolicyValue(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            'google_cloud', 'google_only' => 'google_cloud',
            'gemini', 'gemini_only', 'gemini_preferred' => 'gemini',
            default => null,
        };
    }
}
