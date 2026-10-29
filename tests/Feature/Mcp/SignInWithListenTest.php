<?php

namespace Tests\Feature\Mcp;

use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\Plan;
use App\Models\User;
use App\Support\Credits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\InteractsWithDeveloperApi;
use Tests\TestCase;

/**
 * "Sign in with Listen": an AI app registers itself, the member signs in and
 * allows it on Listen's page, and the app gets a token for the MCP server
 * (OAuth 2.1 with PKCE). The app then works like a key of its own, and
 * disconnecting it in the dashboard ends its access at once. Paid plans
 * with the premium voices only.
 */
class SignInWithListenTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    private const REDIRECT = 'https://app.example/oauth/callback';

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
        $this->plan = $this->apiPlan();

        // A signing key pair for this test run only.
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        config([
            'passport.private_key' => $private,
            'passport.public_key' => openssl_pkey_get_details($key)['key'],
        ]);
    }

    public function test_apps_find_where_to_sign_in(): void
    {
        $refused = $this->mcp(null, 'tools/list')->assertStatus(401);
        $this->assertSame('Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"', $refused->headers->get('WWW-Authenticate'));
        $this->assertStringContainsString('Sign in with Listen', (string) $refused->json('error.message'));

        $this->get('/.well-known/oauth-protected-resource/mcp')
            ->assertOk()
            ->assertJsonPath('resource', url('/mcp'))
            ->assertJsonPath('authorization_servers', [url('/')]);

        $this->get('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('authorization_endpoint', url('/oauth/authorize'))
            ->assertJsonPath('token_endpoint', url('/oauth/token'))
            ->assertJsonPath('registration_endpoint', url('/oauth/register'))
            ->assertJsonPath('code_challenge_methods_supported', ['S256']);
    }

    public function test_an_app_registers_with_an_https_or_app_address_only(): void
    {
        $this->postJson('/oauth/register', ['client_name' => 'Example AI', 'redirect_uris' => [self::REDIRECT]])
            ->assertCreated()
            ->assertJsonPath('token_endpoint_auth_method', 'none');
        $this->postJson('/oauth/register', ['client_name' => 'Desktop app', 'redirect_uris' => ['cursor://callback/oauth']])->assertCreated();
        $this->postJson('/oauth/register', ['client_name' => 'Local tool', 'redirect_uris' => ['http://127.0.0.1:33418/callback']])->assertCreated();

        $this->postJson('/oauth/register', ['client_name' => 'Plain web', 'redirect_uris' => ['http://app.example/callback']])
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_redirect_uri');
        $this->postJson('/oauth/register', ['client_name' => 'Odd scheme', 'redirect_uris' => ['javascript://alert']])->assertStatus(400);
    }

    public function test_a_member_allows_an_app_and_it_makes_speech_on_their_credits(): void
    {
        $user = $this->member();
        $clientId = $this->registerApp('Example AI');

        // Not signed in: the sign-in page first, and back here after.
        $this->get($this->authorizeUrl($clientId, $this->challenge('v')))->assertRedirect();

        $page = $this->actingAs($user)->get($this->authorizeUrl($clientId, $this->challenge($verifier = Str::random(64))))
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertSee('Allow Example AI to use your Listen account?')
            ->assertSee('app.example')
            ->assertSee($user->email);

        $tokens = $this->exchange($clientId, $this->approve($page), $verifier)->assertOk()->json();
        $this->assertSame(3600, $tokens['expires_in']);
        $this->assertNotEmpty($tokens['refresh_token']);

        // The app now shows as a connected app, with what a connected app may do.
        $connected = ApiKey::query()->connectedApps()->sole();
        $this->assertSame([(int) $user->id, $clientId, 'Example AI'], [(int) $connected->user_id, $connected->oauth_client_id, $connected->name]);
        $this->assertFalse($connected->allows('history_write'));

        $tools = array_column($this->mcp($tokens['access_token'], 'tools/list')->assertOk()->json('result.tools'), 'name');
        $this->assertContains('text_to_speech', $tools);

        $this->mcp($tokens['access_token'], 'tools/call', ['name' => 'text_to_speech', 'arguments' => ['text' => 'Hello there, this is a test.', 'voice_id' => self::voiceId($this->premiumVoice())]])
            ->assertOk()
            ->assertJsonPath('result.isError', false);
        $this->assertSame(Credits::forSeconds($this->audioSeconds), $this->keyCreditsSpent($connected));
        $this->assertSame(Credits::forSeconds($this->audioSeconds), $this->creditsSpent($user));
        $this->assertSame((int) $connected->id, (int) ApiRequestLog::query()->where('endpoint', 'mcp.text_to_speech')->sole()->api_key_id);

        // The app renews its access without the member.
        $renewed = $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $clientId])->assertOk()->json();
        $this->mcp($renewed['access_token'], 'tools/list')->assertOk();
        $this->assertSame(1, ApiKey::query()->connectedApps()->count());
    }

    public function test_disconnecting_an_app_ends_its_access_at_once(): void
    {
        $user = $this->member();
        $tokens = $this->connect($user, $this->registerApp('Example AI'));
        $other = $this->connect($user, $this->registerApp('Another app'));
        $this->assertSame(2, ApiKey::query()->connectedApps()->active()->count());

        $app = ApiKey::query()->where('name', 'Example AI')->sole();
        $this->actingAs($user)->delete(route('developers.keys.revoke', $app))->assertRedirect();

        $this->mcp($tokens['access_token'], 'tools/list')->assertStatus(401)->assertJsonPath('error.data.code', 'invalid_token');
        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $app->oauth_client_id])->assertStatus(400);
        // The other app is not touched.
        $this->mcp($other['access_token'], 'tools/list')->assertOk();

        // Allowing it again connects it again, as a new connection.
        $again = $this->connect($user, (string) $app->oauth_client_id);
        $this->mcp($again['access_token'], 'tools/list')->assertOk();
        $this->assertSame(2, ApiKey::query()->connectedApps()->active()->count());
    }

    public function test_only_paid_plans_with_the_premium_voices_can_connect_apps(): void
    {
        $free = $this->apiMember($this->apiPlan(['slug' => 'free-test', 'price_cents' => 0, 'api_access_enabled' => false]));
        $clientId = $this->registerApp('Example AI');

        $page = $this->actingAs($free)->get($this->authorizeUrl($clientId, $this->challenge($verifier = Str::random(64))))
            ->assertOk()
            ->assertSee('Your plan doesn&#039;t include AI apps yet', false)
            ->assertDontSee('id="connectAllow"', false);

        // Even an approval sent by hand gets nothing the server will serve.
        $tokens = $this->exchange($clientId, $this->approve($page), $verifier)->json();
        $this->mcp($tokens['access_token'], 'tools/list')->assertStatus(403)->assertJsonPath('error.data.code', 'apps_not_in_plan');

        // A paid plan without the developer API can connect apps.
        $paid = $this->apiMember($this->apiPlan(['slug' => 'creator-no-api', 'api_access_enabled' => false]));
        $this->mcp($this->connect($paid, $clientId)['access_token'], 'tools/list')->assertOk();
        // Its keys still need a plan with the API.
        $this->mcp($this->issueKey($paid)['plain'], 'tools/list')->assertStatus(403)->assertJsonPath('error.data.code', 'api_access_not_in_plan');
    }

    public function test_the_dashboard_lists_connected_apps_apart_from_keys(): void
    {
        $user = $this->member();
        $this->issueKey($user, null, null, 'Server key');
        $this->connect($user, $this->registerApp('Example AI'));

        $page = $this->actingAs($user)->getLocalizedRoute('dashboard')->assertOk();
        $page->assertSee('Connected AI apps')->assertSee('Example AI')->assertSee('Disconnect');
        $this->assertSame(1, ApiKey::query()->issued()->active()->count());
    }

    private function member(): User
    {
        return $this->apiMember($this->plan);
    }

    private function registerApp(string $name): string
    {
        return (string) $this->postJson('/oauth/register', ['client_name' => $name, 'redirect_uris' => [self::REDIRECT]])->assertCreated()->json('client_id');
    }

    private function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function authorizeUrl(string $clientId, string $challenge): string
    {
        return '/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => 'state-123',
            'scope' => 'mcp:use',
            'resource' => url('/mcp'),
        ]);
    }

    /** Presses "Allow" on the consent page and returns the code the app receives. */
    private function approve(TestResponse $page): string
    {
        preg_match('/name="auth_token" value="([^"]+)"/', (string) $page->getContent(), $match);
        $redirect = $this->post('/oauth/authorize', ['auth_token' => $match[1] ?? ''])->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(self::REDIRECT.'?', (string) $redirect);
        parse_str((string) parse_url((string) $redirect, PHP_URL_QUERY), $query);
        $this->assertSame('state-123', $query['state'] ?? null);

        return (string) ($query['code'] ?? '');
    }

    private function exchange(string $clientId, string $code, string $verifier): TestResponse
    {
        return $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $verifier,
            'code' => $code,
        ]);
    }

    /** @return array{access_token: string, refresh_token: string} */
    private function connect(User $user, string $clientId): array
    {
        $page = $this->actingAs($user)->get($this->authorizeUrl($clientId, $this->challenge($verifier = Str::random(64))))->assertOk();

        return $this->exchange($clientId, $this->approve($page), $verifier)->assertOk()->json();
    }

    /** @param  array<string, mixed>  $params */
    private function mcp(?string $token, string $method, array $params = []): TestResponse
    {
        $headers = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method] + ($params === [] ? [] : ['params' => $params]), $headers);
    }
}
