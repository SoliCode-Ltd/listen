<?php

namespace Tests\Feature\InApp;

use App\Models\AppDevice;
use App\Models\AudioGeneration;
use App\Models\User;
use App\Services\Push\ApnsSender;
use App\Services\Push\AppPush;
use App\Services\Push\FcmSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The iPhone app: links to the site open in it once the site names the app
 * (/.well-known/apple-app-site-association), and "your voice is ready" reaches
 * an iPhone through Apple's own push service, signed with the Apple
 * developer account's key. Android phones keep going through Firebase.
 */
class IphoneLinksAndPushTest extends InAppTestCase
{
    /** Apple's token for a phone: 64 hexadecimal characters. */
    private const IPHONE_TOKEN = '0a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9';

    private const ANDROID_TOKEN = 'fcm-token_AAAA:APA91bHPRgkF3JUikC4ENAHEeMrd41Zxv3hVZjC9KtT8OvPVGJ-hQMRKRrZuJAEcl7B338qju59zJMjw2DELjzEvxwYv7hH5Ynpc1ODQ0';

    /** The public half of the key the test signs with, to check Apple could verify it. */
    private string $publicKey = '';

    private function configureApple(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $this->publicKey = (string) openssl_pkey_get_details($key)['key'];
        config([
            'services.apns.team_id' => 'A1B2C3D4E5',
            'services.apns.key_id' => 'KEY1234567',
            // As the owner pastes it into .env: the file's content as base64, on one line.
            'services.apns.private_key' => base64_encode($pem),
            'services.apns.bundle_id' => 'studio.listenai.app',
            'services.apns.sandbox' => false,
        ]);
    }

    private function iphone(User $member, string $locale = 'ar', string $token = self::IPHONE_TOKEN): AppDevice
    {
        return AppDevice::create([
            'user_id' => $member->id, 'platform' => 'ios', 'token_hash' => AppDevice::hashOf($token),
            'token' => $token, 'locale' => $locale, 'last_seen_at' => now(),
        ]);
    }

    private function longVoice(User $member): AudioGeneration
    {
        $generation = AudioGeneration::create([
            'user_id' => $member->id, 'input_text' => 'مرحبا', 'source_language' => 'ar',
            'target_language' => 'ar', 'voice_tier' => 'hd', 'voice_name' => 'Kore', 'status' => 'done',
        ]);
        $generation->forceFill(['created_at' => now()->subSeconds(90)])->save();

        return $generation;
    }

    /** @return list<HttpRequest> what was sent to Apple */
    private function sentToApple(): array
    {
        return collect(Http::recorded())->map(fn (array $pair): HttpRequest => $pair[0])
            ->filter(fn (HttpRequest $request): bool => str_contains($request->url(), 'push.apple.com'))->values()->all();
    }

    public function test_no_iphone_app_is_named_until_the_team_id_is_set(): void
    {
        config(['services.apns.team_id' => '']);

        $this->get('/.well-known/apple-app-site-association')->assertOk()->assertExactJson(['applinks' => ['details' => []]]);

        // Something that is not a team id names no app either.
        config(['services.apns.team_id' => 'not a team id']);
        $this->get('/.well-known/apple-app-site-association')->assertOk()->assertExactJson(['applinks' => ['details' => []]]);
    }

