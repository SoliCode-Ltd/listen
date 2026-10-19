<?php

namespace Tests\Feature\Api;

use App\Models\AudioGeneration;
use App\Models\CharPackWallet;
use App\Models\Plan;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The limits a paying client runs into: the plan's credits (a hard stop,
 * never overage), its monthly and daily generations, a key's own monthly
 * budget, the per-minute rate and the generations running at once, for the
 * whole account. Each refusal has its documented status and code, and a
 * refused request is never charged.
 */
class ApiLimitsTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
    }

    /**
     * A request needing more credits than are left this period is refused
     * with 402 quota_exceeded, saying how many it needed; nothing is spent.
     */
    public function test_a_request_needing_more_credits_than_are_left_is_refused_with_402_quota_exceeded(): void
    {
        $user = $this->apiMember();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);
        CreditPeriod::counter($user->id)->update(['total_chars' => 90000 - 44]);

        $response = $this->speak($plain, $this->premiumVoice());

        $this->assertApiError($response, 402, 'quota_exceeded', 'quota_error')
            ->assertJsonPath('error.credits_needed', 45);
        $this->assertSame(90000 - 44, $this->creditsSpent($user), 'nothing more is spent');
        $this->assertSame(0, $this->requestsCounted($user));
        $this->assertSame(0, $this->keyCreditsSpent($key));
        $this->assertSame(0, AudioGeneration::query()->count());
        $this->assertSame(0, $this->speechCalls->gemini);
    }

    /**
     * A request needing exactly the credits left goes through; the next one
     * is refused, and the plan's credits are never exceeded.
     */
    public function test_the_last_credits_can_be_spent_and_then_the_plan_stops(): void
    {
        $this->audioSeconds = 3;
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();
        CreditPeriod::counter($user->id)->update(['total_chars' => 90000 - 45]);

        $this->speak($plain, $voice)->assertOk()->assertHeader('X-Credits-Remaining', '0');
        $this->assertApiError($this->speak($plain, $voice, ['text' => 'One more, please.']), 402, 'quota_exceeded');

        $this->assertSame(90000, $this->creditsSpent($user));
        $this->assertSame(1, AudioGeneration::query()->count());
    }

    /**
     * Credits Listen gave as a gift for the period add to the plan's own.
     */
    public function test_gift_credits_add_to_the_plans_credits(): void
    {
        $this->audioSeconds = 3;
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        CreditPeriod::counter($user->id)->update(['total_chars' => 90000, 'bonus_chars' => 100]);

        $this->speak($plain, $this->premiumVoice())->assertOk()->assertHeader('X-Credits-Remaining', '55');

        $this->apiGet($plain, '/user/subscription')
            ->assertJsonPath('credits.gift', 100)
            ->assertJsonPath('credits.limit', 90100)
            ->assertJsonPath('credits.remaining', 55);
    }

    /**
     * A plan's monthly number of generations is a limit too (402
     * quota_exceeded once reached).
     */
    public function test_the_plans_monthly_generations_are_a_limit_too(): void
    {
        $user = $this->apiMember($this->apiPlan(['monthly_requests' => 2]));
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();

        $this->speak($plain, $voice, ['text' => 'First of the month.'])->assertOk();
        $this->speak($plain, $voice, ['text' => 'Second of the month.'])->assertOk();
        $this->assertApiError($this->speak($plain, $voice, ['text' => 'Third of the month.']), 402, 'quota_exceeded', 'quota_error');

        $this->assertSame([2, 120], [$this->requestsCounted($user), $this->creditsSpent($user)]);
    }

    /**
     * A key with its own monthly credit limit stops at it (402
     * key_credit_limit_exceeded, with what is left on the key), while the
     * member's other keys keep working on the plan's credits.
     */
    public function test_a_keys_own_monthly_credit_limit_stops_only_that_key(): void
    {
        $user = $this->apiMember();
        ['key' => $limited, 'plain' => $limitedPlain] = $this->issueKey($user, null, 100, 'Customer A');
        $otherPlain = $this->issueKey($user, null, null, 'Internal')['plain'];
        $voice = $this->premiumVoice();

        $this->speak($limitedPlain, $voice, ['text' => 'Within the budget.'])->assertOk();
        $response = $this->speak($limitedPlain, $voice, ['text' => 'Over the budget now.']);

        $this->assertApiError($response, 402, 'key_credit_limit_exceeded', 'quota_error')
            ->assertJsonPath('error.credits_needed', 45)
            ->assertJsonPath('error.credits_remaining', 40);
        $this->assertSame(60, $this->keyCreditsSpent($limited));
        $this->speak($otherPlain, $voice, ['text' => 'Another key, no budget of its own.'])->assertOk();
        $this->assertSame(120, $this->creditsSpent($user));
        $this->apiGet($limitedPlain, '/user/subscription')
            ->assertJsonPath('api_key.monthly_credit_limit', 100)
            ->assertJsonPath('api_key.credits_used_this_month', 60);
    }

    /**
     * A plan's daily generations answer 429 daily_limit_exceeded until the
     * member's next day, which Retry-After and requests.daily_resets_at both
     * point to; generations that failed do not count.
     */
    public function test_the_daily_generation_limit_answers_429_until_the_members_next_day(): void
    {
        $user = $this->apiMember($this->apiPlan(['daily_requests' => 2]));
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();
        $this->storedGeneration($user, ['status' => 'failed', 'audio_path' => null]);

        $this->speak($plain, $voice, ['text' => 'First of the day.'])->assertOk();
        $this->speak($plain, $voice, ['text' => 'Second of the day.'])->assertOk();
        $response = $this->speak($plain, $voice, ['text' => 'Third of the day.']);

        $this->assertApiError($response, 429, 'daily_limit_exceeded', 'quota_error');
        $resetsAt = Carbon::parse($this->apiGet($plain, '/user/subscription')->assertJsonPath('requests.used_today', 2)->json('requests.daily_resets_at'));
        $this->assertTrue($resetsAt->isAfter(now()));
        $this->assertSame((string) (int) now()->diffInSeconds($resetsAt, true), $response->headers->get('Retry-After'));
        $this->assertSame(120, $this->creditsSpent($user), 'the refused one is not charged');

        $this->travelTo($resetsAt->copy()->addMinute());
        $this->speak($plain, $voice, ['text' => 'A new day.'])->assertOk();
    }

    /**
     * Generation requests over the plan's rate get 429 rate_limit_exceeded
     * with Retry-After and the X-RateLimit headers; they are not charged, and
     * a minute later the client may go on.
     */
    public function test_generation_requests_over_the_plans_rate_get_429_with_rate_limit_headers(): void
    {
        $user = $this->apiMember($this->apiPlan(['api_requests_per_minute' => 2]));
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();

        $this->speak($plain, $voice, ['text' => 'One.'])->assertOk()
            ->assertHeader('X-RateLimit-Limit', '2')->assertHeader('X-RateLimit-Remaining', '1');
        $this->speak($plain, $voice, ['text' => 'Two.'])->assertOk()
            ->assertHeader('X-RateLimit-Limit', '2')->assertHeader('X-RateLimit-Remaining', '0');
        $response = $this->speak($plain, $voice, ['text' => 'Three.']);

        $this->assertApiError($response, 429, 'rate_limit_exceeded', 'rate_limit_error')
            ->assertHeader('Retry-After', '60')
            ->assertHeader('X-RateLimit-Limit', '2')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('X-RateLimit-Reset', (string) now()->addSeconds(60)->getTimestamp());
        $this->assertSame([2, 120], [AudioGeneration::query()->count(), $this->creditsSpent($user)]);

        $this->travel(61)->seconds();
        $this->speak($plain, $voice, ['text' => 'Four, a minute later.'])->assertOk();
    }

    /**
     * The rate is for the whole account: more keys never mean more requests.
     * Another member's rate is their own.
     */
    public function test_the_rate_is_shared_by_all_of_a_members_keys_and_not_by_other_members(): void
    {
        $plan = $this->apiPlan(['api_requests_per_minute' => 1]);
        $user = $this->apiMember($plan);
        $first = $this->issueKey($user, null, null, 'First')['plain'];
        $second = $this->issueKey($user, null, null, 'Second')['plain'];
        $neighbour = $this->issueKey($this->apiMember($plan))['plain'];
        $voice = $this->premiumVoice();

        $this->speak($first, $voice)->assertOk();
        $this->assertApiError($this->speak($second, $voice), 429, 'rate_limit_exceeded');
        $this->speak($neighbour, $voice)->assertOk();
    }

    /**
     * Background requests use the same generation allowance as waiting ones.
     */
    public function test_background_requests_count_against_the_same_generation_rate(): void
    {
        $plain = $this->issueKey($this->apiMember($this->apiPlan(['api_requests_per_minute' => 2])))['plain'];
        $voice = $this->premiumVoice();

        $this->speak($plain, $voice)->assertOk();
        $this->speakAsync($plain, $voice, ['text' => 'In the background.'])->assertStatus(202);
        $this->assertApiError($this->speakAsync($plain, $voice, ['text' => 'One too many.']), 429, 'rate_limit_exceeded');
    }

    /**
     * Status checks, voices, history and the account have their own, larger
     * allowance, so polling never uses up the generations.
     */
    public function test_reads_have_their_own_allowance_apart_from_generations(): void
    {
        $plain = $this->issueKey($this->apiMember($this->apiPlan(['api_requests_per_minute' => 1])))['plain'];
        $voice = $this->premiumVoice();

        $this->apiGet($plain, '/voices')->assertOk()->assertHeader('X-RateLimit-Limit', '300')->assertHeader('X-RateLimit-Remaining', '299');
        $this->speak($plain, $voice)->assertOk()->assertHeader('X-RateLimit-Limit', '1')->assertHeader('X-RateLimit-Remaining', '0');
        $this->assertApiError($this->speak($plain, $voice), 429, 'rate_limit_exceeded');

        $this->apiGet($plain, '/history')->assertOk()->assertHeader('X-RateLimit-Remaining', '298');
        $this->apiGet($plain, '/user/subscription')->assertOk();
    }

    /**
     * With all of the plan's generations-at-once running, another one gets
     * 429 concurrency_limit_exceeded and is not charged; it goes through once
     * one has finished.
     */
    public function test_generations_at_the_same_time_are_limited_to_the_plans_concurrency(): void
    {
        $user = $this->apiMember($this->apiPlan(['api_concurrency' => 1]));
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();
        $running = Cache::lock('api-slot:'.$user->id.':1', 300);
        $this->assertTrue($running->get(), 'one generation is running');

        $response = $this->speak($plain, $voice);

        $this->assertApiError($response, 429, 'concurrency_limit_exceeded', 'rate_limit_error')
            ->assertHeader('Retry-After', '2')
            ->assertJsonPath('error.concurrency_limit', 1);
        $this->assertNothingCharged($user);

        $running->release();
        $this->speak($plain, $voice)->assertOk();
    }

    /**
     * A finished generation frees its place, so one-at-a-time plans can make
     * one generation after another.
     */
    public function test_a_finished_generation_frees_its_place(): void
    {
        $user = $this->apiMember($this->apiPlan(['api_concurrency' => 1]));
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();

        foreach (['One.', 'Two.', 'Three.'] as $text) {
            $this->speak($plain, $voice, ['text' => $text])->assertOk();
        }

        $this->assertSame(3, AudioGeneration::query()->where('status', 'done')->count());
    }

    /**
     * When every place the site keeps for waiting requests is taken, the
     * answer is 503 server_busy with Retry-After, nothing is charged, and the
     * member's own place is not kept.
     */
    public function test_when_the_whole_site_is_busy_the_answer_is_503_server_busy(): void
    {
        config(['listen.api.site_slots' => 1]);
        $user = $this->apiMember($this->apiPlan(['api_concurrency' => 1]));
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();
        $busy = Cache::lock('api-site-slot:1', 300);
        $this->assertTrue($busy->get());

        $this->assertApiError($this->speak($plain, $voice), 503, 'server_busy', 'api_error')
            ->assertHeader('Retry-After', '5');
        $this->assertNothingCharged($user);

        $busy->release();
        $this->speak($plain, $voice)->assertOk();
    }

    /**
     * The API has its share of the site's daily speech: past it, requests get
     * 503 capacity_reached (with Retry-After) and are not charged, while
     * generations already accepted are unaffected.
     */
    public function test_past_the_apis_daily_speech_share_requests_get_503_capacity_reached(): void
    {
        // A ceiling of 100 characters a day, 60 of them for the API.
        config(['listen.cost_guardrails.daily_gemini_char_limit' => 100, 'listen.api.daily_share' => 0.6]);
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();

        $this->speak($plain, $voice)->assertOk();
        $this->speak($plain, $voice, ['text' => 'Hello there, this is test 2.'])->assertOk();
        $response = $this->speak($plain, $voice, ['text' => 'Hello there, this is test 3.']);

        $this->assertApiError($response, 503, 'capacity_reached', 'api_error');
        $this->assertGreaterThanOrEqual(60, (int) $response->headers->get('Retry-After'));
        $this->assertSame([2, 120], [AudioGeneration::query()->count(), $this->creditsSpent($user)]);
    }

    /**
     * A member whose access comes from a pack is charged to the pack (by the
     * real audio, as everyone), and sees the pack's credits; the monthly
     * counter is not touched.
     */
    public function test_a_pack_is_charged_for_its_generations_and_stops_when_it_runs_out(): void
    {
        $free = Plan::factory()->free()->create(['monthly_chars' => 0, 'monthly_requests' => 60, 'voice_tier' => 'standard', 'is_active' => true]);
        $this->apiPlan(['slug' => 'pack-100', 'name' => 'Pack', 'kind' => Plan::KIND_PACK, 'price_cents' => 1500, 'pack_days' => 30]);
        $user = $this->apiMember($free);
        $wallet = CharPackWallet::query()->create(['user_id' => $user->id, 'chars_total' => 100, 'chars_used' => 0, 'expires_at' => now()->addDays(30)]);
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);
        $voice = $this->premiumVoice();
        $this->assertTrue(app(PlanAccessService::class)->resolveAccessPlanForUserId($user->id)->isPack());

        $this->speak($plain, $voice)->assertOk()
            ->assertHeader('X-Credits-Used', '60')
            ->assertHeader('X-Credits-Remaining', '40');

        $this->assertSame(60, $wallet->fresh()->chars_used);
        $this->assertSame(0, $this->creditsSpent($user), 'the monthly allowance is not used');
        $this->assertSame(60, $this->keyCreditsSpent($key));
        $this->assertTrue(AudioGeneration::query()->sole()->billed_to_pack);
        $this->apiGet($plain, '/user/subscription')
            ->assertJsonPath('billing_mode', 'pack')
            ->assertJsonPath('credits.limit', 100)
            ->assertJsonPath('credits.remaining', 40)
            ->assertJsonPath('credits.resets_at', null);

        $this->assertApiError($this->speak($plain, $voice, ['text' => 'More than the pack has left.']), 402, 'quota_exceeded', 'quota_error');
        $this->assertSame(60, $wallet->fresh()->chars_used, 'the refused one takes nothing');
    }
}
