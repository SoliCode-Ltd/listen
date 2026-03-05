<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAudioJob;
use App\Models\AudioGeneration;
use App\Models\TextItem;
use App\Models\UsageCounter;
use App\Services\PlanAccessService;
use App\Support\AudioUrl;
use App\Support\PlanUsageGuard;
use App\Support\UsageEstimator;
use App\Support\VoiceCatalog;
use App\Support\VoiceTier;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
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
    ];

    public function __construct(
        private readonly PlanAccessService $planAccessService,
    ) {}

    public function index()
    {
        $userId = (int) auth()->id();
        $period = now()->format('Y-m');

        $usage = UsageCounter::firstOrCreate(
            ['user_id' => $userId, 'period_ym' => $period],
            ['total_minutes' => 0, 'total_chars' => 0, 'total_requests' => 0]
        );

        $baseQuery = AudioGeneration::query()->where('user_id', $userId);

        $jobs = (clone $baseQuery)
            ->with(['textItem:id,title'])
            ->latest()
            ->get();

        $stats = [
            'processing' => (clone $baseQuery)->whereIn('status', ['queued', 'processing'])->count(),
            'done' => (clone $baseQuery)->where('status', 'done')->count(),
            'failed' => (clone $baseQuery)->where('status', 'failed')->count(),
            'total' => (clone $baseQuery)->count(),
        ];

        $activePlan = $this->planAccessService->resolveActivePlanForUserId($userId);
        $planTier = $this->planAccessService->resolveTierForPlan($activePlan);
        $preferredProvider = VoiceCatalog::preferredProviderForTier($planTier);
        $catalogByTier = VoiceCatalog::publicCatalogByTier();
        $tierCatalog = $catalogByTier[$planTier] ?? VoiceCatalog::publicCatalog($planTier, $preferredProvider);
        $planChars = PlanUsageGuard::planChars($activePlan);
        $planRequests = PlanUsageGuard::planRequests($activePlan);
        $requestsUnlimited = PlanUsageGuard::hasUnlimitedRequests($activePlan);
        $usageCharsPercent = min(100, (int) round(($usage->total_chars / $planChars) * 100));
        $usageRequestsPercent = $requestsUnlimited
            ? 0
            : min(100, (int) round(($usage->total_requests / max(1, $planRequests)) * 100));

        // Hybrid TTS: resolve dialogue availability per plan
        $dialogueEnabled = (bool) ($activePlan?->dialogue_enabled ?? false);

        return view('dashboard', [
            'usage' => $usage,
            'jobs' => $jobs,
            'stats' => $stats,
            'activePlan' => $activePlan,
            'planTier' => $planTier,
            'planTierLabel' => VoiceTier::label($planTier),
            'planChars' => $planChars,
            'maxCharsPerRequest' => PlanUsageGuard::maxCharsPerRequest($activePlan),
            'planRequests' => $requestsUnlimited ? null : $planRequests,
            'requestsUnlimited' => $requestsUnlimited,
            'usageCharsPercent' => $usageCharsPercent,
            'usageRequestsPercent' => $usageRequestsPercent,
            'voiceCatalog' => $tierCatalog,
            'voiceNameMap' => VoiceCatalog::mapByName(),
            'defaultVoiceAr' => VoiceCatalog::defaultForLanguageAndProvider('ar', $planTier, $preferredProvider),
            'defaultVoiceEn' => VoiceCatalog::defaultForLanguageAndProvider('en', $planTier, $preferredProvider),
            // Hybrid TTS studio data
            'dialogueEnabled' => $dialogueEnabled,
            'ttsProviderPolicy' => $activePlan?->tts_provider_policy ?? 'default',
        ]);
    }

    public function queue(Request $request)
    {
        $userId = (int) auth()->id();
        $activePlan = $this->planAccessService->resolveActivePlanForUserId($userId);
        $planTier = $this->planAccessService->resolveTierForPlan($activePlan);
        $maxChars = PlanUsageGuard::maxCharsPerRequest($activePlan);
        $dialogueEnabled = (bool) ($activePlan?->dialogue_enabled ?? false);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:50000'],
            'target_language' => ['required', 'in:ar,en'],
            'translate' => ['nullable', 'boolean'],
            'voice_key' => ['nullable', 'string', 'max:60'],
            'voice_tier' => ['nullable', 'in:standard,hd'],
            // Studio parameters
            'dialect' => ['nullable', 'string', 'max:10'],
            'performance_style' => ['nullable', 'string', 'in:'.implode(',', self::PERFORMANCE_STYLE_VALUES)],
            'emotion' => ['nullable', 'string', 'in:'.implode(',', self::EMOTION_VALUES)],
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
            return $this->rejectTierAccess($request);
        }

        $voiceName = $this->normalizeVoiceSelection(
            $validated['target_language'],
            $validated['voice_key'] ?? null,
            $requestedTier,
        );
        $voiceProvider = VoiceCatalog::providerForName($voiceName);
        $studioOptions = $this->sanitizeStudioOptionsForTier($requestedTier, [
            'dialect' => $validated['dialect'] ?? null,
            'performance_style' => $validated['performance_style'] ?? null,
            'emotion' => $validated['emotion'] ?? 'neutral',
            'speed' => $validated['speed'] ?? null,
            'pitch' => $validated['pitch'] ?? null,
        ]);

        $text = trim($validated['content']);
        $sourceLanguage = preg_match('/[\x{0600}-\x{06FF}]/u', $text) ? 'ar' : 'en';
        $estimate = UsageEstimator::estimate($text);
        if ($estimate['chars'] > $maxChars) {
            throw ValidationException::withMessages([
                'content' => __('ui.backend.dashboard_request_limit', ['max' => $maxChars]),
            ]);
        }

        $period = now()->format('Y-m');
        $counter = UsageCounter::firstOrCreate(
            ['user_id' => $userId, 'period_ym' => $period],
            ['total_minutes' => 0, 'total_chars' => 0, 'total_requests' => 0]
        );

        PlanUsageGuard::assertWithinLimits($activePlan, $counter, $estimate);

        $item = TextItem::create([
            'user_id' => $userId,
            'source_type' => 'message',
            'title' => $validated['title'] ?? null,
            'content' => $text,
            'language' => $sourceLanguage,
            'status' => 'processing',
            'received_at' => now(),
        ]);

        $job = AudioGeneration::create([
            'user_id' => $userId,
            'text_item_id' => $item->id,
            'input_text' => $text,
            'source_language' => $sourceLanguage,
            'target_language' => $validated['target_language'],
            'voice_tier' => $requestedTier,
            'voice_name' => $voiceName,
            'tts_provider' => $voiceProvider,
            'translate' => (bool) ($validated['translate'] ?? false),
            'duration_seconds' => $estimate['seconds'],
            'status' => 'processing',
            // Studio fields
            'dialect' => $studioOptions['dialect'],
            'performance_style' => $studioOptions['performance_style'],
            'emotion' => $studioOptions['emotion'],
            'speed' => $studioOptions['speed'],
            'pitch' => $studioOptions['pitch'],
            // Dialogue fields
            'is_dialogue' => $dialogueEnabled
                && $requestedTier !== VoiceTier::STANDARD
                && ($validated['is_dialogue'] ?? false),
            'dialogue_metadata' => ($dialogueEnabled
                && $requestedTier !== VoiceTier::STANDARD
                && ($validated['is_dialogue'] ?? false)
                && ! empty($validated['dialogue_speakers']))
                ? ['speakers' => $validated['dialogue_speakers']]
                : null,
        ]);

        // Reserve usage at queue time; GenerateAudioJob reconciles on success/final failure.
        $counter->increment('total_requests');
        $counter->increment('total_chars', $estimate['chars']);
        $counter->increment('total_minutes', $estimate['minutes']);

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
            $this->releaseReservedUsage($counter, $estimate);
            $counter->refresh();
        }

        $baseQuery = AudioGeneration::query()->where('user_id', $userId);
        $stats = [
            'processing' => (clone $baseQuery)->whereIn('status', ['queued', 'processing'])->count(),
            'done' => (clone $baseQuery)->where('status', 'done')->count(),
            'failed' => (clone $baseQuery)->where('status', 'failed')->count(),
            'total' => (clone $baseQuery)->count(),
        ];

        $planChars = PlanUsageGuard::planChars($activePlan);
        $planRequests = PlanUsageGuard::planRequests($activePlan);
        $requestsUnlimited = PlanUsageGuard::hasUnlimitedRequests($activePlan);
        $usageCharsPercent = min(100, (int) round(($counter->total_chars / $planChars) * 100));
        $usageRequestsPercent = $requestsUnlimited
            ? 0
            : min(100, (int) round(($counter->total_requests / max(1, $planRequests)) * 100));
        $voiceNameMap = VoiceCatalog::mapByName();

        if ($request->expectsJson()) {
            $isDone = $job->status === 'done';
            $statusCode = $isDone ? 200 : 422;

            return response()->json([
                'ok' => $isDone,
                'message' => $isDone
                    ? __('ui.backend.dashboard_voice_generated')
                    : ($job->error_message ?: __('ui.backend.dashboard_voice_generation_failed_try_again')),
                'job' => $this->buildJobPayload($job, $voiceNameMap),
                'usage' => [
                    'total_chars' => (int) $counter->total_chars,
                    'total_minutes' => (int) $counter->total_minutes,
                    'total_requests' => (int) $counter->total_requests,
                    'plan_chars' => $planChars,
                    'plan_requests' => $requestsUnlimited ? null : $planRequests,
                    'requests_unlimited' => $requestsUnlimited,
                    'chars_percent' => $usageCharsPercent,
                    'requests_percent' => $usageRequestsPercent,
                ],
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

        $deletedId = (int) $job->id;
        $job->delete();

        $baseQuery = AudioGeneration::query()->where('user_id', $userId);
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

    /**
     * @param  array{chars:int,minutes:int}  $estimate
     */
    private function releaseReservedUsage(UsageCounter $counter, array $estimate): void
    {
        $counter->refresh();

        $counter->update([
            'total_requests' => max(0, (int) $counter->total_requests - 1),
            'total_chars' => max(0, (int) $counter->total_chars - (int) $estimate['chars']),
            'total_minutes' => max(0, (int) $counter->total_minutes - (int) $estimate['minutes']),
        ]);
    }

    private function buildJobPayload(AudioGeneration $job, array $voiceNameMap): array
    {
        $job->loadMissing('textItem:id,title');

        return [
            'id' => (int) $job->id,
            'title' => (string) ($job->textItem?->title ?? ''),
            'text_excerpt' => (string) Str::limit(trim((string) preg_replace('/\s+/u', ' ', (string) $job->input_text)), 42, ''),
            'status' => (string) $job->status,
            'source_language' => $job->source_language ?: '-',
            'target_language' => (string) $job->target_language,
            'voice_label' => $voiceNameMap[$job->voice_name]['label'] ?? __('ui.dashboard.default_speaker'),
            'voice_tier' => VoiceTier::label((string) ($job->voice_tier ?: VoiceTier::STANDARD)),
            'translate' => (bool) $job->translate,
            'duration_seconds' => $job->duration_seconds ? (int) $job->duration_seconds : null,
            'audio_url' => AudioUrl::fromPath($job->audio_path),
            'error_message' => $job->error_message,
            'created_human' => $job->created_at?->diffForHumans(),
        ];
    }

    /**
     * @param  array{dialect:mixed,performance_style:mixed,emotion:mixed,speed:mixed,pitch:mixed}  $options
     * @return array{dialect:?string,performance_style:string,emotion:string,speed:float,pitch:float}
     */
    private function sanitizeStudioOptionsForTier(string $tier, array $options): array
    {
        $normalizedTier = VoiceTier::normalize($tier);

        // Standard tier: no performance controls — use defaults only.
        if ($normalizedTier === VoiceTier::STANDARD) {
            return [
                'dialect'  => null,
                'performance_style' => 'neutral',
                'emotion'  => 'neutral',
                'speed'    => 1.0,
                'pitch'    => 0.0,
            ];
        }

        $performanceStyle = isset($options['performance_style']) && is_string($options['performance_style'])
            ? trim($options['performance_style'])
            : '';
        $emotion = isset($options['emotion']) && is_string($options['emotion'])
            ? trim($options['emotion'])
            : '';

        // Backward compatibility for old clients that used emotion for style.
        if ($performanceStyle === '' && in_array($emotion, self::PERFORMANCE_STYLE_VALUES, true) && ! in_array($emotion, self::EMOTION_VALUES, true)) {
            $performanceStyle = $emotion;
            $emotion = 'neutral';
        }

        // HD tier: full performance controls.
        return [
            'dialect' => isset($options['dialect']) && is_string($options['dialect']) ? trim($options['dialect']) ?: null : null,
            'performance_style' => $performanceStyle !== '' ? $performanceStyle : 'neutral',
            'emotion' => $emotion !== '' ? $emotion : 'neutral',
            'speed' => isset($options['speed']) ? (float) $options['speed'] : 1.0,
            'pitch' => isset($options['pitch']) ? (float) $options['pitch'] : 0.0,
        ];
    }
}
