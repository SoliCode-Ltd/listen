<?php

namespace Tests\Feature\Api;

use App\Models\AudioGeneration;
use App\Models\Subscription;
use App\Models\UsageCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * api:expire-stuck-generations (every five minutes): a developer API
 * generation stuck queued for an hour, or processing for half an hour, is
 * failed and every credit it reserved is given back, exactly once, to the
 * credit period it was taken from. Fresh generations, finished ones and the
 * dashboard's own are left alone, and a job that wakes up later never makes
 * or charges a generation the sweep already failed.
 */
class ExpireStuckApiGenerationsTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
        config(['queue.default' => 'database']);
    }

    /**
     * A background generation no worker picked up is failed after an hour
     * and refunded; running the sweep again gives nothing back twice (the
     * member's other, finished generation stays charged).
     */
    public function test_a_generation_stuck_in_the_queue_is_failed_and_refunded_exactly_once(): void
    {
        $user = $this->apiMember();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);
        $voice = $this->premiumVoice();
        $this->speak($plain, $voice, ['text' => 'A finished one.'])->assertOk();
        $id = $this->speakAsync($plain, $voice)->assertStatus(202)->json('history_item_id');
        $this->assertSame([105, 2, 7], [$this->creditsSpent($user), $this->requestsCounted($user), $this->secondsCounted($user)]);

        $this->travel(61)->minutes();
        $this->artisan('api:expire-stuck-generations')->expectsOutput('Expired 1 stuck API generation(s).')->assertSuccessful();

        $generation = AudioGeneration::query()->findOrFail($id);
        $this->assertSame('failed', $generation->status);
        $this->assertStringContainsString('No credits were charged', (string) $generation->error_message);
        $this->assertSame([60, 1, 4], [$this->creditsSpent($user), $this->requestsCounted($user), $this->secondsCounted($user)]);
        $this->assertSame([60, 1], [$this->keyCreditsSpent($key), $this->keyRequestsCounted($key)]);

        $this->artisan('api:expire-stuck-generations')->expectsOutput('Expired 0 stuck API generation(s).')->assertSuccessful();
        $this->assertSame([60, 1, 4], [$this->creditsSpent($user), $this->requestsCounted($user), $this->secondsCounted($user)]);
        $this->assertSame([60, 1], [$this->keyCreditsSpent($key), $this->keyRequestsCounted($key)]);

        $item = $this->apiGet($plain, '/history/'.$id)->assertOk()->json();
        $this->assertSame(['failed', 0, null], [$item['status'], $item['credits_used'], $item['audio_url']]);
        $this->assertStringContainsString('took too long', (string) $item['error']['message']);
    }

    /**
     * A queued generation is given the full hour: at 59 minutes it is left,
     * past 60 it is failed.
     */
    public function test_a_queued_generation_is_left_alone_for_its_first_hour(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $id = $this->speakAsync($plain, $this->premiumVoice())->assertStatus(202)->json('history_item_id');

        $this->travel(59)->minutes();
        $this->artisan('api:expire-stuck-generations')->expectsOutput('Expired 0 stuck API generation(s).');
        $this->assertSame(['queued', 45], [AudioGeneration::query()->findOrFail($id)->status, $this->creditsSpent($user)]);

        $this->travel(2)->minutes();
        $this->artisan('api:expire-stuck-generations')->expectsOutput('Expired 1 stuck API generation(s).');
        $this->assertSame(['failed', 0], [AudioGeneration::query()->findOrFail($id)->status, $this->creditsSpent($user)]);
    }

    /**
     * A generation left processing (its worker or web process died) is failed
     * and refunded after thirty minutes without progress, not before.
     */
    public function test_a_generation_stuck_processing_is_failed_after_thirty_minutes(): void
    {
        $user = $this->apiMember();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);
        $id = $this->speakAsync($plain, $this->premiumVoice())->assertStatus(202)->json('history_item_id');
        AudioGeneration::query()->findOrFail($id)->update(['status' => 'processing']);

        $this->travel(29)->minutes();
        $this->artisan('api:expire-stuck-generations')->expectsOutput('Expired 0 stuck API generation(s).');
        $this->assertSame('processing', AudioGeneration::query()->findOrFail($id)->status);

        $this->travel(2)->minutes();
        $this->artisan('api:expire-stuck-generations')->expectsOutput('Expired 1 stuck API generation(s).');
        $this->assertSame('failed', AudioGeneration::query()->findOrFail($id)->status);
        $this->assertSame([0, 0, 0], [$this->creditsSpent($user), $this->requestsCounted($user), $this->keyCreditsSpent($key)]);
    }

    /**
     * Finished and failed API generations, and the dashboard's own queued
     * ones (they are not the API's to refund), are never touched.
     */
    public function test_finished_failed_and_dashboard_generations_are_left_alone(): void
    {
        $user = $this->apiMember();
        $key = $this->issueKey($user)['key'];
        $done = $this->storedGeneration($user, ['api_key_id' => $key->id]);
        $failed = $this->storedGeneration($user, ['api_key_id' => $key->id, 'status' => 'failed', 'audio_path' => null, 'error_message' => 'Earlier failure.']);
        $dashboard = $this->storedGeneration($user, ['status' => 'queued', 'audio_path' => null]);

        $this->travel(3)->hours();
        $this->artisan('api:expire-stuck-generations')->expectsOutput('Expired 0 stuck API generation(s).')->assertSuccessful();

        $this->assertSame('done', $done->fresh()->status);
        $this->assertSame(['failed', 'Earlier failure.'], [$failed->fresh()->status, $failed->fresh()->error_message]);
        $this->assertSame('queued', $dashboard->fresh()->status);
    }

    /**
     * The job of a generation the sweep failed may still be on the queue;
     * when a worker finally runs it, nothing is made and nothing is charged.
     */
    public function test_a_job_that_wakes_up_after_the_sweep_never_makes_or_charges_it(): void
    {
        $user = $this->apiMember();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);
        $id = $this->speakAsync($plain, $this->premiumVoice())->assertStatus(202)->json('history_item_id');
        $this->travel(61)->minutes();
        $this->artisan('api:expire-stuck-generations')->assertSuccessful();

        $this->assertSame(0, Artisan::call('queue:work', ['--once' => true, '--queue' => 'high,default']));

        $this->assertSame(0, DB::table('jobs')->count(), 'the job ran');
        $this->assertSame(0, $this->speechCalls->gemini, 'no voice was made');
        $generation = AudioGeneration::query()->findOrFail($id);
        $this->assertSame(['failed', null], [$generation->status, $generation->audio_path]);
        $this->assertSame([0, 0, 0], [$this->creditsSpent($user), $this->requestsCounted($user), $this->keyCreditsSpent($key)]);
    }

    /**
     * The refund goes back to the credit period the credits were taken
     * from: after the plan renews, the new period's usage is not touched.
     */
    public function test_the_refund_goes_back_to_the_credit_period_it_was_taken_from(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();
        $stuck = $this->speakAsync($plain, $voice)->assertStatus(202)->json('history_item_id');
        $oldPeriod = AudioGeneration::query()->findOrFail($stuck)->settings_snapshot['reservation']['period'];

        // A month later the plan renews, and the member makes a generation in the new period.
        $renewedAt = Carbon::parse('2026-11-14 10:00:00', 'UTC');
        $this->travelTo($renewedAt->copy()->addMinutes(5));
        Subscription::query()->where('user_id', $user->id)->update(['current_period_start' => $renewedAt, 'current_period_end' => $renewedAt->copy()->addMonth()]);
        $this->speak($plain, $voice, ['text' => 'In the new period.'])->assertOk();
        $newPeriod = UsageCounter::query()->where('user_id', $user->id)->where('period_ym', '!=', $oldPeriod)->sole();
        $this->assertSame(60, (int) $newPeriod->total_chars);

        $this->artisan('api:expire-stuck-generations')->expectsOutput('Expired 1 stuck API generation(s).');

        $this->assertSame(0, (int) UsageCounter::query()->where('user_id', $user->id)->where('period_ym', $oldPeriod)->value('total_chars'), 'given back where it was taken');
        $this->assertSame([60, 1, 4], [(int) $newPeriod->fresh()->total_chars, (int) $newPeriod->fresh()->total_requests, (int) $newPeriod->fresh()->total_seconds], 'the new period is untouched');
    }
}
