<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\DemoVoiceGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voice;
use App\Services\DemoHistoryImporter;
use App\Support\VoiceCatalog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A long history stays quick to load: each voice's details are read once per
 * request, the studio lists the latest 500 generations, and the demo rows'
 * paid tags are only rewritten when the member's plan changed.
 */
class StudioHistoryLoadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_voice_is_read_once_per_request_and_again_once_it_is_changed(): void
    {
        $voice = Voice::query()->create([
            'internal_voice_id' => 'en-US-history', 'language' => 'en', 'gender' => 'female', 'tier' => 'standard',
            'display_name' => 'Sara', 'provider' => 'google_cloud', 'provider_voice_id' => 'en-US-history', 'is_active' => true,
        ]);
        $reads = $this->countQueries('from "voices"');

        foreach (range(1, 3) as $row) {
            $this->assertSame('Sara', VoiceCatalog::voiceMeta('en-US-history')['label']);
            $this->assertNull(VoiceCatalog::voiceMeta('en-US-gone'));
        }
        $this->assertSame(2, $reads->count, 'each voice is read once, a missing one too');

        $voice->update(['display_name' => 'Mariam']);
        $this->assertSame('Mariam', VoiceCatalog::voiceMeta('en-US-history')['label'], 'a saved voice is read again');

        // The queue worker starts each job afresh, as each request starts with nothing kept.
        app()->forgetScopedInstances();
        $reads->count = 0;
        VoiceCatalog::voiceMeta('en-US-history');
        $this->assertSame(1, $reads->count);
    }

    public function test_the_studio_lists_the_latest_500_generations(): void
    {
        $user = $this->member();
        $rows = [];
        foreach (range(1, 505) as $number) {
            $rows[] = [
                'user_id' => $user->id, 'user_sequence_number' => $number, 'input_text' => "Line {$number}",
                'source_language' => 'en', 'target_language' => 'en', 'voice_tier' => 'standard', 'voice_name' => 'en-US-history',
                'translate' => false, 'status' => 'failed', 'created_at' => now()->subMinutes(1000 - $number), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            AudioGeneration::query()->insert($chunk);
        }

        $response = $this->actingAs($user)->getLocalizedRoute('dashboard')->assertOk();

        $jobs = $response->viewData('jobs');
        $this->assertCount(500, $jobs);
        $this->assertSame(505, (int) $jobs->first()->user_sequence_number, 'newest first');
        $this->assertSame(6, (int) $jobs->last()->user_sequence_number, 'the five oldest are left out');
        $this->assertSame(505, $response->viewData('stats')['total'], 'the counts still cover every generation');
    }

    public function test_demo_rows_are_rewritten_only_when_the_paid_state_or_plan_changes(): void
    {
        $user = User::factory()->create();
        $demo = DemoVoiceGeneration::query()->create([
            'user_id' => $user->id, 'primary_identity_hash' => 'u:'.$user->id, 'input_text' => 'Hello there', 'speech_text' => 'Hello there',
            'source_language' => 'en', 'target_language' => 'en', 'translate' => false, 'voice_name' => 'en-US-Wavenet-C',
            'voice_provider' => 'google_cloud', 'voice_tier' => 'standard', 'billable_chars' => 11, 'audio_path' => 'audio/demo.mp3',
        ]);
        $paid = Plan::factory()->create(['slug' => 'creator-history', 'price_cents' => 2200]);
        $importer = app(DemoHistoryImporter::class);
        $reads = $this->countQueries('from "demo_voice_generations"');

        $this->assertSame(1, $importer->syncStorageForPlan((int) $user->id, $paid, 200)['moved_to_history']);
        $reads->count = 0;
        $importer->syncStorageForPlan((int) $user->id, $paid, 200);
        $this->assertSame(0, $reads->count, 'same plan: the rows are not read again');

        $this->assertSame(1, $importer->syncStorageForPlan((int) $user->id, null, 200)['moved_to_demo'], 'a change of plan is applied at once');
        $this->assertFalse($demo->fresh()->metadata['paid_user']);

        // After ten minutes the rows are checked again, so newer demo voices are tagged too.
        $this->travel(11)->minutes();
        $reads->count = 0;
        $importer->syncStorageForPlan((int) $user->id, null, 200);
        $this->assertGreaterThan(0, $reads->count);
    }

    /** Counts the queries whose SQL contains $needle, from now on. */
    private function countQueries(string $needle): \stdClass
    {
        $reads = new \stdClass;
        $reads->count = 0;
        DB::listen(function (QueryExecuted $query) use ($reads, $needle): void {
            if (str_contains($query->sql, $needle)) {
                $reads->count++;
            }
        });

        return $reads;
    }

    private function member(): User
    {
        $plan = Plan::factory()->create([
            'voice_tier' => 'standard', 'price_cents' => 1999, 'is_active' => true,
            'monthly_chars' => 100000, 'max_chars_per_request' => 5000, 'monthly_requests' => 0,
        ]);
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }
}
