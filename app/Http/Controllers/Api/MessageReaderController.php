<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateAudioJob;
use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TextItem;
use App\Models\User;
use App\Models\UsageCounter;
use App\Support\AudioUrl;
use App\Support\PlanUsageGuard;
use App\Support\UsageEstimator;
use App\Support\VoiceCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class MessageReaderController extends Controller
{
    public function profile(Request $request)
    {
        $userId = $this->resolveApiUserId($request);
        $user = User::query()->findOrFail($userId, ['id', 'name', 'email']);
        $activePlan = $this->resolveActivePlan($userId);

        $period = now()->format('Y-m');
        $counter = UsageCounter::firstOrCreate(
            ['user_id' => $userId, 'period_ym' => $period],
            ['total_minutes' => 0, 'total_chars' => 0, 'total_requests' => 0]
        );

        $planChars = PlanUsageGuard::planChars($activePlan);
        $planRequests = PlanUsageGuard::planRequests($activePlan);
        $requestsUnlimited = PlanUsageGuard::hasUnlimitedRequests($activePlan);
        $defaultAr = VoiceCatalog::voiceMeta(VoiceCatalog::defaultForLanguage('ar'));
        $defaultEn = VoiceCatalog::voiceMeta(VoiceCatalog::defaultForLanguage('en'));

        return response()->json([
            'ok' => true,
            'profile' => [
                'user' => [
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                ],
                'plan' => [
                    'name' => (string) ($activePlan?->name ?? 'Free'),
                    'slug' => (string) ($activePlan?->slug ?? 'free'),
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
                        ? 100
                        : min(100, (int) round(((int) $counter->total_requests / max(1, $planRequests)) * 100)),
                ],
                'voices' => [
                    'catalog' => VoiceCatalog::publicCatalog(),
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
        $userId = $this->resolveApiUserId($request);
        $activePlan = $this->resolveActivePlan($userId);
        $maxChars = PlanUsageGuard::maxCharsPerRequest($activePlan);
        $processingMode = strtolower((string) config('listen.audio.processing', 'sync'));

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:50000'],
            'target_language' => ['required', 'in:ar,en'],
            'source_type' => ['required', 'in:email,message,file'],
            'voice_key' => ['nullable', 'string', 'max:60'],
            'voice_name' => ['nullable', 'string', 'max:120'],
            'translate' => ['nullable', 'boolean'],
        ]);

        $voiceName = $this->normalizeVoiceSelection(
            $validated['target_language'],
            $validated['voice_key'] ?? null,
            $validated['voice_name'] ?? null,
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
        $runAsync = $processingMode === 'queue'
            || ($processingMode === 'sync' && $forceAsyncChars > 0 && $estimate['chars'] >= $forceAsyncChars);
        $initialStatus = $runAsync ? 'queued' : 'processing';

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
            'status' => $initialStatus,
            'received_at' => now(),
        ]);

        $job = AudioGeneration::create([
            'user_id' => $userId,
            'text_item_id' => $item->id,
            'input_text' => $text,
            'source_language' => $sourceLanguage,
            'target_language' => $validated['target_language'],
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
            $dispatchFailed = false;
            try {
                GenerateAudioJob::dispatchSync($job->id);
            } catch (\Throwable) {
                $dispatchFailed = true;
            }

            if ($dispatchFailed) {
                $this->releaseReservedUsage($counter, $estimate);
            }

            $job->refresh();
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
            'audio_url' => AudioUrl::fromPath($job->audio_path),
            'error_message' => $job->error_message,
            'estimated_chars' => $estimate['chars'],
            'estimated_seconds' => $estimate['seconds'],
            'estimated_minutes' => $estimate['minutes'],
        ], $statusCode);
    }

    public function list(Request $request)
    {
        $userId = $this->resolveApiUserId($request);
        $limit = min(100, max(1, (int) $request->integer('limit', 25)));

        $jobs = AudioGeneration::query()
            ->where('user_id', $userId)
            ->latest()
            ->limit($limit)
            ->get([
                'id',
                'source_language',
                'target_language',
                'voice_name',
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

    private function resolveApiUserId(Request $request): int
    {
        if (Auth::check()) {
            return (int) Auth::id();
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
            return (int) $user->id;
        }

        // Backward-compatible emergency key for server-to-server integrations.
        $legacyApiKey = trim((string) env('LISTEN_API_KEY', ''));
        if ($legacyApiKey !== '' && hash_equals($legacyApiKey, $provided)) {
            return 1;
        }

        abort(401, 'Invalid API key.');
    }

    /**
     * @param array{chars:int,minutes:int} $estimate
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

    private function resolveActivePlan(int $userId): ?Plan
    {
        $subscription = Subscription::query()
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('current_period_end')
                    ->orWhere('current_period_end', '>=', now());
            })
            ->latest('id')
            ->with('plan')
            ->first();

        if ($subscription?->plan) {
            return $subscription->plan;
        }

        return Plan::query()->where('slug', 'free')->first()
            ?? Plan::query()->where('is_active', true)->orderBy('price_cents')->first();
    }

    private function normalizeVoiceSelection(string $language, ?string $voiceKey, ?string $voiceName): string
    {
        $voiceKey = trim((string) $voiceKey);
        $voiceName = trim((string) $voiceName);

        if ($voiceKey !== '') {
            $mapped = VoiceCatalog::nameFromKeyForLanguage($language, $voiceKey);
            if (! $mapped) {
                throw ValidationException::withMessages([
                    'voice_key' => 'Selected voice is not available for the chosen language.',
                ]);
            }

            return $mapped;
        }

        if ($voiceName !== '') {
            if (! VoiceCatalog::isAllowedForLanguage($voiceName, $language)) {
                throw ValidationException::withMessages([
                    'voice_name' => 'Selected voice is not available for the chosen language.',
                ]);
            }

            return $voiceName;
        }

        return VoiceCatalog::defaultForLanguage($language);
    }
}
