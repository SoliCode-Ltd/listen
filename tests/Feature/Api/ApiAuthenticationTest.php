<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\BillingTrial;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Support\Api\ApiPlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who may call the developer API (/v1), and how a refused or failed call is
 * answered: the documented status and code, always in the same JSON error
 * shape with a request id, never an HTML page and never a charge.
 */
class ApiAuthenticationTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
    }

    /**
     * A request without a key is told how to send one, in the API's error
     * shape, and is never cached.
     */
    public function test_a_request_without_a_key_is_refused_with_401_missing_api_key(): void
    {
        $response = $this->apiGet(null, '/user');

        $this->assertApiError($response, 401, 'missing_api_key', 'authentication_error');
        $this->assertNull($response->json('error.param'));
        $this->assertStringContainsString('X-Listen-Api-Key', (string) $response->json('error.message'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * The key in the X-Listen-Api-Key header, the way the docs show first.
     * Account answers carry a request id and are never cached.
     */
    public function test_the_key_is_accepted_in_the_x_listen_api_key_header(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];

        $response = $this->apiGet($plain, '/user')->assertOk()->assertJsonPath('user_id', $user->id);

        $response->assertHeader('Content-Type', 'application/json');
        $this->assertStringStartsWith('req_', (string) $response->headers->get('X-Request-Id'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * The standard "Authorization: Bearer" header works too, as documented.
     */
    public function test_the_key_is_accepted_as_a_bearer_token(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];

        $this->get('/v1/user', ['Authorization' => 'Bearer '.$plain])
            ->assertOk()
            ->assertJsonPath('user_id', $user->id);
    }

    /**
     * Keys are read from those two headers only: a key in the query string
     * (?api_key=, which would end up in server and proxy logs) or in the
     * browser extension's X-API-Key header is not used.
     */
    public function test_a_key_in_the_query_string_or_another_header_is_not_accepted(): void
    {
        $plain = $this->issueKey($this->apiMember())['plain'];

        $this->assertApiError($this->get('/v1/user?api_key='.$plain), 401, 'missing_api_key');
        $this->assertApiError($this->get('/v1/user', ['X-API-Key' => $plain]), 401, 'missing_api_key');
    }

    /**
     * Something that is not a Listen key, and a well-formed key that was never
     * issued, are both refused as invalid.
     */
    public function test_a_malformed_or_unknown_key_is_refused_with_401_invalid_api_key(): void
    {
        $this->apiMember();

        $this->assertApiError($this->apiGet('not-a-listen-key', '/user'), 401, 'invalid_api_key', 'authentication_error');
        $this->assertApiError($this->apiGet(ApiKey::PREFIX.str_repeat('x', 40), '/user'), 401, 'invalid_api_key');
    }

    /**
     * A revoked key stops at once; the member's other keys keep working.
     */
    public function test_a_revoked_key_is_refused_and_the_members_other_keys_keep_working(): void
    {
        $user = $this->apiMember();
        ['key' => $revoked, 'plain' => $revokedPlain] = $this->issueKey($user, null, null, 'Old app');
        $otherPlain = $this->issueKey($user, null, null, 'New app')['plain'];
        $this->apiGet($revokedPlain, '/user')->assertOk();

        $revoked->revoke();

        $this->assertApiError($this->apiGet($revokedPlain, '/user'), 401, 'invalid_api_key');
        $this->apiGet($otherPlain, '/user')->assertOk();
    }

    /**
     * A suspended member's keys are refused, and nothing is generated or charged.
     */
    public function test_a_blocked_members_key_is_refused_with_403_account_suspended(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();
        $user->forceFill(['is_blocked' => true, 'blocked_at' => now()])->save();

        $this->assertApiError($this->apiGet($plain, '/user'), 403, 'account_suspended', 'permission_error');
        $this->assertApiError($this->speak($plain, $voice), 403, 'account_suspended');
        $this->assertNothingCharged($user);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function plansWithoutTheApi(): array
    {
        return [
            'the API switched off on the plan' => [['api_access_enabled' => false]],
            'a free plan, whatever its switch says' => [['price_cents' => 0]],
            'a plan on the standard voices' => [['voice_tier' => 'standard']],
            'a plan without commercial use' => [['commercial_use_allowed' => false]],
        ];
    }

    /**
     * The API is for paying clients on the premium voices with commercial use;
     * any other plan is refused before anything is generated or charged.
     *
     * @param  array<string, mixed>  $plan
     */
    #[DataProvider('plansWithoutTheApi')]
    public function test_a_plan_without_the_api_is_refused_with_403_api_access_not_in_plan(array $plan): void
    {
        $user = $this->apiMember($this->apiPlan($plan));
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();

        $this->assertApiError($this->apiGet($plain, '/voices'), 403, 'api_access_not_in_plan', 'permission_error');
        $this->assertApiError($this->speak($plain, $voice), 403, 'api_access_not_in_plan');
        $this->assertNothingCharged($user);
    }

    /**
     * Once the paid period is over and nothing renewed it, the member's keys
     * stop working.
     */
    public function test_a_key_stops_working_when_the_paid_plan_has_ended(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $this->apiGet($plain, '/user')->assertOk();

        Subscription::query()->where('user_id', $user->id)->update(['current_period_end' => now()->subDay()]);

        $this->assertApiError($this->apiGet($plain, '/user'), 403, 'api_access_not_in_plan');
    }

    /**
     * During an unpaid free trial the API is refused: it starts after the
     * first payment, as the docs say.
     */
    public function test_an_unpaid_trial_is_refused_with_403_api_not_available_during_trial(): void
    {
        $plan = $this->apiPlan();
        $user = $this->apiMember($plan);
        $plain = $this->issueKey($user)['plain'];
        BillingTrial::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'quota_plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
            'status' => BillingTrial::STATUS_TRIALING,
            'amount_cents' => 2900,
            'started_at' => now(),
            'trial_ends_at' => now()->addDays(7),
        ]);

        $this->assertApiError($this->apiGet($plain, '/user'), 403, 'api_not_available_during_trial', 'permission_error');
        $this->assertApiError($this->speak($plain, $this->premiumVoice()), 403, 'api_not_available_during_trial');
        $this->assertNothingCharged($user);
    }

    /**
     * The admin's pause switch answers every call with 503 api_paused and a
     * Retry-After, and generates nothing.
     */
    public function test_the_admins_pause_switch_answers_503_api_paused(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();
        SiteSetting::set(ApiPlanAccess::SITE_SWITCH, '0', 'api');

        $this->assertApiError($this->apiGet($plain, '/user'), 503, 'api_paused', 'api_error')
            ->assertHeader('Retry-After', '300');
        $this->assertApiError($this->speak($plain, $voice), 503, 'api_paused');
        $this->assertNothingCharged($user);

        SiteSetting::set(ApiPlanAccess::SITE_SWITCH, '1', 'api');
        $this->apiGet($plain, '/user')->assertOk();
    }

    /**
     * After 30 refused calls in a minute from one address, the next is told to
     * wait (429 too_many_failed_attempts); a valid key from that address keeps
     * working, and the address is let in again a minute later.
     */
    public function test_too_many_refused_requests_from_one_address_get_429_while_a_valid_key_still_works(): void
    {
        $plain = $this->issueKey($this->apiMember())['plain'];

        for ($i = 1; $i <= 30; $i++) {
            $this->apiGet(ApiKey::PREFIX.'guess'.$i, '/user')->assertStatus(401);
        }

        $this->assertApiError($this->apiGet(ApiKey::PREFIX.'guess31', '/user'), 429, 'too_many_failed_attempts', 'rate_limit_error')
            ->assertHeader('Retry-After', '60');
        $this->assertApiError($this->apiGet(null, '/user'), 429, 'too_many_failed_attempts');
        $this->apiGet($plain, '/user')->assertOk();

        $this->travel(61)->seconds();
        $this->assertApiError($this->apiGet(ApiKey::PREFIX.'guess32', '/user'), 401, 'invalid_api_key');
    }

    /**
     * Paths and methods the API does not have are answered in the API's JSON
     * error shape, never with the site's HTML error page.
     */
    public function test_unknown_paths_and_wrong_methods_get_json_errors(): void
    {
        $plain = $this->issueKey($this->apiMember())['plain'];
        $voice = $this->premiumVoice();

        $this->assertApiError($this->apiGet($plain, '/no-such-endpoint'), 404, 'not_found', 'invalid_request_error');
        $this->assertApiError($this->apiGet($plain, '/text-to-speech/'.self::voiceId($voice)), 405, 'method_not_allowed')
            ->assertHeader('Allow', 'POST');
    }

    /**
     * Something failing on the server is answered with 500 internal_error in
     * the API's shape, with the request id for support and none of the
     * failure's own details.
     */
    public function test_an_unexpected_failure_is_answered_with_500_internal_error_and_no_details(): void
    {
        $plain = $this->issueKey($this->apiMember())['plain'];
        Schema::drop('voices');

        $response = $this->apiGet($plain, '/voices');

        $this->assertApiError($response, 500, 'internal_error', 'api_error');
        $this->assertStringContainsString('request_id', (string) $response->json('error.message'));
        foreach (['voices', 'SQL', 'sqlite', 'Exception'] as $detail) {
            $this->assertStringNotContainsStringIgnoringCase($detail, (string) $response->json('error.message'));
        }
    }

    /**
     * Messages are in English whatever language the site is shown in, so
     * client code can rely on them.
     */
    public function test_error_messages_are_in_english_whatever_the_site_language(): void
    {
        $plain = $this->issueKey($this->apiMember())['plain'];
        $voice = $this->premiumVoice();
        app()->setLocale('ar');

        $response = $this->speak($plain, $voice, ['voice_settings' => ['speed' => 3]]);

        $this->assertApiError($response, 422, 'validation_failed', 'invalid_request_error', 'voice_settings.speed');
        $this->assertSame('The voice settings.speed field must not be greater than 2.', $response->json('error.message'));
    }

    /**
     * Every call is kept in the admin's request log, refused ones too, so
     * support can find a client's request by its X-Request-Id.
     */
    public function test_every_request_is_logged_for_the_admin_with_its_outcome(): void
    {
        $user = $this->apiMember();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);

        $refused = $this->apiGet(ApiKey::PREFIX.'wrong', '/user');
        $served = $this->apiGet($plain, '/user/subscription');

        $this->assertDatabaseHas('api_request_logs', [
            'request_id' => $refused->headers->get('X-Request-Id'),
            'user_id' => null,
            'status' => 401,
            'error_code' => 'invalid_api_key',
            'path' => '/v1/user',
        ]);
        $log = ApiRequestLog::query()->where('request_id', $served->headers->get('X-Request-Id'))->firstOrFail();
        $this->assertSame($user->id, (int) $log->user_id);
        $this->assertSame($key->id, (int) $log->api_key_id);
        $this->assertSame('v1.user.subscription', $log->endpoint);
        $this->assertSame(200, $log->status);
        $this->assertNull($log->error_code);
    }

    /**
     * Using a key notes when (and from where) it was last used, for the
     * member's dashboard.
     */
    public function test_using_a_key_records_when_it_was_last_used(): void
    {
        ['key' => $key, 'plain' => $plain] = $this->issueKey($this->apiMember());
        $this->assertNull($key->last_used_at);

        $this->apiGet($plain, '/user')->assertOk();

        $key->refresh();
        $this->assertTrue($key->last_used_at->equalTo(now()));
        $this->assertSame('127.0.0.1', $key->last_used_ip);
    }
}