    public function test_the_site_names_the_iphone_app_and_the_same_links_as_the_android_app(): void
    {
        config(['services.apns.team_id' => ' a1b2c3d4e5 ', 'services.apns.bundle_id' => 'studio.listenai.app']);

        $response = $this->get('/.well-known/apple-app-site-association')->assertOk();

        // Apple asks for JSON at an address without an ending, with no redirect on the way.
        $response->assertHeader('Content-Type', 'application/json');
        $details = $response->json('applinks.details');
        $this->assertCount(1, $details);
        $this->assertSame(['A1B2C3D4E5.studio.listenai.app'], $details[0]['appIDs']);
        $paths = array_column($details[0]['components'], '/');

        // The pages the Android app opens (its manifest), one for one.
        $manifest = (string) file_get_contents(base_path('mobile/android/app/src/main/AndroidManifest.xml'));
        preg_match_all('/<data android:(path|pathPrefix)="([^"]+)"/', $manifest, $android, PREG_SET_ORDER);
        $expected = array_map(function (array $entry): string {
            // "Starts with" on Android is a star on iPhone; a folder ("/email/verify/") keeps its slash.
            return $entry[1] === 'path' ? $entry[2] : $entry[2].'*';
        }, $android);
        sort($expected);
        $sorted = $paths;
        sort($sorted);
        $this->assertSame($expected, $sorted);

        // Nothing that sells, and never the browser leg of sign-in.
        foreach ($paths as $path) {
            $this->assertDoesNotMatchRegularExpression('#pricing|billing|signin|^/$|^/\*$#', $path);
        }
        // Apple's servers ask without cookies and keep the answer: no session is started for them.
        $this->assertSame([], $response->headers->getCookies());
        $this->assertStringContainsString('max-age=3600', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_voice_that_is_ready_reaches_the_iphone_through_apple(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $this->configureApple();
        $this->iphone($member, 'ar');
        Http::fake(['api.push.apple.com/*' => Http::response('', 200)]);

        app(AppPush::class)->voiceReady($this->longVoice($member));

        $sent = $this->sentToApple();
        $this->assertCount(1, $sent);
        $request = $sent[0];
        $this->assertSame('https://api.push.apple.com/3/device/'.self::IPHONE_TOKEN, $request->url());
        $this->assertSame('POST', $request->method());
        $this->assertSame(['studio.listenai.app'], $request->header('apns-topic'));
        $this->assertSame(['alert'], $request->header('apns-push-type'));
        $this->assertNotEmpty($request->header('apns-collapse-id')[0] ?? '');
        $this->assertGreaterThan(time() + 3600, (int) $request->header('apns-expiration')[0]);

        // What the phone shows, in the phone's language, and where a tap leads.
        $payload = $request->data();
        $this->assertSame(trans('app.push.voice_ready_title', [], 'ar'), $payload['aps']['alert']['title']);
        $this->assertSame(trans('app.push.voice_ready_body', [], 'ar'), $payload['aps']['alert']['body']);
        $this->assertSame('default', $payload['aps']['sound']);
        $this->assertStringContainsString('/ar/dashboard', $payload['url']);
        $this->assertSame('voice_ready', $payload['kind']);
        // Nothing went to Firebase for an iPhone.
        Http::assertNotSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'googleapis.com'));
    }

    public function test_the_message_is_signed_the_way_apple_checks_it(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $this->configureApple();
        $this->iphone($member);
        Http::fake(['api.push.apple.com/*' => Http::response('', 200)]);

        app(AppPush::class)->voiceReady($this->longVoice($member));

        $authorization = $this->sentToApple()[0]->header('Authorization')[0];
        $this->assertStringStartsWith('Bearer ', $authorization);
        [$header, $claims, $signature] = explode('.', substr($authorization, 7));
        $decode = fn (string $part): string => (string) base64_decode(strtr($part, '-_', '+/'));

        $this->assertSame(['alg' => 'ES256', 'kid' => 'KEY1234567'], json_decode($decode($header), true));
        $body = json_decode($decode($claims), true);
        $this->assertSame('A1B2C3D4E5', $body['iss']);
        $this->assertEqualsWithDelta(time(), $body['iat'], 5);

        // The signature is two 32-byte numbers side by side, and the key's public half accepts it.
        $raw = $decode($signature);
        $this->assertSame(64, strlen($raw));
        $number = function (string $bytes): string {
            $bytes = ltrim($bytes, "\x00");
            if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
                $bytes = "\x00".$bytes;
            }

            return "\x02".chr(strlen($bytes)).$bytes;
        };
        $sequence = $number(substr($raw, 0, 32)).$number(substr($raw, 32));
        $der = "\x30".chr(strlen($sequence)).$sequence;
        $this->assertSame(1, openssl_verify($header.'.'.$claims, $der, $this->publicKey, OPENSSL_ALGO_SHA256));

