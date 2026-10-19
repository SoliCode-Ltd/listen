<?php

namespace Tests\Feature\Api;

use App\Models\ApiRequestLog;
use App\Models\AudioGeneration;
use App\Models\User;
use App\Models\Voice;
use App\Services\Api\ApiIdempotency;
use App\Support\CreditPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Safe retries with the Idempotency-Key header: a client that sends a
 * generation again with the same key and body gets the first result, marked
 * Idempotent-Replayed, and is never charged twice. A key reused for another
 * body is refused; a key whose request ended before charging, or whose
 * generation failed and was given back, may be used again. Keys belong to
 * one account: another account sending the same value gets its own result.
 */
class ApiIdempotencyTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
    }

    /**
     * The retry returns the very same audio and history item, marked as a
     * replay, and nothing is generated or charged again.
     */
    public function test_a_retry_with_the_same_key_returns_the_first_audio_and_charges_once(): void
    {
        $user = $this->apiMember();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);
        $voice = $this->premiumVoice();
        $headers = ['Idempotency-Key' => 'order-1001'];

        $first = $this->speak($plain, $voice, [], $headers)->assertOk()->assertHeaderMissing('Idempotent-Replayed');
        $retry = $this->speak($plain, $voice, [], $headers)->assertOk();

        $retry->assertHeader('Idempotent-Replayed', 'true')
            ->assertHeader('X-History-Item-Id', $first->headers->get('X-History-Item-Id'))
            ->assertHeader('X-Credits-Used', '60')
            ->assertHeader('X-Audio-Duration-Seconds', '4')
            ->assertHeader('Content-Type', $first->headers->get('Content-Type'));
        $this->assertSame($first->getContent(), $retry->getContent());
        $this->assertSame(1, AudioGeneration::query()->count());
        $this->assertSame([60, 1, 4], [$this->creditsSpent($user), $this->requestsCounted($user), $this->secondsCounted($user)]);
        $this->assertSame([60, 1], [$this->keyCreditsSpent($key), $this->keyRequestsCounted($key)]);
        $this->assertSame(1, $this->speechCalls->gemini);
        $this->assertTrue(ApiRequestLog::query()->where('request_id', $retry->headers->get('X-Request-Id'))->value('replayed'));
    }

    /**
     * A retry is answered with what was already paid for even when the plan
     * has no credits left now: it is never refused, and never charged.
     */
    public function test_a_retry_is_served_even_when_no_credits_are_left_now(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();
        $headers = ['Idempotency-Key' => 'order-1010'];
        $first = $this->speak($plain, $voice, [], $headers)->assertOk();

        CreditPeriod::counter($user->id)->update(['total_chars' => 90000]);
        $this->assertApiError($this->speak($plain, $voice, ['text' => 'Something new.']), 402, 'quota_exceeded');

        $this->speak($plain, $voice, [], $headers)->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertHeader('X-History-Item-Id', $first->headers->get('X-History-Item-Id'))
            ->assertHeader('X-Credits-Remaining', '0');
        $this->assertSame(90000, $this->creditsSpent($user));
    }

    /**
     * The same key with a different body is refused with 422
     * idempotency_key_reused, and the second body is not generated.
     */
    public function test_the_same_key_with_a_different_body_is_refused_with_422_idempotency_key_reused(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();
        $headers = ['Idempotency-Key' => 'order-1002'];

        $this->speak($plain, $voice, ['text' => 'The first text.'], $headers)->assertOk();
        $response = $this->speak($plain, $voice, ['text' => 'A different text.'], $headers);

        $this->assertApiError($response, 422, 'idempotency_key_reused', 'invalid_request_error', 'Idempotency-Key');
        $this->assertSame(1, AudioGeneration::query()->count());
        $this->assertSame(60, $this->creditsSpent($user));
    }

    /**
     * A key belongs to the endpoint it was first sent to: the same key and
     * body sent to the background endpoint is a different request.
     */
    public function test_the_same_key_sent_to_the_other_endpoint_is_refused(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();

        $this->speak($plain, $voice, [], ['Idempotency-Key' => 'order-1003'])->assertOk();

        $this->assertApiError($this->speakAsync($plain, $voice, [], ['Idempotency-Key' => 'order-1003']), 422, 'idempotency_key_reused');
        $this->assertSame(60, $this->creditsSpent($user));
    }

    /**
     * A background retry gets the same history item back whatever its state
     * (queued, then completed), and the credits are reserved only once.
     */
    public function test_a_background_retry_returns_the_same_history_item_and_charges_once(): void
    {
        config(['queue.default' => 'database']);
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();
        $headers = ['Idempotency-Key' => 'batch-7-item-3'];

        $first = $this->speakAsync($plain, $voice, [], $headers)->assertStatus(202)->assertHeaderMissing('Idempotent-Replayed');
        $id = $first->json('history_item_id');
        $this->speakAsync($plain, $voice, [], $headers)->assertStatus(202)
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertHeader('Location', url('/v1/history/'.$id))
            ->assertJsonPath('history_item_id', $id)
            ->assertJsonPath('status', 'queued');
        $this->assertSame(1, DB::table('jobs')->count(), 'queued once');
        $this->assertSame(60, $this->creditsSpent($user), 'reserved once');

        $this->assertSame(0, Artisan::call('queue:work', ['--once' => true, '--queue' => 'high,default']));
        $this->speakAsync($plain, $voice, [], $headers)->assertStatus(202)
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('history_item_id', $id)
            ->assertJsonPath('status', 'completed');

        $this->assertSame(1, AudioGeneration::query()->count());
        $this->assertSame([60, 1], [$this->creditsSpent($user), $this->requestsCounted($user)]);
        $this->assertSame(1, $this->speechCalls->gemini);
    }

    /**
     * A retry that arrives while the first request's generation is still
     * being made gets 409 idempotent_request_in_progress, with the history
     * item to fetch later, and is not charged.
     */
    public function test_a_retry_while_the_first_generation_is_still_being_made_gets_409(): void
    {
        $user = $this->apiMember();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);
        $voice = $this->premiumVoice();
        $body = ['text' => 'Still being made.'];
        $idempotency = $this->claim($user, $voice, 'order-1004', $body);
        $running = $this->storedGeneration($user, ['status' => 'processing', 'audio_path' => null, 'api_key_id' => $key->id]);
        app(ApiIdempotency::class)->attach($idempotency, $user->id, $running);

        $response = $this->speak($plain, $voice, $body, ['Idempotency-Key' => 'order-1004']);

        $this->assertApiError($response, 409, 'idempotent_request_in_progress', 'invalid_request_error', 'Idempotency-Key')
            ->assertHeader('Retry-After', '2')
            ->assertJsonPath('error.history_item_id', $running->id);
        $this->assertStringContainsString('/v1/history/'.$running->id, (string) $response->json('error.message'));
        $this->assertSame(0, $this->creditsSpent($user));
        $this->assertSame(0, $this->speechCalls->gemini);
    }

    /**
     * A key taken by a request that ended before it recorded anything (its
     * process died) answers 409 for two minutes, then a retry takes it over
     * and is generated and charged once.
     */
    public function test_a_key_held_by_a_request_that_ended_before_charging_is_free_again_after_two_minutes(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();
        $body = ['text' => 'The first try died.'];
        $this->claim($user, $voice, 'order-1005', $body);

        $this->assertApiError($this->speak($plain, $voice, $body, ['Idempotency-Key' => 'order-1005']), 409, 'idempotent_request_in_progress')
            ->assertHeader('Retry-After', '2')
            ->assertJsonMissingPath('error.history_item_id');
        $this->assertSame(0, $this->creditsSpent($user));

        $this->travel(121)->seconds();
        $this->speak($plain, $voice, $body, ['Idempotency-Key' => 'order-1005'])->assertOk()->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame([1, 60], [AudioGeneration::query()->count(), $this->creditsSpent($user)]);
    }

    /**
     * A request refused before anything was charged (here: no credits left)
     * lets its key go, so the same retry is served once the credits are there.
     */
    public function test_a_request_refused_before_charging_lets_its_key_be_used_again(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();
        $counter = CreditPeriod::counter($user->id);
        $counter->update(['total_chars' => 90000]);
        $headers = ['Idempotency-Key' => 'order-1006'];

        $this->assertApiError($this->speak($plain, $voice, [], $headers), 402, 'quota_exceeded');
        $counter->update(['bonus_chars' => 1000]);
        $this->speak($plain, $voice, [], $headers)->assertOk()->assertHeaderMissing('Idempotent-Replayed');
        $this->speak($plain, $voice, [], $headers)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, AudioGeneration::query()->count());
        $this->assertSame(90060, $this->creditsSpent($user));
    }

    /**
     * When the first generation failed (its credits were given back), the
     * same key makes it again and the member pays once, for the audio made.
     */
    public function test_a_key_whose_generation_failed_makes_it_again_and_charges_once(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->arabicVoice();
        $body = ['text' => 'Please translate this sentence.', 'translate' => true];
        $headers = ['Idempotency-Key' => 'order-1007'];

        $this->assertApiError($this->speak($plain, $voice, $body, $headers), 502, 'generation_failed');
        $this->assertSame(0, $this->creditsSpent($user));

        $this->translatorUp = true;
        $this->speak($plain, $voice, $body, $headers)->assertOk()->assertHeaderMissing('Idempotent-Replayed');
        $this->speak($plain, $voice, $body, $headers)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(['failed', 'done'], AudioGeneration::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame([60, 1], [$this->creditsSpent($user), $this->requestsCounted($user)]);
    }

    /**
     * Deleting the history item a key made never lets a retry with that key
     * make it (and charge it) again: the retry is told it was deleted.
     */
    public function test_a_retry_after_deleting_the_item_is_not_charged_again(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();
        $headers = ['Idempotency-Key' => 'order-1010'];

        $first = $this->speak($plain, $voice, [], $headers)->assertOk();
        $this->apiDelete($plain, '/history/'.$first->headers->get('X-History-Item-Id'))->assertOk();

        $this->assertApiError($this->speak($plain, $voice, [], $headers), 410, 'idempotent_result_deleted');
        $this->travel(3)->minutes();
        $this->assertApiError($this->speak($plain, $voice, [], $headers), 410, 'idempotent_result_deleted');
        // Another body under the same key is still a reused key.
        $this->assertApiError($this->speak($plain, $voice, ['text' => 'Something else entirely.'], $headers), 422, 'idempotency_key_reused');

        $this->assertSame([60, 1], [$this->creditsSpent($user), $this->requestsCounted($user)]);
        $this->assertSame(1, $this->speechCalls->gemini);
        $this->assertSame(0, AudioGeneration::query()->count());

        // A new key makes it again, charged once more.
        $this->speak($plain, $voice, [], ['Idempotency-Key' => 'order-1011'])->assertOk();
        $this->assertSame(120, $this->creditsSpent($user));
    }

    /**
     * Another account sending the same key and body gets a generation of its
     * own, paid by itself; it never receives the first account's audio.
     */
    public function test_another_account_sending_the_same_key_gets_its_own_generation(): void
    {
        $plan = $this->apiPlan();
        $first = $this->apiMember($plan);
        $second = $this->apiMember($plan);
        $firstKey = $this->issueKey($first)['plain'];
        $secondKey = $this->issueKey($second)['plain'];
        $voice = $this->premiumVoice();
        $headers = ['Idempotency-Key' => 'shared-value-1'];

        $mine = $this->speak($firstKey, $voice, [], $headers)->assertOk();
        $theirs = $this->speak($secondKey, $voice, [], $headers)->assertOk()->assertHeaderMissing('Idempotent-Replayed');

        $theirId = (int) $theirs->headers->get('X-History-Item-Id');
        $this->assertNotSame((int) $mine->headers->get('X-History-Item-Id'), $theirId);
        $this->assertSame($second->id, (int) AudioGeneration::query()->findOrFail($theirId)->user_id);
        $this->assertSame([60, 60], [$this->creditsSpent($first), $this->creditsSpent($second)]);
        $this->assertApiError($this->apiGet($secondKey, '/history/'.$mine->headers->get('X-History-Item-Id')), 404, 'history_item_not_found');
    }

    /**
     * Keys belong to the account, so a member's keys share them: the same
     * key sent with another of the member's API keys is the same request.
     */
    public function test_a_members_api_keys_share_their_idempotency_keys(): void
    {
        $user = $this->apiMember();
        $one = $this->issueKey($user, null, null, 'One')['plain'];
        $two = $this->issueKey($user, null, null, 'Two')['plain'];
        $voice = $this->premiumVoice();
        $headers = ['Idempotency-Key' => 'order-1008'];

        $first = $this->speak($one, $voice, [], $headers)->assertOk();
        $this->speak($two, $voice, [], $headers)->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertHeader('X-History-Item-Id', $first->headers->get('X-History-Item-Id'));

        $this->assertSame(60, $this->creditsSpent($user));
    }

    /**
     * An Idempotency-Key over 255 characters is refused with 400
     * invalid_idempotency_key before anything is charged; 255 is fine.
     */
    public function test_an_idempotency_key_over_255_characters_is_refused_with_400(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();

        $this->assertApiError($this->speak($plain, $voice, [], ['Idempotency-Key' => str_repeat('k', 256)]), 400, 'invalid_idempotency_key', 'invalid_request_error', 'Idempotency-Key');
        $this->assertApiError($this->speakAsync($plain, $voice, [], ['Idempotency-Key' => str_repeat('k', 256)]), 400, 'invalid_idempotency_key');
        $this->assertNothingCharged($user);

        $this->speak($plain, $voice, [], ['Idempotency-Key' => str_repeat('k', 255)])->assertOk();
    }

    /**
     * Keys are kept for 24 hours: after that the same key is a new request,
     * generated and charged again.
     */
    public function test_after_24_hours_the_same_key_is_a_new_request(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();
        $headers = ['Idempotency-Key' => 'order-1009'];
        $this->speak($plain, $voice, [], $headers)->assertOk();

        $this->travel(23)->hours();
        $this->speak($plain, $voice, [], $headers)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        $this->travel(2)->hours();
        $this->speak($plain, $voice, [], $headers)->assertOk()->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame([2, 120], [AudioGeneration::query()->count(), $this->creditsSpent($user)]);
    }

    /**
     * A background generation that got stuck is failed and refunded by the
     * sweep; the client's retry with the same key then makes it again, and
     * the member pays for it once.
     */
    public function test_after_the_sweep_refunds_a_stuck_generation_the_same_key_makes_it_again_once(): void
    {
        config(['queue.default' => 'database']);
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();
        $headers = ['Idempotency-Key' => 'batch-9-item-1'];
        // No worker picks it up.
        $stuck = $this->speakAsync($plain, $voice, [], $headers)->assertStatus(202)->json('history_item_id');

        $this->travel(61)->minutes();
        $this->artisan('api:expire-stuck-generations')->assertSuccessful();
        $this->assertSame(0, $this->creditsSpent($user));

        $retry = $this->speakAsync($plain, $voice, [], $headers)->assertStatus(202)->assertHeaderMissing('Idempotent-Replayed');

        $this->assertNotSame($stuck, $retry->json('history_item_id'));
        $this->assertSame([60, 1], [$this->creditsSpent($user), $this->requestsCounted($user)], 'reserved once, for the new one');
    }

    /**
     * Takes the key the way a first request with this body does, without
     * finishing it (as if its process died before it recorded anything).
     *
     * @param  array<string, mixed>  $body
     * @return array{key: string, fingerprint: string}
     */
    private function claim(User $user, Voice $voice, string $key, array $body): array
    {
        $request = Request::create('/v1/text-to-speech/'.self::voiceId($voice), 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => $key,
        ], (string) json_encode($body));
        $service = app(ApiIdempotency::class);
        $idempotency = $service->keyFor($request, $user->id);
        $this->assertNotNull($idempotency);
        $this->assertNull($service->begin($idempotency, $user->id, false));

        return $idempotency;
    }

    private function arabicVoice(): Voice
    {
        return $this->premiumVoice([
            'language' => 'ar', 'gender' => 'male', 'display_name' => 'Othman',
            'internal_voice_id' => 'ar-Gemini-HD-Charon', 'provider_voice_id' => 'Charon',
        ]);
    }
}
