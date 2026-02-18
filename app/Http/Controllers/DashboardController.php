<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAudioJob;
use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\TextItem;
use App\Models\UsageCounter;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
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
            'queued' => (clone $baseQuery)->where('status', 'queued')->count(),
            'done' => (clone $baseQuery)->where('status', 'done')->count(),
            'failed' => (clone $baseQuery)->where('status', 'failed')->count(),
            'total' => (clone $baseQuery)->count(),
        ];

        $activePlan = Plan::query()->where('slug', 'pro')->first();
        $planMinutes = $activePlan?->monthly_minutes ?? 250;
        $usagePercent = min(100, (int) round(($usage->total_minutes / max(1, $planMinutes)) * 100));

        return view('dashboard', compact('usage', 'jobs', 'stats', 'activePlan', 'planMinutes', 'usagePercent'));
    }

    public function queue(Request $request)
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:20000'],
            'source_type' => ['required', 'in:email,message,file'],
            'target_language' => ['required', 'in:ar,en'],
            'translate' => ['nullable', 'boolean'],
            'voice_name' => ['nullable', 'string', 'max:120'],
        ]);

        $text = trim($validated['content']);
        $userId = (int) auth()->id();
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

        return redirect()->route('dashboard')->with('ok', 'Queued successfully.');
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
}
