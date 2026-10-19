<?php

namespace Tests\Feature\Api;

use App\Models\AudioGeneration;
use App\Models\Subscription;
use App\Models\UsageCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * POST /v1/text-to-speech/{voice_id}/async, on the database queue as on the
 * servers: the request answers at once with 202 and a queued history item
 * and reserves the credits; a worker makes the audio later, the charge is
 * corrected to its real length, and the client polls GET /v1/history/{id}
 * and downloads the audio. A request the queue cannot take is given back.
 */
class ApiAsyncGenerationTest extends TestCase
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
     * The answer is 202 with the history item to poll (status queued, the
     * reserved credits, no audio yet) and a Location header; the credits are
     * reserved and nothing is generated until a worker runs.
     */
    public function test_a_background_request_answers_202_with_a_queued_history_item(): void
    {
        $user = $this->apiMember();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);
        $voice = $this->premiumVoice();

        $response = $this->speakAsync($plain, $voice, ['title' => 'Chapter one'])->assertStatus(202);

        $generation = AudioGeneration::query()->sole();
        $response->assertHeader('Location', url('/v1/history/'.$generation->id))
            ->assertHeader('X-History-Item-Id', (string) $generation->id)
            ->assertHeader('X-Character-Count', '28')
            ->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame([
            'history_item_id' => $generation->id,
            'status' => 'queued',
            'request_source' => 'api',
            'api_key_id' => $key->id,
            'voice_id' => self::voiceId($voice),
            'voice_name' => 'Maya',
            'title' => 'Chapter one',
            'text' => 'Hello there, this is a test.',
            'character_count' => 28,
            'credits_used' => 45,
            'duration_seconds' => null,
            'audio_url' => null,
            'error' => null,
        ], array_intersect_key($response->json(), array_flip([
            'history_item_id', 'status', 'request_source', 'api_key_id', 'voice_id', 'voice_name', 'title',
            'text', 'character_count', 'credits_used', 'duration_seconds', 'audio_url', 'error',
        ])));

        $this->assertSame([45, 1, 3], [$this->creditsSpent($user), $this->requestsCounted($user), $this->secondsCounted($user)], 'reserved now');
        $this->assertSame([45, 1], [$this->keyCreditsSpent($key), $this->keyRequestsCounted($key)]);
        $this->assertSame(['default'], DB::table('jobs')->pluck('queue')->all());
        $this->assertSame(0, $this->speechCalls->gemini, 'nothing is generated before a worker runs');

        $this->apiGet($plain, '/history/'.$generation->id)->assertOk()->assertJsonPath('status', 'queued');
        $this->assertApiError($this->apiGet($plain, '/history/'.$generation->id.'/audio'), 409, 'audio_not_ready', 'invalid_request_error', 'history_item_id');
    }

    /**
     * A worker makes it: the history item then shows completed with its real
     * length, cost and audio address, the audio downloads with the key, and
     * the reservation is corrected to the audio really made.
     */
    public function test_a_worker_makes_the_generation_and_the_client_downloads_it(): void
    {
        $user = $this->apiMember();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);
        $id = $this->speakAsync($plain, $this->premiumVoice())->assertStatus(202)->json('history_item_id');

        $this->runWorker();

        $item = $this->apiGet($plain, '/history/'.$id)->assertOk()->json();
        $this->assertSame(['completed', 4, 60, null], [$item['status'], $item['duration_seconds'], $item['credits_used'], $item['error']]);
        $this->assertSame(url('/v1/history/'.$id.'/audio'), $item['audio_url']);

        $audio = $this->get($item['audio_url'], ['X-Listen-Api-Key' => $plain])->assertOk();
        $generation = AudioGeneration::query()->findOrFail($id);
        $this->assertSame(Storage::disk('public')->get($generation->audio_path), $audio->getContent());
        $audio->assertHeader('X-History-Item-Id', (string) $id);

        $this->assertSame([60, 1, 4], [$this->creditsSpent($user), $this->requestsCounted($user), $this->secondsCounted($user)], 'corrected to the real audio');
        $this->assertSame([60, 1], [$this->keyCreditsSpent($key), $this->keyRequestsCounted($key)]);
        $this->assertSame(1, $this->speechCalls->gemini);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /**
     * Plans with high processing priority go to the high queue, the others to
     * the default one (the same queues as the browser extension's).
     */
    public function test_the_plans_processing_priority_picks_the_queue(): void
    {
        $standard = $this->issueKey($this->apiMember())['plain'];
        $priority = $this->issueKey($this->apiMember($this->apiPlan(['slug' => 'pro', 'name' => 'Pro', 'processing_priority' => 'high'])))['plain'];
        $voice = $this->premiumVoice();

        $this->speakAsync($standard, $voice)->assertStatus(202);
        $this->speakAsync($priority, $voice)->assertStatus(202);

        $this->assertSame(['default', 'high'], DB::table('jobs')->orderBy('id')->pluck('queue')->all());
    }

    /**
     * A slower speed makes longer audio, so more is reserved (twice as much at
     * 0.5); the worker then charges the audio really made.
     */
    public function test_a_slower_speed_reserves_more_and_the_worker_charges_the_real_length(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];

        $this->speakAsync($plain, $this->premiumVoice(), ['voice_settings' => ['speed' => 0.5]])->assertStatus(202);
        $this->assertSame([90, 6], [$this->creditsSpent($user), $this->secondsCounted($user)]);

        $this->runWorker();

        $this->assertSame([60, 4], [$this->creditsSpent($user), $this->secondsCounted($user)]);
    }

    /**
     * A generation reserved before the plan renewed and made after it is
     * corrected in the credit period its credits were reserved in; the new
     * period's usage is not touched.
     */
    public function test_a_generation_made_after_the_plan_renews_is_corrected_in_the_period_it_was_reserved_in(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $this->speakAsync($plain, $this->premiumVoice())->assertStatus(202);
        $reservedIn = AudioGeneration::query()->sole()->settings_snapshot['reservation']['period'];

        $renewedAt = Carbon::parse('2026-11-14 10:00:00', 'UTC');
        $this->travelTo($renewedAt->copy()->addMinute());
        Subscription::query()->where('user_id', $user->id)->update(['current_period_start' => $renewedAt, 'current_period_end' => $renewedAt->copy()->addMonth()]);
        $this->runWorker();

        $counters = UsageCounter::query()->where('user_id', $user->id)->get()->keyBy('period_ym');
        $this->assertSame([60, 1, 4], [(int) $counters[$reservedIn]->total_chars, (int) $counters[$reservedIn]->total_requests, (int) $counters[$reservedIn]->total_seconds]);
        $this->assertSame(60, $this->creditsSpent($user), 'nothing was charged to the new period');
    }

    /**
     * A background request may carry more text than a waiting one (up to the
     * plan's limit, at most 10,000 characters); past that it is refused.
     */
    public function test_background_requests_take_longer_texts_up_to_their_own_limit(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();

        $this->speakAsync($plain, $voice, ['text' => str_repeat('Long text. ', 700)])->assertStatus(202);

        $response = $this->speakAsync($plain, $voice, ['text' => str_repeat('d', 10001)]);
        $this->assertApiError($response, 422, 'text_too_long', 'invalid_request_error', 'text')
            ->assertJsonPath('error.max_characters', 10000);
        $this->assertSame(1, AudioGeneration::query()->count());
    }

    /**
     * When the queue cannot take the job, the request answers 503
     * queue_unavailable and everything it reserved is given back.
     */
    public function test_a_request_the_queue_cannot_take_is_refunded_with_503_queue_unavailable(): void
    {
        config(['queue.connections.database.table' => 'jobs_table_that_is_missing']);
        $user = $this->apiMember();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);

        $response = $this->speakAsync($plain, $this->premiumVoice());

        $this->assertApiError($response, 503, 'queue_unavailable', 'api_error')->assertHeader('Retry-After', '30');
        $this->assertStringContainsString('No credits were charged', (string) $response->json('error.message'));
        $this->assertSame('failed', AudioGeneration::query()->sole()->status);
        $this->assertSame([0, 0, 0], [$this->creditsSpent($user), $this->requestsCounted($user), $this->secondsCounted($user)]);
        $this->assertSame([0, 0], [$this->keyCreditsSpent($key), $this->keyRequestsCounted($key)]);
    }

    /**
     * A member may have only a few background generations waiting or
     * running (ten on this plan): one more gets 429 queue_limit_exceeded and
     * is not charged; waiting requests are not held up by it.
     */
    public function test_too_many_background_generations_waiting_get_429_queue_limit_exceeded(): void
    {
        $user = $this->apiMember();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);
        $voice = $this->premiumVoice();
        for ($i = 0; $i < 10; $i++) {
            $this->storedGeneration($user, ['status' => 'queued', 'audio_path' => null, 'api_key_id' => $key->id]);
        }

        $response = $this->speakAsync($plain, $voice);

        $this->assertApiError($response, 429, 'queue_limit_exceeded', 'rate_limit_error')
            ->assertHeader('Retry-After', '10')
            ->assertJsonPath('error.queue_limit', 10);
        $this->assertSame(0, $this->creditsSpent($user));
        $this->assertSame(0, DB::table('jobs')->count());

        $this->speak($plain, $voice)->assertOk();
    }

    private function runWorker(): void
    {
        $this->assertSame(0, Artisan::call('queue:work', ['--once' => true, '--queue' => 'high,default']));
    }
}
