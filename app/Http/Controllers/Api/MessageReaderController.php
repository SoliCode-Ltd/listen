<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateAudioJob;
use App\Models\AudioGeneration;
use App\Models\TextItem;
use App\Models\UsageCounter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class MessageReaderController extends Controller
{
    public function queue(Request $request)
    {
        $userId = $this->resolveApiUserId($request);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:20000'],
            'target_language' => ['required', 'in:ar,en'],
            'source_type' => ['required', 'in:email,message,file'],
            'voice_name' => ['nullable', 'string', 'max:120'],
            'translate' => ['nullable', 'boolean'],
        ]);

        $text = trim($validated['content']);
        $sourceLanguage = preg_match('/[\x{0600}-\x{06FF}]/u', $text) ? 'ar' : 'en';
        [$estimatedSeconds, $estimatedMinutes] = $this->estimateTiming($text);

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
            'voice_name' => $validated['voice_name'] ?? 'default',
            'translate' => (bool) ($validated['translate'] ?? false),
            'duration_seconds' => $estimatedSeconds,
            'status' => 'queued',
        ]);

        $period = now()->format('Y-m');
        $counter = UsageCounter::firstOrCreate(
            ['user_id' => $userId, 'period_ym' => $period],
            ['total_minutes' => 0, 'total_chars' => 0, 'total_requests' => 0]
        );
        $counter->increment('total_requests');
        $counter->increment('total_chars', mb_strlen($text));
        $counter->increment('total_minutes', $estimatedMinutes);

        GenerateAudioJob::dispatch($job->id);

        return response()->json([
            'ok' => true,
            'job_id' => $job->id,
            'item_id' => $item->id,
            'status' => $job->status,
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
            return 1;
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
}
