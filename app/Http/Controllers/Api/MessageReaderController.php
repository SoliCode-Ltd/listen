<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateAudioJob;
use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TextItem;
use App\Models\UsageCounter;
use App\Support\VoiceCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MessageReaderController extends Controller
{
    public function queue(Request $request)
    {
        $userId = $this->resolveApiUserId($request);
        $activePlan = $this->resolveActivePlan($userId);
        $maxChars = max(200, (int) ($activePlan?->max_chars_per_request ?? 600));

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:'.$maxChars],
            'target_language' => ['required', 'in:ar,en'],
            'source_type' => ['required', 'in:email,message,file'],
            'voice_name' => ['nullable', 'string', 'max:120'],
            'translate' => ['nullable', 'boolean'],
        ]);

        $voiceName = $this->normalizeVoiceSelection(
            $validated['target_language'],
            $validated['voice_name'] ?? null,
        );

        $text = trim($validated['content']);
        $sourceLanguage = preg_match('/[\x{0600}-\x{06FF}]/u', $text) ? 'ar' : 'en';
        [$estimatedSeconds, $estimatedMinutes] = $this->estimateTiming($text);

        $period = now()->format('Y-m');
        $counter = UsageCounter::firstOrCreate(
            ['user_id' => $userId, 'period_ym' => $period],
            ['total_minutes' => 0, 'total_chars' => 0, 'total_requests' => 0]
        );

        $planMinutes = max(1, (int) ($activePlan?->monthly_minutes ?? 10));
        if (($counter->total_minutes + $estimatedMinutes) > $planMinutes) {
            throw ValidationException::withMessages([
                'content' => 'Monthly plan minutes exceeded. Please upgrade your plan to continue.',
            ]);
        }

        $item = TextItem::create([
            'user_id' => $userId,
            'source_type' => $validated['source_type'],
            'title' => $validated['title'] ?? null,
            'content' => $text,
            'language' => $sourceLanguage,
            'status' => 'queued',
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
            'duration_seconds' => $estimatedSeconds,
            'status' => 'queued',
        ]);

        $counter->increment('total_requests');
        $counter->increment('total_chars', mb_strlen($text));
        $counter->increment('total_minutes', $estimatedMinutes);

        GenerateAudioJob::dispatch($job->id);

        return response()->json([
            'ok' => true,
            'job_id' => $job->id,
            'item_id' => $item->id,
            'status' => $job->status,
            'voice_name' => $voiceName,
            'estimated_seconds' => $estimatedSeconds,
            'estimated_minutes' => $estimatedMinutes,
        ], 202);
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
                    'voice_name' => $job->voice_name,
                    'translate' => $job->translate,
                    'status' => $job->status,
                    'duration_seconds' => $job->duration_seconds,
                    'audio_url' => $job->audio_path ? Storage::disk('public')->url($job->audio_path) : null,
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

    private function estimateTiming(string $text): array
    {
        $wordEstimate = max(
            str_word_count(strip_tags($text)),
            (int) ceil(mb_strlen($text) / 5)
        );

        $seconds = max(3, (int) ceil($wordEstimate / 2.6));
        $minutes = max(1, (int) ceil($seconds / 60));

        return [$seconds, $minutes];
    }

    private function resolveApiUserId(Request $request): int
    {
        if (Auth::check()) {
            return (int) Auth::id();
        }

        $apiKey = (string) env('LISTEN_API_KEY', '');
        if ($apiKey === '') {
            abort(401, 'API access is disabled.');
        }

        $provided = $request->header('X-Listen-Key')
            ?? $request->header('X-API-Key')
            ?? $request->input('api_key');

        $provided = is_string($provided) ? trim($provided) : '';
        if ($provided === '' || ! hash_equals($apiKey, $provided)) {
            abort(401, 'Invalid API key.');
        }

        return 1;
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

    private function normalizeVoiceSelection(string $language, ?string $voiceName): string
    {
        $voiceName = trim((string) $voiceName);

        if ($voiceName === '') {
            return VoiceCatalog::defaultForLanguage($language);
        }

        if (! VoiceCatalog::isAllowedForLanguage($voiceName, $language)) {
            throw ValidationException::withMessages([
                'voice_name' => 'Selected voice is not available for the chosen language.',
            ]);
        }

        return $voiceName;
    }
}