        // The same sign-in is used again for the next message (Apple asks not to make one each time).
        app(AppPush::class)->voiceReady($this->longVoice($member));
        $this->assertSame($authorization, $this->sentToApple()[1]->header('Authorization')[0]);
    }

    public function test_the_signature_is_laid_out_right_whatever_the_numbers_are(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $public = (string) openssl_pkey_get_details($key)['key'];

        // Many signatures: the numbers are sometimes 31, 32 or 33 bytes long in openssl's form.
        for ($i = 0; $i < 60; $i++) {
            $message = 'message '.$i;
            openssl_sign($message, $der, $key, OPENSSL_ALGO_SHA256);
            $raw = ApnsSender::rawSignature($der);
            $this->assertSame(64, strlen($raw), "signature $i");
        }
        $this->assertSame(1, openssl_verify($message, $der, $public, OPENSSL_ALGO_SHA256));
    }

    public function test_each_phone_gets_its_message_through_its_own_service(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $this->configureApple();
        $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($rsa, $pem);
        config(['services.fcm.credentials_json' => base64_encode((string) json_encode([
            'project_id' => 'listen-app-test', 'client_email' => 'push@listen-app-test.iam.gserviceaccount.com',
            'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token',
        ]))]);
        $this->iphone($member, 'en');
        AppDevice::create([
            'user_id' => $member->id, 'platform' => 'android', 'token_hash' => AppDevice::hashOf(self::ANDROID_TOKEN),
            'token' => self::ANDROID_TOKEN, 'locale' => 'ar', 'last_seen_at' => now(),
        ]);
        Http::fake([
            'api.push.apple.com/*' => Http::response('', 200),
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/listen-app-test/messages/1']),
        ]);

        app(AppPush::class)->voiceReady($this->longVoice($member));

        $this->assertCount(1, $this->sentToApple());
        $this->assertSame(trans('app.push.voice_ready_title', [], 'en'), $this->sentToApple()[0]->data()['aps']['alert']['title']);
        Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'fcm.googleapis.com')
            && $r->data()['message']['token'] === self::ANDROID_TOKEN);
        Http::assertSentCount(3);
    }

    public function test_an_iphone_that_removed_the_app_is_forgotten(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $this->configureApple();

        foreach ([[410, 'Unregistered'], [400, 'BadDeviceToken']] as [$status, $reason]) {
            $this->iphone($member);
            Http::fake(['api.push.apple.com/*' => Http::response(['reason' => $reason], $status)]);

            app(AppPush::class)->voiceReady($this->longVoice($member));

            $this->assertSame(0, AppDevice::query()->count(), $reason);
        }
    }

    public function test_a_message_apple_refuses_for_another_reason_keeps_the_phone_and_fails_nothing(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $this->configureApple();
        $this->iphone($member);

        // Apple is busy, or the network is down: the phone stays, the voice is not failed.
        Http::fake(['api.push.apple.com/*' => Http::response(['reason' => 'TooManyRequests'], 429)]);
        app(AppPush::class)->voiceReady($this->longVoice($member));
        $this->assertSame(1, AppDevice::query()->count());

        Http::fake(fn () => throw new ConnectionException('no route'));
        app(AppPush::class)->voiceReady($this->longVoice($member));
        $this->assertSame(1, AppDevice::query()->count());

        // A key Apple no longer accepts: the sign-in is made again next time.
        Cache::flush();
        Http::fake(['api.push.apple.com/*' => Http::response(['reason' => 'ExpiredProviderToken'], 403)]);
        $this->assertSame(FcmSender::FAILED, app(ApnsSender::class)->send(self::IPHONE_TOKEN, 't', 'b'));
        $this->assertSame(1, AppDevice::query()->count());
    }

    public function test_nothing_is_sent_to_iphones_until_apples_key_is_set(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $this->iphone($member);
        Http::fake();

        $this->assertFalse(app(ApnsSender::class)->configured());
        app(AppPush::class)->voiceReady($this->longVoice($member));

        Http::assertNothingSent();
        $this->assertSame(1, AppDevice::query()->count());
        $this->assertSame(FcmSender::NOT_CONFIGURED, app(ApnsSender::class)->send(self::IPHONE_TOKEN, 't', 'b'));
    }

    public function test_a_token_that_is_not_apples_is_never_sent(): void
    {
        $this->configureApple();
        Http::fake();

        $this->assertSame(FcmSender::GONE, app(ApnsSender::class)->send(self::ANDROID_TOKEN, 't', 'b'));
        $this->assertSame(FcmSender::GONE, app(ApnsSender::class)->send('../3/device/x', 't', 'b'));
        Http::assertNothingSent();
    }

    public function test_the_key_can_be_a_file_on_the_server_instead(): void
    {
        $this->configureApple();
        $pem = (string) base64_decode((string) config('services.apns.private_key'));
        $path = tempnam(sys_get_temp_dir(), 'apns');
        file_put_contents($path, $pem);
        config(['services.apns.private_key' => '', 'services.apns.private_key_path' => $path]);

        try {
            $this->assertTrue(app(ApnsSender::class)->configured());
            Http::fake(['api.push.apple.com/*' => Http::response('', 200)]);
            $this->assertSame(FcmSender::SENT, app(ApnsSender::class)->send(self::IPHONE_TOKEN, 't', 'b'));
        } finally {
            @unlink($path);
        }

        // Something that is not a key is not one.
        config(['services.apns.private_key' => 'not-a-key', 'services.apns.private_key_path' => '']);
        $this->assertFalse(app(ApnsSender::class)->configured());
    }
}
