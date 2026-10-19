<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAudioJob;
use App\Models\AudioGeneration;
use App\Models\ClonedVoice;
use App\Models\DemoVoiceGeneration;
use App\Models\Plan;
use App\Models\UsageCounter;
use App\Rules\DoesNotContainProfanity;
use App\Services\AudioGenerationRecorder;
use App\Services\Billing\CharPackService;
use App\Services\Billing\TrialUsageRecorder;
use App\Services\LocalizedPricingService;
use App\Services\PlanAccessService;
use App\Services\ProfanityFilter;
use App\Services\VoiceCloningService;
use App\Support\AudioEffects;
use App\Support\AudioUrl;
use App\Support\DialogueSpeakers;
use App\Support\PlanFeatures;
use App\Support\PlanPricingLines;
use App\Support\CreditPeriod;
use App\Support\PlanUsageGuard;
use App\Support\SpeechLanguages;
use App\Support\TtsStudioConfig;
use App\Support\UsageEstimator;
use App\Support\UsageSnapshot;
use App\Support\VoiceCatalog;
use App\Support\VoiceCloningAccess;
use App\Support\VoiceTier;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    public function __construct(
        private readonly PlanAccessService $planAccessService,
        private readonly AudioGenerationRecorder $audioGenerationRecorder,
    ) {}

    public function index(Request $request)
    {
        $userId = (int) auth()->id();
        $usage = CreditPeriod::counter($userId);

        $activePlan = $this->planAccessService->resolveAccessPlanForUserId($userId);
        $quotaPlan = $this->planAccessService->resolveQuotaPlanForUserId($userId, $activePlan);

        $baseQuery = $this->userHistoryQuery($userId);
        $voiceNameMap = $this->voiceNameMapForUser($userId);
        $demoHistoryJobs = $this->buildDemoHistoryJobs($userId, $activePlan, $voiceNameMap);

        $jobs = (clone $baseQuery)
            ->with(['textItem:id,title'])
            ->latest()
            ->get();

        $reusePreset = null;
        $reuseDisplayNumber = (int) $request->integer('reuse');
        if ($reuseDisplayNumber > 0) {
            $reuseSource = $this->userHistoryQuery($userId)
                ->with('textItem:id,title')
                ->where('user_sequence_number', $reuseDisplayNumber)
                ->first();

            if ($reuseSource?->hasReusablePremiumSettings()) {
                $reusePreset = array_merge(
                    $reuseSource->reusableSettings() ?? [],
                    [
                        'job_id' => $reuseSource->id,
                        'display_number' => $reuseSource->displayNumber(),
                        'title' => (string) ($reuseSource->textItem?->title ?? ''),
                        'content' => (string) $reuseSource->input_text,
                    ],
                );
            }
        }

        if ($reusePreset === null) {
            $reuseDemoId = (int) $request->integer('reuse_demo');
            if ($reuseDemoId > 0) {
                $reuseDemoSource = DemoVoiceGeneration::query()
                    ->where('user_id', $userId)
                    ->whereKey($reuseDemoId)
                    ->first();
                $reusePreset = $this->buildDemoReusePreset($reuseDemoSource);
            }
        }

        $stats = [
            'processing' => (clone $baseQuery)->whereIn('status', ['queued', 'processing'])->count(),
            'done' => (clone $baseQuery)->where('status', 'done')->count(),
            'failed' => (clone $baseQuery)->where('status', 'failed')->count(),
            'total' => (clone $baseQuery)->count(),
        ];

        $planTier = $this->planAccessService->resolveTierForPlan($activePlan);
        $preferredProvider = VoiceCatalog::preferredProviderForTier($planTier);
        $catalogByTier = VoiceCatalog::publicCatalogByTier();
        $tierCatalog = $catalogByTier[$planTier] ?? VoiceCatalog::publicCatalog($planTier, $preferredProvider);
        $usageSnapshot = UsageSnapshot::for($userId, $quotaPlan, $usage);
        $packOffer = $this->packOffer($request, $usageSnapshot);
        // A member without a plan or characters sees every premium option, so
        // they can see what a plan gives them; generating asks them to choose one.
        $planShowcase = $packOffer !== null && $usageSnapshot['mode'] === 'monthly' && $usageSnapshot['remaining_chars'] <= 0;
        if ($planShowcase) {
            $planTier = VoiceTier::HD;
            $preferredProvider = VoiceCatalog::preferredProviderForTier($planTier);
            $tierCatalog = $catalogByTier[$planTier] ?? VoiceCatalog::publicCatalog($planTier, $preferredProvider);
        }
        $planChars = $usageSnapshot['plan_chars'];
        $giftChars = $usageSnapshot['gift_chars'];
        $planRequests = PlanUsageGuard::planRequests($quotaPlan);
        $requestsUnlimited = $usageSnapshot['requests_unlimited'];
        $usageCharsPercent = $usageSnapshot['chars_percent'];
        $usageRequestsPercent = $usageSnapshot['requests_percent'];

        // Hybrid TTS: resolve dialogue availability per plan
        $dialogueEnabled = $planShowcase || (bool) ($activePlan?->dialogue_enabled ?? false)
            || PlanFeatures::userHas(auth()->user(), $activePlan, PlanFeatures::DIALOGUE);
        $customPromptEnabled = $planShowcase || $this->planAllowsCustomPrompt($activePlan);
        $extensionApiState = $this->extensionApiState(auth()->user());
        $voiceCloningVisible = VoiceCloningAccess::allows(auth()->user(), $activePlan);
        $voiceCloningUpgrade = VoiceCloningAccess::showsUpgrade(auth()->user(), $activePlan);
        if ($voiceCloningVisible) {
            VoiceCloningService::failStalledForUser($userId);
        }
        $voiceCloningVoices = $voiceCloningVisible
            ? ClonedVoice::forUser($userId)->orderByDesc('created_at')->get()
            : collect();
        // Finished voices the member deleted keep their slot for a while.
        $voiceCloningCount = $voiceCloningVisible ? VoiceCloningService::slotsInUse($userId) : 0;
        $voiceCloningDeletedNote = $voiceCloningVisible ? VoiceCloningService::deletedVoiceNote($userId) : '';
        $voiceCloningMaxVoices = VoiceCloningService::maxVoicesForViewer(auth()->user(), $activePlan);
        $voiceCloningEnabledGlobally = VoiceCloningService::isEnabled();
        $voiceCloningPlanEnabled = VoiceCloningService::planAllows($activePlan);

        // The Developer API section: keys and usage for plans with the API, which plans have it for other paying members.
        $developerApi = null;
        try {
            if (\App\Services\Api\ApiAccountSummary::shownFor($activePlan)) {
                $developerApi = app(\App\Services\Api\ApiAccountSummary::class)->for($request->user());
            }
        } catch (\Throwable) {
            // Before the developer API migration has run on a server.
        }

        $page = view('dashboard', [
            'developerApi' => $developerApi,
            'usage' => $usage,
            'usageSnapshot' => $usageSnapshot,
            'packOffer' => $packOffer,
            'planShowcase' => $planShowcase,
            // The plan's own pricing-page lines; "credit card not needed" means nothing once signed in.
            'planLines' => array_values(array_filter(
                PlanPricingLines::forPlan($activePlan ?? new Plan, app()->getLocale()),
                fn (array $line): bool => $line['label'] !== __('ui.pricing.lines.label_card_needed'),
            )),
            'jobs' => $jobs,
            'stats' => $stats,
            'activePlan' => $activePlan,
            'planTier' => $planTier,
            'planTierLabel' => VoiceTier::label($planTier),
            'planChars' => $planChars,
            'giftChars' => $giftChars,
            'maxCharsPerRequest' => PlanUsageGuard::maxCharsPerRequest($activePlan),
            'planRequests' => $requestsUnlimited ? null : $planRequests,
            'requestsUnlimited' => $requestsUnlimited,
            'usageCharsPercent' => $usageCharsPercent,
            'usageRequestsPercent' => $usageRequestsPercent,
            'voiceCatalog' => $tierCatalog,
            'voiceNameMap' => $voiceNameMap,
            'defaultVoiceAr' => VoiceCatalog::defaultForLanguageAndProvider('ar', $planTier, $preferredProvider),
            'defaultVoiceEn' => VoiceCatalog::defaultForLanguageAndProvider('en', $planTier, $preferredProvider),
            'studioPerformanceStyles' => TtsStudioConfig::performanceStyleCatalog(),
            'studioEmotions' => TtsStudioConfig::emotionCatalog(),
            'studioDialectsByLanguage' => TtsStudioConfig::dialectCatalogByLanguage(),
            'studioDefaultsByLanguage' => [
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
            // Options the plan lacks stay visible, dimmed, with an upgrade button.
            'featureLocks' => $this->featureLocks($activePlan, $planTier, $planShowcase, $dialogueEnabled),
            // Hybrid TTS studio data
            'dialogueEnabled' => $dialogueEnabled,
            'customPromptEnabled' => $customPromptEnabled,
            'ttsProviderPolicy' => $activePlan?->tts_provider_policy ?? 'default',
            'extensionApiState' => $extensionApiState,
            'reusePreset' => $reusePreset,
            'demoHistoryJobs' => $demoHistoryJobs,
            // Cloned voices for voice selection dropdown
            'clonedVoices' => $voiceCloningVisible
                ? $voiceCloningVoices->filter(fn (ClonedVoice $v) => $v->isReady())->map(fn (ClonedVoice $v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'language_code' => $v->language_code,
                    'key' => 'cloned_'.$v->id,
                ])->values()->all()
                : [],
            'voiceCloningVisible' => $voiceCloningVisible,
            'voiceCloningUpgrade' => $voiceCloningUpgrade,
            'voiceCloningEnabledGlobally' => $voiceCloningEnabledGlobally,
            'voiceCloningPlanEnabled' => $voiceCloningPlanEnabled,
            'voiceCloningList' => $voiceCloningVoices,
            'voiceCloningCount' => $voiceCloningCount,
            'voiceCloningDeletedNote' => $voiceCloningDeletedNote,
            'voiceCloningMaxVoices' => $voiceCloningMaxVoices,
            'voiceCloningMinDuration' => VoiceCloningService::minSampleDuration(),
            'voiceCloningMaxDuration' => VoiceCloningService::maxSampleDuration(),
            'voiceCloningMinConsent' => VoiceCloningService::minConsentDuration(),
            'voiceCloningConsentScripts' => VoiceCloningService::consentScriptsByLocale(),
            'voiceCloningMaxUploadMb' => VoiceCloningService::maxUploadSizeMb(),
        ]);

        // A new developer API key is on this page once: the browser must not keep a copy.
        return session()->has('new_api_key_plain')
            ? response($page)->header('Cache-Control', 'no-store, private')
            : $page;
    }

    public function queue(Request $request)
    {
        $userId = (int) auth()->id();
        $activePlan = $this->planAccessService->resolveAccessPlanForUserId($userId);
        $quotaPlan = $this->planAccessService->resolveQuotaPlanForUserId($userId, $activePlan);
        $billToPack = CharPackService::billsToPack($quotaPlan);
        $planTier = $this->planAccessService->resolveTierForPlan($activePlan);
        $maxChars = PlanUsageGuard::maxCharsPerRequest($quotaPlan);
        $dialogueEnabled = (bool) ($activePlan?->dialogue_enabled ?? false)
            || PlanFeatures::userHas($request->user(), $activePlan, PlanFeatures::DIALOGUE);
        $requestedTier = VoiceTier::normalize((string) ($request->input('voice_tier') ?? $planTier));

        if (VoiceTier::isHigherThan($requestedTier, $planTier)) {
            return $this->rejectTierAccess($request);
        }

        // Style and emotion left on "Default" get the site's defaults for the language.
        if (VoiceTier::normalize($requestedTier) === VoiceTier::HD) {
            $request->merge(TtsStudioConfig::withDefaultChoices($request->only(['performance_style', 'emotion']), $request->input('target_language')));
        }
        $this->ensureRequiredStudioSelections($requestedTier, [
            'performance_style' => $request->input('performance_style'),
            'emotion' => $request->input('emotion'),
        ]);
        $performanceStyleValues = TtsStudioConfig::performanceStyleValues();
        $emotionValues = TtsStudioConfig::emotionValues();

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string', 'max:50000', 'required_without:text', new DoesNotContainProfanity(app(ProfanityFilter::class))],
            'text' => ['nullable', 'string', 'max:50000', 'required_without:content', new DoesNotContainProfanity(app(ProfanityFilter::class))],
            'target_language' => ['required', 'in:'.implode(',', SpeechLanguages::codes())],
            'translate' => ['nullable', 'boolean'],
            'voice_key' => ['nullable', 'string', 'max:60'],
            'voice_tier' => ['nullable', 'in:standard,hd'],
            // Studio parameters
            'dialect' => ['nullable', 'string', 'max:10'],
            'performance_style' => ['nullable', 'string', 'in:'.implode(',', $performanceStyleValues)],
            'style_prompt' => ['nullable', 'string', 'max:2000'],
            'emotion' => ['nullable', 'string', 'in:'.implode(',', $emotionValues)],
            'speed' => ['nullable', 'numeric', 'min:0.5', 'max:2.0'],
            'pitch' => ['nullable', 'numeric', 'min:-10', 'max:10'],
            'audio_effect' => ['nullable', 'string', 'in:'.implode(',', AudioEffects::keys())],
        ] + DialogueSpeakers::rules(), [
            'performance_style.in' => __('ui.backend.performance_style_invalid'),
            'emotion.in' => __('ui.backend.emotion_invalid'),
        ]);

        // Moods and sounds go with words: a script of tags alone has nothing to speak.
        if (! \App\Support\GeminiVocalTags::hasWords(trim((string) ($validated['content'] ?? $validated['text'] ?? '')))) {
            throw ValidationException::withMessages(['content' => __('ui.backend.dashboard_text_needs_words')]);
        }

        $requestedTier = VoiceTier::normalize((string) ($validated['voice_tier'] ?? $planTier));
        if (SpeechLanguages::premiumOnly($validated['target_language']) && $requestedTier !== VoiceTier::HD) {
            throw ValidationException::withMessages([
                'target_language' => __('ui.backend.dashboard_language_not_available_for_tier'),
            ]);
        }

        if ($dialogueEnabled && ($validated['is_dialogue'] ?? false)) {
            DialogueSpeakers::ensureUsable($requestedTier, (array) ($validated['dialogue_speakers'] ?? []), (string) ($validated['content'] ?? $validated['text'] ?? ''));
        }

        // Check if user selected a cloned voice (key format: cloned_{id})
        $voiceKey = $validated['voice_key'] ?? null;
        $clonedVoiceId = null;
        if ($voiceKey && str_starts_with($voiceKey, 'cloned_')) {
            if (! VoiceCloningAccess::allows($request->user(), $activePlan)) {
                throw ValidationException::withMessages([
                    'voice_key' => __('ui.backend.dashboard_speaker_not_available'),
                ]);
            }

            $clonedVoiceId = (int) str_replace('cloned_', '', $voiceKey);
            $clonedVoice = ClonedVoice::where('id', $clonedVoiceId)
                ->where('user_id', $userId)
                ->ready()
                ->first();
            if (! $clonedVoice) {
                throw ValidationException::withMessages([
                    'voice_key' => __('ui.voice_cloning.processing_failed_generic'),
                ]);
            }
        }

        $voiceName = $clonedVoiceId
            ? ('cloned_'.$clonedVoiceId)
            : $this->normalizeVoiceSelection(
                $validated['target_language'],
                $voiceKey,
                $requestedTier,
            );
        $voiceProvider = $clonedVoiceId ? 'gemini' : VoiceCatalog::providerForName($voiceName);
        $studioOptions = $this->sanitizeStudioOptionsForTier($requestedTier, [
            'dialect' => $validated['dialect'] ?? null,
            'performance_style' => $validated['performance_style'] ?? null,
            'style_prompt' => $validated['style_prompt'] ?? null,
            'emotion' => $validated['emotion'] ?? 'neutral',
            'speed' => $validated['speed'] ?? null,
            'pitch' => $validated['pitch'] ?? null,
        ], $activePlan);

        if (SpeechLanguages::premiumOnly($validated['target_language'])) {
            // These languages have no dialect or accent list.
            $studioOptions['dialect'] = null;
        }

        $text = trim((string) ($validated['content'] ?? $validated['text'] ?? ''));
        $sourceLanguage = SpeechLanguages::detect($text);
        $estimate = UsageEstimator::estimate($text);
        if ($estimate['chars'] > $maxChars) {
            throw ValidationException::withMessages([
                'content' => __('ui.backend.dashboard_request_limit', ['max' => $maxChars]),
            ]);
        }

        $counter = CreditPeriod::counter($userId);

        PlanUsageGuard::assertWithinDailyLimit($quotaPlan, $userId);

        if ($billToPack) {
            // Takes the pack characters now; given back below if the generation fails.
            app(CharPackService::class)->reserve($userId, $estimate['credits']);
        } else {
            PlanUsageGuard::assertWithinLimits($quotaPlan, $counter, $estimate);
        }

        try {
            $recorded = $this->audioGenerationRecorder->createForUser(
                $userId,
                [
                    'source_type' => 'message',
                    'title' => $validated['title'] ?? null,
                    'content' => $text,
                    'language' => $sourceLanguage,
                    'status' => 'processing',
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
                    'status' => 'processing',
                    'dialect' => $studioOptions['dialect'],
                    'performance_style' => $studioOptions['performance_style'],
                    'style_prompt' => $studioOptions['style_prompt'],
                    'emotion' => $studioOptions['emotion'],
                    'speed' => $studioOptions['speed'],
                    'pitch' => $studioOptions['pitch'],
                    // Only on plans with sound environments (or a member given them for a while), and only when this server can add them.
                    'audio_effect' => PlanFeatures::userHas($request->user(), $activePlan, PlanFeatures::EFFECTS) && AudioEffects::available()
                        ? ($validated['audio_effect'] ?? null)
                        : null,
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
                    'usage_period' => $counter->period_ym,
                ],
            );
        } catch (\Throwable $e) {
            if ($billToPack) {
                app(CharPackService::class)->release($userId, $estimate['credits']);
            }
            throw $e;
        }

        $job = $recorded['job'];

        // Reserve usage at queue time; GenerateAudioJob reconciles on success/final failure.
        if (! $billToPack) {
            $counter->increment('total_requests');
            $counter->increment('total_chars', $estimate['credits']);
            $counter->addSeconds($estimate['seconds']);
            app(TrialUsageRecorder::class)->add($userId, $estimate);
        }

        try {
            // Dashboard flow is intentionally synchronous for immediate UX.
            GenerateAudioJob::dispatchSync($job->id);
        } catch (\Throwable) {
            // Job failed — status will be checked below.
        }

        $job->refresh();
        $counter->refresh();

        // Always refund usage when the job actually failed,
        // regardless of whether the exception propagated.
        if ($job->status === 'failed') {
            if ($billToPack) {
                app(CharPackService::class)->release($userId, $estimate['credits']);
            } else {
                $this->releaseReservedUsage($counter, $estimate);
            }
            $counter->refresh();
        }

        $baseQuery = $this->userHistoryQuery($userId);
        $stats = [
            'processing' => (clone $baseQuery)->whereIn('status', ['queued', 'processing'])->count(),
            'done' => (clone $baseQuery)->where('status', 'done')->count(),
            'failed' => (clone $baseQuery)->where('status', 'failed')->count(),
            'total' => (clone $baseQuery)->count(),
        ];

        $usageSnapshot = UsageSnapshot::for($userId, $quotaPlan, $counter);
        $voiceNameMap = $this->voiceNameMapForUser($userId);

        if ($request->expectsJson()) {
            $isDone = $job->status === 'done';
            $statusCode = $isDone ? 200 : 422;

            return response()->json([
                'ok' => $isDone,
                'message' => $isDone
                    ? __('ui.backend.dashboard_voice_generated')
                    : ($job->error_message ?: __('ui.backend.dashboard_voice_generation_failed_try_again')),
                'job' => $this->buildJobPayload($job, $voiceNameMap),
                'usage' => $usageSnapshot,
                'stats' => $stats,
            ], $statusCode);
        }

        $isDone = $job->status === 'done';

        return redirect()->route('dashboard')->with(
            $isDone ? 'ok' : 'error',
            $isDone ? __('ui.backend.dashboard_voice_generated') : __('ui.backend.dashboard_voice_generation_failed_check_jobs')
        );
    }

    public function destroy(Request $request, AudioGeneration $job)
    {
        $userId = (int) auth()->id();
        if ((int) $job->user_id !== $userId) {
            abort(404);
        }

        // Still being made: its credits are only given back once it finishes or fails, so
        // deleting it now would lose them. One stuck for long can go (not a developer API
        // one: those are failed and refunded on their own).
        if (in_array($job->status, ['queued', 'processing'], true) && ($job->api_key_id || $job->updated_at?->gt(now()->subMinutes(30)))) {
            $message = __('ui.backend.dashboard_voice_delete_in_progress');

            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $message], 409)
                : redirect()->route('dashboard')->with('error', $message);
        }

        $deletedId = (int) $job->id;
        $job->delete();

        $baseQuery = $this->userHistoryQuery($userId);
        $stats = [
            'processing' => (clone $baseQuery)->whereIn('status', ['queued', 'processing'])->count(),
            'done' => (clone $baseQuery)->where('status', 'done')->count(),
            'failed' => (clone $baseQuery)->where('status', 'failed')->count(),
            'total' => (clone $baseQuery)->count(),
        ];
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'deleted_id' => $deletedId,
                'message' => __('ui.backend.dashboard_voice_deleted'),
                'stats' => $stats,
            ]);
        }

        return redirect()->route('dashboard')->with('ok', __('ui.backend.dashboard_voice_deleted'));
    }

    public function destroyDemo(Request $request, DemoVoiceGeneration $generation)
    {
        $userId = (int) auth()->id();
        if ((int) $generation->user_id !== $userId) {
            abort(404);
        }

        // The row stays: the demo allowance adds up the member's demo rows, so
        // deleting one must not give its characters back. Without its audio it
        // drops out of the member's history (which lists only rows with audio),
        // and the file goes too unless another row still plays it.
        $deletedId = (int) $generation->id;
        $audioPath = trim((string) $generation->audio_path);
        $metadata = is_array($generation->metadata) ? $generation->metadata : [];
        $generation->forceFill([
            'audio_path' => null,
            'metadata' => $metadata + ['deleted_by_member_at' => now()->toIso8601String()],
        ])->save();

        if ($audioPath !== ''
            && ! DemoVoiceGeneration::query()->where('audio_path', $audioPath)->exists()
            && ! AudioGeneration::query()->where('audio_path', $audioPath)->exists()) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($audioPath);
        }

        $baseQuery = $this->userHistoryQuery($userId);
        $stats = [
            'processing' => (clone $baseQuery)->whereIn('status', ['queued', 'processing'])->count(),
            'done' => (clone $baseQuery)->where('status', 'done')->count(),
            'failed' => (clone $baseQuery)->where('status', 'failed')->count(),
            'total' => (clone $baseQuery)->count(),
        ];
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'deleted_id' => $deletedId,
                'message' => __('ui.backend.dashboard_voice_deleted'),
                'stats' => $stats,
            ]);
        }

        return redirect()->route('dashboard')->with('ok', __('ui.backend.dashboard_voice_deleted'));
    }

    private function normalizeVoiceSelection(string $language, ?string $voiceKey, string $tier): string
    {
        $voiceKey = trim((string) $voiceKey);
        $preferredProvider = VoiceCatalog::preferredProviderForTier($tier);

        if ($voiceKey === '') {
            $defaultVoice = VoiceCatalog::defaultForLanguageAndProvider($language, $tier, $preferredProvider);
            if ($defaultVoice === '') {
                throw ValidationException::withMessages([
                    'target_language' => __('ui.backend.dashboard_language_not_available_for_tier'),
                ]);
            }

            return $defaultVoice;
        }

        $voiceName = VoiceCatalog::nameFromKeyForLanguageAndProvider($language, $voiceKey, $tier, $preferredProvider);
        if (! $voiceName) {
            throw ValidationException::withMessages([
                'voice_key' => __('ui.backend.dashboard_speaker_not_available'),
            ]);
        }

        return $voiceName;
    }

    private function rejectTierAccess(Request $request)
    {
        $message = __('ui.backend.dashboard_upgrade_for_quality');

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'message' => $message,
            ], 403);
        }

        abort(403, $message);
    }

    private function extensionApiState(?object $user): array
    {
        $last4 = trim((string) ($user?->api_key_last4 ?? ''));

        return [
            'has_api_key' => (bool) ($user?->api_key_hash ?? false),
            'masked_key' => $last4 !== '' ? 'lk_live_****'.$last4 : null,
            'api_key_created_human' => $user?->api_key_created_at?->diffForHumans(),
        ];
    }

    /**
     * What members without a paid plan are offered: a one-time pack when one
     * is on sale, and the cheapest monthly plans. Null for members on a plan
     * or in a trial.
     *
     * @param  array<string, mixed>  $usageSnapshot
     * @return array{state:string, options:list<array<string, mixed>>}|null
     */
    private function packOffer(Request $request, array $usageSnapshot): ?array
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        $current = $this->planAccessService->resolveActivePlanForUserId((int) $user->id);
        if ((int) ($current?->price_cents ?? 0) > 0 || $this->planAccessService->userHasUnpaidTrialWindow((int) $user->id)) {
            return null;
        }

        $pricing = app(LocalizedPricingService::class);
        $country = $pricing->resolveCountryContext($request);
        $locale = app()->getLocale();
        $price = static function (Plan $plan) use ($pricing, $country, $locale): string {
            $method = $pricing->preferredManualMethodForPlan($plan, $country);
            $quote = ($method ? $pricing->manualQuoteForPlan($plan, 'monthly', $country, $method, $locale) : null)
                ?? $pricing->convertedQuoteFromUsd((int) $plan->getEffectivePriceCents(), 'monthly', $country, 'stripe', $locale);

            return (string) $quote['amount_formatted'];
        };

        $state = match (true) {
            $usageSnapshot['mode'] === 'pack' => 'has_pack',
            $usageSnapshot['remaining_chars'] <= 0 => 'no_chars',
            default => 'free_chars',
        };

        $perMonth = __('ui.pricing.per_month_short');
        $card = static function (Plan $plan, string $period, string $headline, string $url, string $cta) use ($price, $locale): array {
            $lines = PlanPricingLines::forPlan($plan, $locale);

            return [
                'name' => $plan->getLocalizedName($locale),
                'description' => $plan->getLocalizedDescription($locale),
                'price' => $price($plan),
                'period' => $period,
                'headline' => $headline,
                'lines' => array_slice($lines, 0, 5),
                'more_lines' => max(0, count($lines) - 5),
                'cta' => $plan->getLocalizedButtonText($locale) ?: $cta,
                'url' => $url,
                'featured' => (bool) $plan->is_featured,
            ];
        };

        $options = [];
        $packs = app(CharPackService::class);
        $pack = $packs->offeredPack();
        if ($pack && $packs->canBuy($user, $pack)) {
            $options[] = $card(
                $pack,
                __('ui.pack.one_time'),
                __('ui.pack.headline', ['count' => number_format((int) $pack->monthly_chars), 'days' => $pack->packDays()]),
                route('billing.pack'),
                $state === 'has_pack' ? __('ui.pack.offer_get_more') : __('ui.pack.offer_get_pack'),
            );
        }

        $plans = Plan::query()
            ->subscriptionPlans()
            ->where('is_active', true)
            ->where('price_cents', '>', 0)
            ->orderBy('price_cents')
            ->limit($options === [] ? 2 : 1)
            ->get();
        foreach ($plans as $plan) {
            $options[] = $card(
                $plan,
                $perMonth,
                number_format((int) $plan->monthly_chars).' '.__('ui.pricing.characters_per_month'),
                route('billing.page', ['plan' => $plan->slug]),
                __('ui.pack.offer_choose_plan', ['name' => $plan->getLocalizedName($locale)]),
            );
        }

        if ($options === []) {
            return null;
        }

        return [
            'state' => $state,
            'options' => $options,
        ];
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

    private function buildJobPayload(AudioGeneration $job, array $voiceNameMap): array
    {
        $job->loadMissing('textItem:id,title');

        return [
            'id' => (int) $job->id,
            'display_number' => $job->displayNumber(),
            'title' => (string) ($job->textItem?->title ?? ''),
            'text_excerpt' => (string) Str::limit(trim((string) preg_replace('/\s+/u', ' ', (string) $job->input_text)), 42, ''),
            'status' => (string) $job->status,
            'source_language' => $job->source_language ?: '-',
            'target_language' => (string) $job->target_language,
            'voice_label' => $this->localizedVoiceLabel($job->voice_name, $voiceNameMap),
            'voice_tier' => VoiceTier::label((string) ($job->voice_tier ?: VoiceTier::STANDARD)),
            'translate' => (bool) $job->translate,
            'duration_seconds' => $job->duration_seconds ? (int) $job->duration_seconds : null,
            'audio_url' => AudioUrl::fromPath($job->audio_path),
            'error_message' => $job->error_message,
            'created_human' => $job->created_at?->diffForHumans(),
            'created_at_ts' => $job->created_at?->getTimestamp(),
            'can_reuse_settings' => $job->hasReusablePremiumSettings(),
            'reuse_url' => $job->hasReusablePremiumSettings()
                ? route('dashboard', ['reuse' => $job->displayNumber()]).'#generateVoiceSection'
                : null,
            'is_demo_history' => false,
            'row_key' => 'audio:'.$job->id,
        ];
    }

    /**
     * Catalog labels plus the owner's cloned voices, keyed the same way
     * a generation stores them (`cloned_{id}`).
     *
     * @return array<string, array<string, mixed>>
     */
    private function voiceNameMapForUser(int $userId): array
    {
        $map = VoiceCatalog::mapByName();

        ClonedVoice::withTrashed()
            ->where('user_id', $userId)
            ->get(['id', 'name'])
            ->each(function (ClonedVoice $voice) use (&$map): void {
                $name = trim((string) $voice->name);
                if ($name === '') {
                    return;
                }

                $key = 'cloned_'.$voice->id;
                $map[$key] = [
                    'key' => $key,
                    'label' => $name,
                    'label_ar' => $name,
                ];
            });

        return $map;
    }

    private function localizedVoiceLabel(?string $voiceName, array $voiceNameMap): string
    {
        $entry = $voiceNameMap[(string) $voiceName] ?? null;
        if (! is_array($entry)) {
            return __('ui.dashboard.default_speaker');
        }

        $isArabicLocale = app()->getLocale() === 'ar';
        $label = $isArabicLocale
            ? ($entry['label_ar'] ?? $entry['label'] ?? __('ui.dashboard.default_speaker'))
            : ($entry['label'] ?? __('ui.dashboard.default_speaker'));

        return ltrim(trim((string) $label), '+');
    }

    /**
     * @param  array{performance_style:mixed,emotion:mixed}  $input
     */
    private function ensureRequiredStudioSelections(string $tier, array $input): void
    {
        if (VoiceTier::normalize($tier) !== VoiceTier::HD) {
            return;
        }

        $errors = [];

        if (! is_string($input['performance_style'] ?? null) || trim((string) $input['performance_style']) === '') {
            $errors['performance_style'] = [__('ui.backend.performance_style_required')];
        }

        if (! is_string($input['emotion'] ?? null) || trim((string) $input['emotion']) === '') {
            $errors['emotion'] = [__('ui.backend.emotion_required')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array{dialect:mixed,performance_style:mixed,style_prompt:mixed,emotion:mixed,speed:mixed,pitch:mixed}  $options
     * @return array{dialect:?string,performance_style:string,style_prompt:?string,emotion:string,speed:float,pitch:float}
     */
    private function sanitizeStudioOptionsForTier(string $tier, array $options, ?Plan $plan = null): array
    {
        $normalizedTier = VoiceTier::normalize($tier);

        // Standard tier: no performance controls — use defaults only.
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

        // Backward compatibility for old clients that used emotion for style.
        if (
            $performanceStyle === ''
            && in_array($emotion, TtsStudioConfig::performanceStyleValues(), true)
            && ! in_array($emotion, TtsStudioConfig::emotionValues(), true)
        ) {
            $performanceStyle = $emotion;
            $emotion = 'neutral';
        }

        // Style prompt is only allowed if the plan permits it (custom_prompt_enabled)
        $stylePrompt = null;
        if ($this->planAllowsCustomPrompt($plan)) {
            $stylePrompt = isset($options['style_prompt']) && is_string($options['style_prompt'])
                ? trim($options['style_prompt']) ?: null
                : null;
        }

        // Premium tier: full performance controls.
        return [
            'dialect' => isset($options['dialect']) && is_string($options['dialect']) ? trim($options['dialect']) ?: null : null,
            'performance_style' => $performanceStyle !== '' ? $performanceStyle : 'neutral',
            'style_prompt' => $stylePrompt,
            'emotion' => $emotion !== '' ? $emotion : 'neutral',
            'speed' => isset($options['speed']) ? (float) $options['speed'] : 1.0,
            'pitch' => isset($options['pitch']) ? (float) $options['pitch'] : 0.0,
        ];
    }

    private function userHistoryQuery(int $userId)
    {
        return AudioGeneration::query()
            ->where('user_id', $userId)
            ->where(function ($query): void {
                $query->whereDoesntHave('textItem')
                    ->orWhereHas('textItem', static function ($textItemQuery): void {
                        $textItemQuery->where('source_type', '<>', 'demo');
                    });
            });
    }

    private function isPaidPlan(?Plan $plan): bool
    {
        if (! $plan) {
            return false;
        }

        $slug = strtolower(trim((string) ($plan->slug ?? '')));
        $priceCents = (int) ($plan->price_cents ?? 0);

        return $priceCents > 0 || ($slug !== '' && $slug !== 'free');
    }

    /**
     * @return array<string, array{url: string, plan: ?string}|null>
     */
    private function featureLocks(?Plan $plan, string $planTier, bool $showcase, bool $dialogueEnabled): array
    {
        $arabic = app()->getLocale() === 'ar';
        $premium = $planTier === VoiceTier::HD;

        return [
            PlanFeatures::STUDIO => PlanFeatures::lock(PlanFeatures::STUDIO, $premium, $arabic),
            PlanFeatures::EFFECTS => PlanFeatures::lock(PlanFeatures::EFFECTS, $showcase || PlanFeatures::userHas(auth()->user(), $plan, PlanFeatures::EFFECTS), $arabic),
            PlanFeatures::DIALOGUE => PlanFeatures::lock(PlanFeatures::DIALOGUE, $premium && $dialogueEnabled, $arabic),
        ];
    }

    private function planAllowsCustomPrompt(?Plan $plan): bool
    {
        return (bool) $plan?->allowsCustomPrompt();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDemoHistoryJobs(int $userId, ?Plan $activePlan, array $voiceNameMap): array
    {
        if (! $this->isPaidPlan($activePlan)) {
            return [];
        }

        $sequenceByDemoId = [];
        $demoIds = DemoVoiceGeneration::query()
            ->where('user_id', $userId)
            ->whereNotNull('audio_path')
            ->where('audio_path', '<>', '')
            ->orderBy('id')
            ->pluck('id')
            ->all();
        foreach ($demoIds as $index => $id) {
            $sequenceByDemoId[(int) $id] = $index + 1;
        }

        $demoRows = DemoVoiceGeneration::query()
            ->where('user_id', $userId)
            ->whereNotNull('audio_path')
            ->where('audio_path', '<>', '')
            ->latest()
            ->limit(250)
            ->get();

        return $demoRows->map(function (DemoVoiceGeneration $demo) use ($voiceNameMap, $sequenceByDemoId): array {
            $rawText = trim((string) ($demo->input_text ?: $demo->speech_text ?: ''));
            $textExcerpt = Str::limit(trim((string) preg_replace('/\s+/u', ' ', $rawText)), 42, '');
            $voiceName = trim((string) ($demo->voice_name ?? ''));
            $voiceLabel = $voiceName !== ''
                ? $this->localizedVoiceLabel($voiceName, $voiceNameMap)
                : ltrim(trim((string) ($demo->voice_label ?: __('ui.dashboard.default_speaker'))), '+');
            $displaySequence = (int) ($sequenceByDemoId[(int) $demo->id] ?? (int) $demo->id);
            $demoReusableSettings = $this->reusableSettingsFromDemo($demo);
            $canReuseSettings = $demoReusableSettings !== null;

            return [
                'id' => (int) $demo->id,
                'row_key' => 'demo:'.$demo->id,
                'display_number' => 'D-'.$displaySequence,
                'title' => $textExcerpt !== ''
                    ? $textExcerpt
                    : 'From Demo',
                'text_excerpt' => $textExcerpt,
                'status' => 'done',
                'source_language' => (string) ($demo->source_language ?: '-'),
                'target_language' => (string) ($demo->target_language ?: ($demo->source_language ?: 'ar')),
                'voice_label' => $voiceLabel,
                'voice_tier' => VoiceTier::label((string) ($demo->voice_tier ?: VoiceTier::STANDARD)),
                'translate' => (bool) $demo->translate,
                'duration_seconds' => $demo->duration_seconds ? (int) $demo->duration_seconds : null,
                'audio_url' => AudioUrl::fromPath((string) $demo->audio_path),
                'error_message' => null,
                'created_human' => $demo->created_at?->diffForHumans(),
                'created_at_ts' => $demo->created_at?->getTimestamp(),
                'can_reuse_settings' => $canReuseSettings,
                'reuse_url' => $canReuseSettings
                    ? route('dashboard', ['reuse_demo' => (int) $demo->id]).'#generateVoiceSection'
                    : null,
                'is_demo_history' => true,
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reusableSettingsFromDemo(DemoVoiceGeneration $demo): ?array
    {
        $targetLanguage = trim((string) ($demo->target_language ?: ($demo->source_language ?: 'ar')));
        $voiceTier = VoiceTier::normalize((string) ($demo->voice_tier ?: VoiceTier::STANDARD));
        $voiceName = trim((string) ($demo->voice_name ?? ''));
        $voiceMeta = $voiceName !== '' ? VoiceCatalog::voiceMeta($voiceName) : null;
        $voiceKey = is_array($voiceMeta) ? trim((string) ($voiceMeta['key'] ?? '')) : '';

        $settings = [
            'voice_tier' => $voiceTier,
            'voice_name' => $voiceName !== '' ? $voiceName : null,
            'voice_key' => $voiceKey !== '' ? $voiceKey : null,
            'target_language' => $targetLanguage !== '' ? $targetLanguage : 'ar',
            'translate' => (bool) $demo->translate,
            'dialect' => trim((string) ($demo->dialect ?? '')) ?: null,
            'performance_style' => trim((string) ($demo->performance_style ?? '')) ?: null,
            'style_prompt' => trim((string) ($demo->style_prompt ?? '')) ?: null,
            'emotion' => trim((string) ($demo->emotion ?? '')) ?: null,
            'speed' => $demo->speed !== null ? (float) $demo->speed : null,
            'pitch' => $demo->pitch !== null ? (float) $demo->pitch : null,
            'is_dialogue' => false,
        ];

        $normalized = array_filter($settings, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildDemoReusePreset(?DemoVoiceGeneration $demo): ?array
    {
        if (! $demo) {
            return null;
        }

        $content = trim((string) ($demo->input_text ?: $demo->speech_text ?: ''));
        if ($content === '') {
            return null;
        }

        $settings = $this->reusableSettingsFromDemo($demo);
        if ($settings === null) {
            return null;
        }

        $displaySequence = (int) DemoVoiceGeneration::query()
            ->where('user_id', (int) $demo->user_id)
            ->whereNotNull('audio_path')
            ->where('audio_path', '<>', '')
            ->where('id', '<=', (int) $demo->id)
            ->count();

        return array_merge(
            $settings,
            [
                'job_id' => 'demo:'.$demo->id,
                'display_number' => 'D-'.$displaySequence,
                'title' => (string) Str::limit(trim((string) preg_replace('/\s+/u', ' ', $content)), 90, ''),
                'content' => $content,
            ],
        );
    }
}
