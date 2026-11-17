<?php

namespace Tests\Feature\InApp;

use App\Models\User;
use App\Services\Auth\AppleSignIn;
use App\Services\BlockedUserService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * "Sign in with Apple" in the iPhone app (Apple's guideline 4.8). The phone
 * signs the member in and the page passes on Apple's signed token; the site
 * checks that token itself before it believes any of it. Apple is faked: the
 * tests sign their own tokens and serve their own "Apple" public key.
 */
class IphoneAppleSignInTest extends InAppTestCase
{
    private const KEY_ID = 'TESTKEY01';

    private const NONCE = 'nonce-for-this-sign-in-0123456789abcdef';

    /** @var \OpenSSLAsymmetricKey the key "Apple" signs with in these tests */
    private $appleKey;

    protected function appUserAgent(): string
    {
        return self::IPHONE_APP_UA;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlansWithEverythingOpen();
        Cache::flush();
        $this->appleKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->fakeApple();
    }

    /** Apple's key list, and (when asked) its answers about a member's link. */
    private function fakeApple(array $more = []): void
    {
        $details = openssl_pkey_get_details($this->appleKey)['rsa'];
        $b64 = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        Http::fake($more + [
            'appleid.apple.com/auth/keys' => Http::response(['keys' => [
                ['kty' => 'RSA', 'kid' => 'SOMEOTHERKEY', 'use' => 'sig', 'alg' => 'RS256', 'n' => $b64(random_bytes(256)), 'e' => 'AQAB'],
                ['kty' => 'RSA', 'kid' => self::KEY_ID, 'use' => 'sig', 'alg' => 'RS256', 'n' => $b64($details['n']), 'e' => $b64($details['e'])],
            ]]),
        ]);
    }

    /** A token as Apple signs it, with these claims changed or added. */
    private function token(array $claims = [], ?string $keyId = null, $signWith = null): string
    {
        $b64 = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $body = array_filter($claims + [
            'iss' => 'https://appleid.apple.com',
            'aud' => 'studio.listenai.app',
            'exp' => time() + 600,
            'iat' => time(),
            'sub' => '001234.abcdef0123456789abcdef0123456789.0123',
            'nonce' => self::NONCE,
            'email' => 'fatima@example.test',
            'email_verified' => 'true',
        ], fn ($value) => $value !== null);
        $unsigned = $b64((string) json_encode(['alg' => 'RS256', 'kid' => $keyId ?? self::KEY_ID])).'.'.$b64((string) json_encode($body));
        openssl_sign($unsigned, $signature, $signWith ?? $this->appleKey, OPENSSL_ALGO_SHA256);

        return $unsigned.'.'.$b64($signature);
    }

    /** The page's request after the phone has signed the member in. */
    private function signIn(string $token, array $more = [], ?string $userAgent = null, string $nonce = self::NONCE): TestResponse
    {
        return $this->as(null, 'en', $userAgent ?? self::IPHONE_APP_UA)
            ->withSession([AppleSignIn::NONCE_SESSION => $nonce])
            ->postJson($this->localizedRoute('auth.apple.verify'), ['identity_token' => $token] + $more);
    }

    /** Signed out as a member does it: the session is gone too. */
    private function signOut(): void
    {
        auth()->logout();
        $this->flushSession();
        app('auth')->forgetGuards();
    }

    public function test_a_new_member_joins_with_apple_and_lands_in_the_app(): void
    {
        $response = $this->signIn($this->token(), ['given_name' => 'Fatima', 'family_name' => 'Al Saud']);

        $response->assertOk()->assertJson(['ok' => true]);
        $member = User::query()->where('email', 'fatima@example.test')->sole();
        $this->assertAuthenticatedAs($member);
        $this->assertSame('Fatima Al Saud', $member->name);
        $this->assertSame('001234.abcdef0123456789abcdef0123456789.0123', $member->apple_id);
        $this->assertNotNull($member->email_verified_at);
        // A page of the site, as after any other sign-in, and never a purchase page.
        $this->assertStringStartsWith(url('/'), (string) $response->json('redirect'));
        $this->assertDoesNotMatchRegularExpression('#pricing|billing#', (string) $response->json('redirect'));
        // The new member has the free plan, like everyone who joins.
        $this->assertTrue($member->planSubscriptions()->exists());
    }

    public function test_the_same_person_signs_in_again_to_the_same_account_even_with_a_hidden_email(): void
    {
        $this->signIn($this->token(['email' => 'x7k2p9@privaterelay.appleid.com']), ['given_name' => 'Omar'])->assertOk();
        $first = User::query()->sole();
        $this->signOut();

        // Apple gives the name only the first time, and may give another relay address later:
        // the person is known by Apple's id for them.
        $this->signIn($this->token(['email' => 'other@privaterelay.appleid.com']))->assertOk();

        $this->assertSame(1, User::query()->count());
        $this->assertAuthenticatedAs($first->fresh());
        $this->assertSame('Omar', $first->fresh()->name);
        $this->assertSame('x7k2p9@privaterelay.appleid.com', $first->fresh()->email);
    }

    public function test_a_member_who_joined_another_way_is_joined_to_their_apple_identity_by_a_verified_email(): void
    {
        $member = $this->memberOn('starter');
        $member->forceFill(['email' => 'fatima@example.test', 'google_id' => 'g-123'])->save();

        $this->signIn($this->token())->assertOk();

        $this->assertSame(1, User::query()->where('email', 'fatima@example.test')->count());
        $this->assertAuthenticatedAs($member->fresh());
        $this->assertSame('001234.abcdef0123456789abcdef0123456789.0123', $member->fresh()->apple_id);
        $this->assertSame('g-123', $member->fresh()->google_id);

        // An address Apple did not verify never opens someone else's account.
        $this->signOut();
        $other = $this->memberOn('starter');
        $other->forceFill(['email' => 'victim@example.test'])->save();
        $this->signIn($this->token(['sub' => '009999.other', 'email' => 'victim@example.test', 'email_verified' => 'false']))->assertStatus(422);
        $this->assertGuest();
        $this->assertNull($other->fresh()->apple_id);
    }

    public function test_a_token_that_is_not_apples_or_not_for_this_app_signs_nobody_in(): void
    {
        $stranger = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $bad = [
            'signed by someone else' => $this->token([], null, $stranger),
            'a key Apple does not list' => $this->token([], 'UNKNOWNKEY'),
            'made for another app' => $this->token(['aud' => 'com.someone.else']),
            'from another issuer' => $this->token(['iss' => 'https://evil.example']),
            'expired' => $this->token(['exp' => time() - 3600]),
            'another sign-in\'s answer' => $this->token(['nonce' => 'a-different-nonce']),
            'no nonce at all' => $this->token(['nonce' => null]),
            'names nobody' => $this->token(['sub' => '']),
            'not a token' => 'not.a.token',
            'unsigned' => rtrim(strtr(base64_encode('{"alg":"none","kid":"'.self::KEY_ID.'"}'), '+/', '-_'), '=').'.'.explode('.', $this->token())[1].'.',
        ];

        foreach ($bad as $why => $token) {
            $this->signIn($token)->assertStatus(422)->assertJson(['ok' => false, 'message' => __('app.signin.apple_failed')]);
            $this->assertFalse(auth()->check(), $why);
        }
        // A page that was never given a nonce cannot sign anyone in either.
        $this->signIn($this->token(['nonce' => '']), [], null, '')->assertStatus(422);
        $this->assertSame(0, User::query()->count());
    }

    public function test_the_answer_is_good_for_one_sign_in_only(): void
    {
        $token = $this->token();

        $this->signIn($token)->assertOk();
        $this->signOut();
        // The same answer again, in a session whose one-time value was used up.
        $this->as(null, 'en', self::IPHONE_APP_UA)->withSession([])
            ->postJson($this->localizedRoute('auth.apple.verify'), ['identity_token' => $token])->assertStatus(422);
        $this->assertGuest();
    }

    public function test_only_the_iphone_app_can_sign_in_this_way(): void
    {
        foreach ([self::APP_UA, self::BROWSER_UA, self::IPHONE_BROWSER_UA] as $userAgent) {
            $this->signIn($this->token(), [], $userAgent)->assertStatus(403);
            $this->assertGuest();
        }
        // And not when the iPhone app's other sign-ins are switched off.
        config(['services.ios_app.social_sign_in' => false]);
        $this->signIn($this->token())->assertStatus(403);
        $this->assertSame(0, User::query()->count());
    }

    public function test_a_blocked_member_is_not_signed_in(): void
    {
        $member = $this->memberOn('starter');
        $member->forceFill(['email' => 'fatima@example.test'])->save();
        app(BlockedUserService::class)->block($member, 'Abuse');

        $this->signIn($this->token())->assertStatus(403)->assertJson(['ok' => false]);
        $this->assertGuest();
    }

    public function test_the_sign_in_page_gives_the_page_script_what_it_needs_and_a_member_none_of_it(): void
    {
        $page = (string) $this->inApp(null)->page('login')->assertOk()->getContent();
        $this->assertSame(1, preg_match('/"apple":\{"url":"([^"]+)","nonce":"([A-Za-z0-9]{40})"\}/', $page, $m));
        $this->assertStringContainsString('auth\/apple\/verify', $m[1]);
        // The same visitor gets the same one-time value until it is used.
        $this->assertSame($m[2], session(AppleSignIn::NONCE_SESSION));

        $member = $this->memberOn('starter');
        $this->assertStringNotContainsString('"apple":{', (string) $this->inApp($member)->page('app.plan')->assertOk()->getContent());
        $this->assertStringNotContainsString('"apple":{', (string) $this->as(null, 'en', self::APP_UA)->page('login')->assertOk()->getContent());
    }

    public function test_with_the_apple_key_set_the_site_keeps_what_it_needs_to_end_the_link_later(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        config(['services.apple_sign_in.team_id' => 'A1B2C3D4E5', 'services.apple_sign_in.key_id' => 'KEY1234567', 'services.apple_sign_in.private_key' => base64_encode($pem)]);
        $this->fakeApple([
            'appleid.apple.com/auth/token' => Http::response(['refresh_token' => 'r.token.from.apple', 'access_token' => 'a']),
            'appleid.apple.com/auth/revoke' => Http::response('', 200),
        ]);

        $this->signIn($this->token(), ['authorization_code' => 'c0de.from.the.phone'])->assertOk();

        $member = User::query()->sole();
        $this->assertSame('r.token.from.apple', $member->apple_refresh_token);
        // Kept encrypted, and never shown with the member.
        $this->assertStringNotContainsString('r.token.from.apple', (string) \DB::table('users')->value('apple_refresh_token'));
        $this->assertArrayNotHasKey('apple_refresh_token', $member->toArray());
        Http::assertSent(function (HttpRequest $request): bool {
            if (! str_contains($request->url(), '/auth/token')) {
                return false;
            }
            // The site says who it is with a token signed by the account's key, for this app.
            [$header, $claims, $signature] = explode('.', (string) $request['client_secret']);
            $decode = fn (string $part): array => (array) json_decode((string) base64_decode(strtr($part, '-_', '+/')), true);

            return $request['code'] === 'c0de.from.the.phone' && $request['client_id'] === 'studio.listenai.app'
                && $request['grant_type'] === 'authorization_code'
                && $decode($header) === ['alg' => 'ES256', 'kid' => 'KEY1234567']
                && $decode($claims)['iss'] === 'A1B2C3D4E5' && $decode($claims)['sub'] === 'studio.listenai.app'
                && $decode($claims)['aud'] === 'https://appleid.apple.com'
                && strlen((string) base64_decode(strtr($signature, '-_', '+/'))) === 64;
        });

        // Ending the link later.
        $this->assertTrue(app(AppleSignIn::class)->revoke('r.token.from.apple'));
        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/auth/revoke') && $request['token'] === 'r.token.from.apple');
    }

    public function test_deleting_the_account_tells_apple_to_forget_the_link(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        config(['services.apple_sign_in.team_id' => 'A1B2C3D4E5', 'services.apple_sign_in.key_id' => 'KEY1234567', 'services.apple_sign_in.private_key' => base64_encode($pem)]);
        $this->fakeApple([
            'appleid.apple.com/auth/token' => Http::response(['refresh_token' => 'r.token.to.end']),
            'appleid.apple.com/auth/revoke' => Http::response('', 200),
        ]);
        $this->signIn($this->token(), ['authorization_code' => 'c0de'])->assertOk();
        $member = User::query()->sole();

        $this->inApp($member)->post($this->localizedRoute('account.delete.confirm'), ['confirm' => '1'])
            ->assertRedirect($this->localizedRoute('account.deleted'));

        $this->assertSame(0, User::query()->count());
        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/auth/revoke')
            && $request['token'] === 'r.token.to.end' && $request['token_type_hint'] === 'refresh_token');

        // Apple being unreachable never keeps an account from being deleted.
        $this->signOut();
        $this->fakeApple(['appleid.apple.com/auth/token' => Http::response(['refresh_token' => 'r.2'])]);
        $this->signIn($this->token(['sub' => '002222.second', 'email' => 'second@example.test']), ['authorization_code' => 'c0de'])->assertOk();
        Http::fake(['appleid.apple.com/auth/revoke' => Http::response('', 500)]);
        $this->inApp(User::query()->sole())->post($this->localizedRoute('account.delete.confirm'), ['confirm' => '1'])->assertRedirect();
        $this->assertSame(0, User::query()->count());
    }

    public function test_without_the_apple_key_signing_in_still_works(): void
    {
        config(['services.apple_sign_in.team_id' => '', 'services.apple_sign_in.key_id' => '', 'services.apple_sign_in.private_key' => '']);

        $this->signIn($this->token(), ['authorization_code' => 'c0de'])->assertOk();

        $this->assertNull(User::query()->sole()->apple_refresh_token);
        $this->assertFalse(app(AppleSignIn::class)->revoke('anything'));
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/auth/token') || str_contains($request->url(), '/auth/revoke'));
    }

    public function test_apples_keys_are_fetched_again_when_a_token_names_a_new_one(): void
    {
        // The site holds an older list that does not have today's key.
        Cache::put('listen:apple_signin_keys', [['kty' => 'RSA', 'kid' => 'LASTYEAR', 'n' => 'AQAB', 'e' => 'AQAB']], now()->addDay());

        $this->signIn($this->token())->assertOk();

        Http::assertSentCount(1);
    }
}
