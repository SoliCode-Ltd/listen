<?php

namespace Tests\Feature\InApp;

use App\Models\AppDevice;
use App\Models\AudioGeneration;
use App\Models\ClonedVoice;
use App\Models\TextItem;
use App\Models\User;
use App\Models\VideoDub;
use App\Services\Push\AppPush;
use App\Services\Push\FcmSender;
use App\Support\GenerationSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * "Your voice is ready" on the member's phone: the app gives the site the
 * phone's messaging token, and work that finishes after the page's reply (a
 * long text, a dubbed video, a cloned voice) is announced on every phone the
 * member is signed in on, in that phone's language. Nothing is sent when
 * messaging is not set up, and a notification that cannot be sent never
 * fails the work it announces.
 */
class AppDevicesAndPushTest extends InAppTestCase
{
    private const TOKEN = 'fcm-token_AAAA:APA91bHPRgkF3JUikC4ENAHEeMrd41Zxv3hVZjC9KtT8OvPVGJ-hQMRKRrZuJAEcl7B338qju59zJMjw2DELjzEvxwYv7hH5Ynpc1ODQ0aT4U4OFEeco8ohsN5PjL1iC2dNtk2BAokeMCg2ZXKqpc8FXKmhX94kIxQ';

    private function configureMessaging(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        config(['services.fcm.credentials_json' => base64_encode((string) json_encode([
            'project_id' => 'listen-app-test',
            'client_email' => 'push@listen-app-test.iam.gserviceaccount.com',
            'private_key' => $pem,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]))]);
    }

