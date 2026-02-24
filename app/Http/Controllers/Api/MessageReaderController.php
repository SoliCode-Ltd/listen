<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateAudioJob;
use App\Models\AudioGeneration;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\BillingSyncService;
use App\Services\PlanAccessService;
use App\Support\AudioUrl;
use App\Support\PlanUsageGuard;
use App\Support\UsageEstimator;
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
    ) {}

    public function profile(Request $request)
    {
        $user = $this->resolveApiUser($request);
        $this->billingSyncService->syncIfStale($user);

        $activePlan = $this->planAccessService->resolveActivePlanForUserId((int) $user->id);
        $planTier = $this->planAccessService->resolveTierForPlan($activePlan);

        $period = now()->format('Y-m');
        $counter = UsageCounter::firstOrCreate(
            ['user_id' => $user->id, 'period_ym' => $period],
            ['total_minutes' => 0, 'total_chars' => 0, 'total_requests' => 0]
        );

        $planChars = PlanUsageGuard::planChars($activePlan);
        $planRequests = PlanUsageGuard::planRequests($activePlan);
        $requestsUnlimited = PlanUsageGuard::hasUnlimitedRequests($activePlan);
        $defaultAr = VoiceCatalog::voiceMeta(VoiceCatalog::defaultForLanguage('ar', $planTier));
        $defaultEn = VoiceCatalog::voiceMeta(VoiceCatalog::defaultForLanguage('en', $planTier));

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
                    'max_chars_per_request' => PlanUsageGuard::maxCharsPerRequest($activePlan),
                    'allow_overage' => PlanUsageGuard::allowsOverage($activePlan),
                    'overage_price_per_million_cents' => $activePlan?->overage_price_per_million_cents !== null
                        ? (int) $activePlan->overage_price_per_million_cents
                        : null,
                    'commercial_use_allowed' => (bool) ($activePlan?->commercial_use_allowed ?? false),
                    'processing_priority' => (string) ($activePlan?->processing_priority ?: 'standard'),
                    'requests_unlimited' => $requestsUnlimited,
                ],
                'usage' => [
                    'period' => $period,
                    'total_chars' => (int) $counter->total_chars,
                    'total_requests' => (int) $counter->total_requests,
                    'total_minutes' => (int) $counter->total_minutes,
                    'plan_chars' => $planChars,
                    'plan_requests' => $requestsUnlimited ? null : $planRequests,
                    'requests_unlimited' => $requestsUnlimited,
                    'chars_percent' => min(100, (int) round(((int) $counter->total_chars / max(1, $planChars)) * 100)),
                    'requests_percent' => $requestsUnlimited
                        ? 0
                        : min(100, (int) round(((int) $counter->total_requests / max(1, $planRequests)) * 100)),
                ],
                'voices' => [
                    'catalog' => VoiceCatalog::publicCatalog($planTier),
                    'defaults' => [
                        'ar' => $defaultAr['key'] ?? null,
                        'en' => $defaultEn['key'] ?? null,
                    ],
                ],
            ],
        ]);
    }

    public function queue(Request $request)
    {
        $user = $this->resolveApiUser($request);
        $this->billingSyncService->syncIfStale($user);

        $activePlan = $this->planAccessService->resolveActivePlanForUserId((int) $user->id);
        $planTier = $this->planAccessService->resolveTierForPlan($activePlan);
        $maxChars = PlanUsageGuard::maxCharsPerRequest($activePlan);
        $processingMode = strtolower((string) config('listen.audio.processing', 'sync'));

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:50000'],
            'target_language' => ['required', 'in:ar,en'],
            'voice_key' => ['nullable', 'string', 'max:60'],
            'voice_name' => ['nullable', 'string', 'max:120'],
            'voice_tier' => ['nullable', 'in:standard,hd,hd_plus'],
            'translate' => ['nullable', 'boolean'],
            'prefer_sync' => ['nullable', 'boolean'],
        ]);

        $requestedTier = VoiceTier::normalize((string) ($validated['voice_tier'] ?? $planTier));
        if (VoiceTier::isHigherThan($requestedTier, $planTier)) {
            return response()->json([
                'ok' => false,
                'message' => 'Upgrade your plan to unlock this voice quality.',
            ], 403);
        }

        $voiceName = $this->normalizeVoiceSelection(
            $validated['target_language'],
            $validated['voice_key'] ?? null,
            $validated['voice_name'] ?? null,
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

        PlanUsageGuard::assertWithinLimits($activePlan, $counter, $estimate);

        $item = \App\Models\TextItem::create([
            'user_id' => $user->id,
            'source_type' => 'message',
            'title' => $validated['title'] ?? null,
            'content' => $text,
            'language' => $sourceLanguage,
            'status' => $initialStatus,
            'received_at' => now(),
        ]);

        $job = AudioGeneration::create([
            'user_id' => $user->id,
            'text_item_id' => $item->id,
            'input_text' => $text,
            'source_language' => $sourceLanguage,
            'target_language' => $validated['target_language'],
            'voice_tier' => $planTier,
            'voice_name' => $voiceName,
            'translate' => (bool) ($validated['translate'] ?? false),
            'duration_seconds' => $estimate['seconds'],
            'status' => $initialStatus,
        ]);

        // Reserve usage at queue time; GenerateAudioJob reconciles on success/final failure.
        $counter->increment('total_requests');
        $counter->increment('total_chars', $estimate['chars']);
        $counter->increment('total_minutes', $estimate['minutes']);

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
                $this->releaseReservedUsage($counter, $estimate);
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
            'voice_tier' => $planTier,
            'voice_tier_label' => VoiceTier::label($planTier),
            'audio_url' => AudioUrl::fromPath($job->audio_path),
            'error_message' => $job->error_message,
            'estimated_chars' => $estimate['chars'],
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
            return $request->user();
        }

        $provided = $request->header('X-Listen-Key')
            ?? $request->header('X-API-Key')
            ?? $request->input('api_key');

        $provided = is_string($provided) ? trim($provided) : '';
        if ($provided === '') {
            abort(401, 'Missing API key.');
        }

        $user = User::query()
            ->where('api_key_hash', User::hashApiKey($provided))
            ->first();

        if ($user) {
            return $user;
        }

        // Backward-compatible emergency key for server-to-server integrations.
        $legacyApiKey = trim((string) env('LISTEN_API_KEY', ''));
        if ($legacyApiKey !== '' && hash_equals($legacyApiKey, $provided)) {
            return User::query()->findOrFail(1);
        }

        abort(401, 'Invalid API key.');
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

    private function normalizeVoiceSelection(string $language, ?string $voiceKey, ?string $voiceName, string $tier): string
    {
        $voiceKey = trim((string) $voiceKey);
        $voiceName = trim((string) $voiceName);

        if ($voiceKey !== '') {
            $mapped = VoiceCatalog::nameFromKeyForLanguage($language, $voiceKey, $tier);
            if (! $mapped) {
                throw ValidationException::withMessages([
                    'voice_key' => 'Selected speaker is not available for the chosen voice language.',
                ]);
            }

            return $mapped;
        }

        if ($voiceName !== '') {
            if (! VoiceCatalog::isAllowedForLanguage($voiceName, $language, $tier)) {
                throw ValidationException::withMessages([
                    'voice_name' => 'Selected speaker is not available for the chosen voice language.',
                ]);
            }

            return $voiceName;
        }

        $defaultVoice = VoiceCatalog::defaultForLanguage($language, $tier);
        if ($defaultVoice === '') {
            throw ValidationException::withMessages([
                'target_language' => 'Selected voice language is not available for your current voice quality.',
            ]);
        }

        return $defaultVoice;
    }
}
