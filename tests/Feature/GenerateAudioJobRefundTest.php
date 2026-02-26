<?php

namespace Tests\Feature;

use App\Jobs\GenerateAudioJob;
use App\Models\AudioGeneration;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateAudioJobRefundTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_async_job_releases_reserved_usage(): void
    {
        $user = User::factory()->create();
        $period = now()->format('Y-m');

        $counter = UsageCounter::create([
            'user_id' => $user->id,
            'period_ym' => $period,
            'total_requests' => 1,
            'total_chars' => 5,
            'total_minutes' => 1,
        ]);

        $generation = AudioGeneration::create([
            'user_id' => $user->id,
            'input_text' => 'Hello!!',
            'source_language' => 'en',
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_name' => 'en-us-test',
            'translate' => false,
            'duration_seconds' => 60,
            'status' => 'processing',
        ]);

        $job = new GenerateAudioJob($generation->id);
        $job->connection = 'database';
        $job->failed(new \RuntimeException('Simulated queue failure'));

        $counter->refresh();
        $generation->refresh();

        $this->assertSame('failed', $generation->status);
        $this->assertSame(0, (int) $counter->total_requests);
        $this->assertSame(0, (int) $counter->total_chars);
        $this->assertSame(0, (int) $counter->total_minutes);
    }
}