    private function fakeMessaging(array $sendReply = ['name' => 'projects/listen-app-test/messages/1'], int $status = 200): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response($sendReply, $status),
        ]);
    }

    private function device(User $member, string $locale = 'ar', string $token = self::TOKEN): AppDevice
    {
        return AppDevice::create([
            'user_id' => $member->id, 'platform' => 'android', 'token_hash' => AppDevice::hashOf($token),
            'token' => $token, 'locale' => $locale, 'last_seen_at' => now(),
        ]);
    }

    private function longVoice(User $member, ?string $source = null, int $secondsAgo = 90): AudioGeneration
    {
        $item = $source === null ? null : TextItem::create([
            'user_id' => $member->id, 'source_type' => $source, 'title' => 't', 'content' => 'مرحبا', 'status' => 'done',
        ]);
        $generation = AudioGeneration::create([
            'user_id' => $member->id, 'text_item_id' => $item?->id, 'input_text' => 'مرحبا', 'source_language' => 'ar',
            'target_language' => 'ar', 'voice_tier' => 'hd', 'voice_name' => 'Kore', 'status' => 'done',
        ]);
        $generation->forceFill(['created_at' => now()->subSeconds($secondsAgo)])->save();

        return $generation;
    }

    /** @return list<array<string, mixed>> the messages sent to the messaging service */
    private function sent(): array
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'fcm.googleapis.com'))
            ->map(fn (array $pair): array => $pair[0]->data()['message'])
            ->values()->all();
    }

    public function test_the_app_registers_the_phone_for_the_member_who_is_signed_in(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');

        $this->inApp($member)->postJson('/app/devices', ['token' => self::TOKEN, 'locale' => 'ar'])->assertOk()->assertJson(['ok' => true]);

        $device = AppDevice::query()->sole();
        $this->assertSame($member->id, $device->user_id);
        $this->assertSame('ar', $device->locale);
        $this->assertSame('1.0.0', $device->app_version);
        $this->assertSame(self::TOKEN, $device->token);
        // The token is kept encrypted; its hash is what is looked up.
        $this->assertStringNotContainsString(self::TOKEN, (string) \DB::table('app_devices')->value('token'));
        $this->assertSame(hash('sha256', self::TOKEN), $device->token_hash);
    }

    public function test_a_phone_belongs_to_whoever_signed_in_on_it_last(): void
    {
        $this->seedPlansWithEverythingOpen();
        $first = $this->memberOn('starter');
        $second = $this->memberOn('free');

        $this->inApp($first)->postJson('/app/devices', ['token' => self::TOKEN])->assertOk();
        $this->inApp($second)->postJson('/app/devices', ['token' => self::TOKEN, 'locale' => 'en'])->assertOk();

        $this->assertSame(1, AppDevice::query()->count());
        $this->assertSame($second->id, AppDevice::query()->sole()->user_id);
        $this->assertSame('en', AppDevice::query()->sole()->locale);
    }

    public function test_only_a_signed_in_member_in_the_app_can_register_a_phone(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');

        $this->inApp(null)->postJson('/app/devices', ['token' => self::TOKEN])->assertStatus(401);
        $this->inBrowser($member)->postJson('/app/devices', ['token' => self::TOKEN])->assertStatus(403);
        $this->inApp($member)->postJson('/app/devices', ['token' => 'short'])->assertStatus(422);
        $this->inApp($member)->postJson('/app/devices', ['token' => str_repeat('a b', 20)])->assertStatus(422);

        $this->assertSame(0, AppDevice::query()->count());
    }

    public function test_a_member_keeps_their_ten_most_recent_phones(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        foreach (range(1, 12) as $n) {
            $this->travel(1)->minutes();
            $this->inApp($member)->postJson('/app/devices', ['token' => self::TOKEN.'-phone-'.$n])->assertOk();
        }

        $this->assertSame(AppDevice::PER_MEMBER, AppDevice::query()->where('user_id', $member->id)->count());
        $this->assertFalse(AppDevice::query()->where('token_hash', AppDevice::hashOf(self::TOKEN.'-phone-1'))->exists());
        $this->assertTrue(AppDevice::query()->where('token_hash', AppDevice::hashOf(self::TOKEN.'-phone-12'))->exists());
    }

    public function test_signing_out_on_a_phone_stops_its_notifications(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $other = $this->device($member, 'en', self::TOKEN.'-tablet');

        $this->inApp($member)->postJson('/app/devices', ['token' => self::TOKEN])->assertOk();
        $this->assertSame(2, AppDevice::query()->count());

        $this->post($this->localizedRoute('logout'))->assertRedirect();

        // This phone is forgotten; the member's other phone is not.
        $this->assertSame([$other->id], AppDevice::query()->pluck('id')->all());
    }

    public function test_the_app_can_forget_a_phone(): void
    {
        $this->seedPlansWithEverythingOpen();
        $member = $this->memberOn('starter');
        $this->device($member);

        $this->inApp($member)->deleteJson('/app/devices', ['token' => self::TOKEN])->assertOk();

        $this->assertSame(0, AppDevice::query()->count());
    }

    public function test_a_long_studio_voice_is_announced_on_each_phone_in_its_language(): void
    {
        $this->seedPlansWithEverythingOpen();
        $this->configureMessaging();
        $this->fakeMessaging();
        $member = $this->memberOn('starter');
        $this->device($member, 'ar');
        $this->device($member, 'en', self::TOKEN.'-second');
        $this->device($this->memberOn('free'), 'en', self::TOKEN.'-someone-else');

        app(AppPush::class)->voiceReady($this->longVoice($member, GenerationSource::STUDIO));

        $messages = $this->sent();
        $this->assertCount(2, $messages);
        $byToken = collect($messages)->keyBy('token');
        $arabic = $byToken[self::TOKEN];
        $english = $byToken[self::TOKEN.'-second'];
        $this->assertSame('صوتك جاهز', $arabic['notification']['title']);
        $this->assertSame('Your voice is ready', $english['notification']['title']);
        $this->assertStringEndsWith('/ar/dashboard', $arabic['data']['url']);
        $this->assertStringEndsWith('/en/dashboard', $english['data']['url']);
        $this->assertSame('voice_ready', $arabic['android']['notification']['channel_id']);
        // Signed in to the messaging service as the project's service account.
        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'fcm.googleapis.com/v1/projects/listen-app-test/messages:send')
            && $request->hasHeader('Authorization', 'Bearer ya29.test'));
    }

    public function test_a_long_voice_made_in_the_app_is_announced_too(): void
    {
        $this->seedPlansWithEverythingOpen();
        $this->configureMessaging();
        $this->fakeMessaging();
        $member = $this->memberOn('starter');
        $this->device($member, 'en');

        app(AppPush::class)->voiceReady($this->longVoice($member, GenerationSource::APP));

        $this->assertCount(1, $this->sent());
    }

    public function test_a_voice_that_was_ready_at_once_is_not_announced(): void
    {
        $this->seedPlansWithEverythingOpen();
        $this->configureMessaging();
        $this->fakeMessaging();
        $member = $this->memberOn('starter');
        $this->device($member);

        app(AppPush::class)->voiceReady($this->longVoice($member, GenerationSource::STUDIO, secondsAgo: 5));

        $this->assertSame([], $this->sent());
    }

    public function test_voices_made_outside_the_studio_are_not_announced(): void
    {
        $this->seedPlansWithEverythingOpen();
        $this->configureMessaging();
        $this->fakeMessaging();
        $member = $this->memberOn('starter');
        $this->device($member);

        foreach ([GenerationSource::EXTENSION, GenerationSource::API, GenerationSource::MCP] as $source) {
            app(AppPush::class)->voiceReady($this->longVoice($member, $source));
        }

        $this->assertSame([], $this->sent());
    }

    public function test_a_dub_and_a_cloned_voice_are_announced(): void
    {
        $this->seedPlansWithEverythingOpen();
        $this->configureMessaging();
        $this->fakeMessaging();
        $member = $this->memberOn('starter');
        $this->device($member, 'en');

        $dub = new VideoDub(['user_id' => $member->id]);
        $dub->id = 7;
        $voice = new ClonedVoice(['user_id' => $member->id, 'name' => 'Mine']);
        $voice->id = 9;
        app(AppPush::class)->dubReady($dub);
        app(AppPush::class)->dubFailed($dub);
        app(AppPush::class)->clonedVoiceReady($voice);

        $messages = $this->sent();
        $this->assertSame(['Your dubbed video is ready', 'Your video could not be dubbed', 'Your cloned voice is ready'], array_column(array_column($messages, 'notification'), 'title'));
        $this->assertStringEndsWith('/en/dub-my-video', $messages[0]['data']['url']);
        $this->assertStringEndsWith('/en/dashboard#voiceCloningSection', $messages[2]['data']['url']);
    }

    public function test_a_phone_that_no_longer_has_the_app_is_forgotten(): void
    {
        $this->seedPlansWithEverythingOpen();
        $this->configureMessaging();
        $this->fakeMessaging(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404);
        $member = $this->memberOn('starter');
        $this->device($member);

        app(AppPush::class)->voiceReady($this->longVoice($member));

        $this->assertSame(0, AppDevice::query()->count());
    }

    public function test_a_failure_of_the_messaging_service_changes_nothing(): void
    {
        $this->seedPlansWithEverythingOpen();
        $this->configureMessaging();
        $member = $this->memberOn('starter');
        $this->device($member);

        $this->fakeMessaging(['error' => ['code' => 503, 'status' => 'UNAVAILABLE']], 503);
        app(AppPush::class)->voiceReady($this->longVoice($member));
        $this->assertSame(1, AppDevice::query()->count());

        Http::fake(fn () => throw new ConnectionException('timed out'));
        app(AppPush::class)->voiceReady($this->longVoice($member));
        $this->assertSame(1, AppDevice::query()->count());
    }

    public function test_nothing_is_sent_when_messaging_is_not_set_up(): void
    {
        $this->seedPlansWithEverythingOpen();
        Http::fake();
        $member = $this->memberOn('starter');
        $this->device($member);

        $this->assertFalse(app(FcmSender::class)->configured());
        app(AppPush::class)->voiceReady($this->longVoice($member));

        Http::assertNothingSent();
        $this->assertSame(FcmSender::NOT_CONFIGURED, app(FcmSender::class)->send(self::TOKEN, 't', 'b'));
    }

    public function test_the_work_that_finishes_later_announces_itself(): void
    {
        // The three places where work ends after the reply each call the announcer.
        $this->assertStringContainsString('AppPush::class)->voiceReady($generation)', (string) file_get_contents(app_path('Jobs/GenerateAudioJob.php')));
        $this->assertStringContainsString('AppPush::class)->clonedVoiceReady($clonedVoice)', (string) file_get_contents(app_path('Jobs/ProcessVoiceCloningJob.php')));
        $runner = (string) file_get_contents(app_path('Services/Dubbing/VideoDubRunner.php'));
        $this->assertStringContainsString('AppPush::class)->dubReady($dub)', $runner);
        $this->assertStringContainsString('AppPush::class)->dubFailed($dub)', $runner);
    }
}
