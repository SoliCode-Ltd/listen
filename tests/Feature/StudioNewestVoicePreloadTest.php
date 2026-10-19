<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The newest finished voice in the history loads ahead (preload="auto"), so it
 * starts at once when played; the other rows keep preload="none" and load
 * only when played, so a long history does not download every file.
 */
class StudioNewestVoicePreloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_newest_finished_voice_loads_ahead(): void
    {
        $plan = Plan::factory()->create(['voice_tier' => 'standard', 'is_active' => true, 'monthly_chars' => 100000, 'max_chars_per_request' => 5000, 'monthly_requests' => 0]);
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);
        foreach (['older', 'newer'] as $i => $name) {
            AudioGeneration::query()->create([
                'user_id' => $user->id, 'user_sequence_number' => $i + 1, 'input_text' => "The {$name} voice.", 'source_language' => 'en',
                'target_language' => 'en', 'voice_tier' => 'standard', 'voice_name' => 'en-US-Wavenet-C', 'status' => 'done',
                'audio_path' => "audio/{$name}.mp3",
            ]);
        }

        $html = $this->actingAs($user)->getLocalizedRoute('dashboard')->assertOk()->getContent();

        // The rows the page draws: the newest finished one with preload="auto".
        $this->assertStringContainsString("const newestReady = allJobs.find((job) => String(job?.status || '') === 'done' && job?.audio_url);", $html);
        $this->assertStringContainsString('renderJobRow(job, resolveJobKey(job) === newestReadyKey)', $html);
        $this->assertStringContainsString("'<audio controls preload=\"' + (preloadAudio ? 'auto' : 'none') + '\" src=\"'", $html);
        // The rows in the page source (replaced as the page opens) never load ahead.
        $this->assertSame(0, substr_count($html, 'preload="auto"'));
        $this->assertGreaterThanOrEqual(2, substr_count($html, "controls\n                        preload=\"none\""));
    }
}
