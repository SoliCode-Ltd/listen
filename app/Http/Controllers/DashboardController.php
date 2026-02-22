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
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
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
            ->latest()
            ->limit(20)
            ->get();

        $stats = [
            'processing' => (clone $baseQuery)->whereIn('status', ['queued', 'processing'])->count(),
            'done' => (clone $baseQuery)->where('status', 'done')->count(),
            'failed' => (clone $baseQuery)->where('status', 'failed')->count(),
            'total' => (clone $baseQuery)->count(),
        ];

        $activePlan = $this->planAccessService->resolveActivePlanForUserId($userId);
        $planTier = $this->planAccessService->resolveTierForPlan($activePlan);
        $planChars = PlanUsageGuard::planChars($activePlan);
        $planRequests = PlanUsageGuard::planRequests($activePlan);
        $requestsUnlimited = PlanUsageGuard::hasUnlimitedRequests($activePlan);
        $usageCharsPercent = min(100, (int) round(($usage->total_chars / $planChars) * 100));
        $usageRequestsPercent = $requestsUnlimited
            ? 0
            : min(100, (int) round(($usage->total_requests / max(1, $planRequests)) * 100));

        return view('dashboard', [
            'usage' => $usage,
            'jobs' => $jobs,
            'stats' => $stats,
            'activePlan' => $activePlan,
            'planTier' => $planTier,
            'planTierLabel' => VoiceTier::label($planTier),
            'planChars' => $planChars,
            'planRequests' => $requestsUnlimited ? null : $planRequests,
            'requestsUnlimited' => $requestsUnlimited,
            'usageCharsPercent' => $usageCharsPercent,
            'usageRequestsPercent' => $usageRequestsPercent,
            'voiceCatalog' => VoiceCatalog::publicCatalog($planTier),
            'voiceNameMap' => VoiceCatalog::mapByName(),
            'defaultVoiceAr' => VoiceCatalog::defaultForLanguage('ar', $planTier),
            'defaultVoiceEn' => VoiceCatalog::defaultForLanguage('en', $planTier),
        ]);
    }

    public function queue(Request $request)
    {
        $userId = (int) auth()->id();
        $activePlan = $this->planAccessService->resolveActivePlanForUserId($userId);
        $planTier = $this->planAccessService->resolveTierForPlan($activePlan);
        $maxChars = PlanUsageGuard::maxCharsPerRequest($activePlan);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:50000'],
            'source_type' => ['required', 'in:email,message,file'],
            'target_language' => ['required', 'in:ar,en'],
            'translate' => ['nullable', 'boolean'],
            'voice_key' => ['nullable', 'string', 'max:60'],
            'voice_tier' => ['nullable', 'in:standard,hd,hd_plus'],
        ]);

        $requestedTier = VoiceTier::normalize((string) ($validated['voice_tier'] ?? $planTier));
        if (VoiceTier::isHigherThan($requestedTier, $planTier)) {
            return $this->rejectTierAccess($request);
        }

        $voiceName = $this->normalizeVoiceSelection(
            $validated['target_language'],
            $validated['voice_key'] ?? null,
            $planTier,
        );

        $text = trim($validated['content']);
        $sourceLanguage = preg_match('/[\x{0600}-\x{06FF}]/u', $text) ? 'ar' : 'en';
        $estimate = UsageEstimator::estimate($text);
        if ($estimate['chars'] > $maxChars) {
            throw ValidationException::withMessages([
                'content' => "Request exceeds plan per-request limit ({$maxChars} non-space characters).",
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
            'source_type' => $validated['source_type'],
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
            'voice_tier' => $planTier,
            'voice_name' => $voiceName,
            'translate' => (bool) ($validated['translate'] ?? false),
            'duration_seconds' => $estimate['seconds'],
            'status' => 'processing',
        ]);

        // Reserve usage at queue time; GenerateAudioJob reconciles on success/final failure.
        $counter->increment('total_requests');
        $counter->increment('total_chars', $estimate['chars']);
        $counter->increment('total_minutes', $estimate['minutes']);

        $dispatchFailed = false;
        try {
            // Dashboard flow is intentionally synchronous for immediate UX.
            GenerateAudioJob::dispatchSync($job->id);
        } catch (\Throwable) {
            $dispatchFailed = true;
        }

        if ($dispatchFailed) {
            $this->releaseReservedUsage($counter, $estimate);
        }

        $job->refresh();
        $counter->refresh();

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
                    ? 'Voice generated successfully.'
                    : ($job->error_message ?: 'Voice generation failed. Please try again.'),
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
            $isDone ? 'Voice generated successfully.' : 'Voice generation failed. Check recent jobs for details.'
        );
    }

    private function normalizeVoiceSelection(string $language, ?string $voiceKey, string $tier): string
    {
        $voiceKey = trim((string) $voiceKey);

        if ($voiceKey === '') {
            $defaultVoice = VoiceCatalog::defaultForLanguage($language, $tier);
            if ($defaultVoice === '') {
                throw ValidationException::withMessages([
                    'target_language' => 'Selected language is not available for your current voice quality.',
                ]);
            }

            return $defaultVoice;
        }

        $voiceName = VoiceCatalog::nameFromKeyForLanguage($language, $voiceKey, $tier);
        if (! $voiceName) {
            throw ValidationException::withMessages([
                'voice_key' => 'Selected voice is not available for the chosen language.',
            ]);
        }

        return $voiceName;
    }

    private function rejectTierAccess(Request $request)
    {
        $message = 'Upgrade your plan to unlock this voice quality.';

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
        return [
            'id' => (int) $job->id,
            'status' => (string) $job->status,
            'source_language' => $job->source_language ?: '-',
            'target_language' => (string) $job->target_language,
            'voice_label' => $voiceNameMap[$job->voice_name]['label'] ?? 'Default voice',
            'voice_tier' => VoiceTier::label((string) ($job->voice_tier ?: VoiceTier::STANDARD)),
            'translate' => (bool) $job->translate,
            'duration_seconds' => $job->duration_seconds ? (int) $job->duration_seconds : null,
            'audio_url' => AudioUrl::fromPath($job->audio_path),
            'error_message' => $job->error_message,
            'created_human' => $job->created_at?->diffForHumans(),
        ];
    }
}
