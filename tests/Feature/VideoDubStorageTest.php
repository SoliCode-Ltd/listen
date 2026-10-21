<?php

namespace Tests\Feature;

use App\Filament\Pages\VideoDubbingSettings;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VideoDub;
use App\Services\Dubbing\DriveArchive;
use App\Services\Dubbing\VideoDubRunner;
use App\Support\VideoDubbing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Where dubbed videos are kept and for how long: a free one is watched on
 * the member's dashboard for a day (never on the demo), a paid one as long
 * as the plan keeps voices; the site's Google Drive keeps them all after
 * that (Listen Studio / Paid or Free / the member), with Google faked here;
 * switched off, they stay on the server.
 */
class VideoDubStorageTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> Folders made in the fake Drive. */
    private array $made = [];

    private string $uploaded = '';

    private int $pieces = 0;

    /** @var array<string, mixed> */
    private array $uploadMeta = [];

    protected function tearDown(): void
    {
        DriveArchive::$chunkBytes = 8 * 1024 * 1024;
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ]);
        SiteSetting::set('video_dubbing_audience', VideoDubbing::AUDIENCE_EVERYONE, 'video_dubbing');
        config(['services.google.client_id' => 'client-1', 'services.google.client_secret' => 'secret-1']);
        $this->fakeGoogle();
    }

    public function test_the_admin_connects_google_drive_once(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.drive.connect'))->assertForbidden();

        $admin = User::factory()->create(['is_admin' => true]);
        $go = $this->actingAs($admin)->get(route('admin.drive.connect'))->assertRedirect();
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $go->headers->get('Location'));
        parse_str((string) parse_url($go->headers->get('Location'), PHP_URL_QUERY), $asked);
        $this->assertSame('openid email '.DriveArchive::SCOPE, $asked['scope'], 'only the files the site makes, never the rest of the Drive');
        $this->assertSame('offline', $asked['access_type']);
        $this->assertSame(route('admin.drive.callback'), $asked['redirect_uri']);

        // Back from Google with someone else's answer: nothing is connected.
        $this->get(route('admin.drive.callback', ['state' => 'not-the-one', 'code' => 'code-1']))->assertRedirect(VideoDubbingSettings::getUrl());
        $this->assertFalse(DriveArchive::connected());

        $this->get(route('admin.drive.connect'));
        $this->get(route('admin.drive.callback', ['state' => session('video_dubbing_drive_state'), 'code' => 'code-1']))->assertRedirect(VideoDubbingSettings::getUrl());
        $this->assertTrue(DriveArchive::connected());
        $this->assertSame('owner@example.com', DriveArchive::account());
        $this->assertSame(['name' => 'Listen Studio', 'parents' => ['root']], array_intersect_key($this->made[0], array_flip(['name', 'parents'])));
        $this->assertNotSame('refresh-1', SiteSetting::get('video_dubbing_drive_token'), 'kept encrypted');

        $this->get(VideoDubbingSettings::getUrl())->assertOk()->assertSee('owner@example.com')->assertSee('Open the Listen Studio folder');
    }

    public function test_a_paid_video_goes_to_its_members_paid_folder_and_plays_from_there(): void
    {
        $this->connect();
        $member = $this->member(dubbing: true, name: 'Sara Ahmed');
        $dub = $this->dub($member, paid: true);

        $this->artisan('dubs:prune')->assertSuccessful();

        $dub->refresh();
        $this->assertSame('drive-file-1', $dub->drive_file_id);
        $this->assertNotNull($dub->archived_at);
        $this->assertFalse(Storage::disk('local')->exists($dub->folder), 'off the server');
        $this->assertSame(['Paid', 'Sara Ahmed · #'.$member->id], array_column(array_slice($this->made, 1), 'name'));
        $this->assertSame([DriveArchive::folderId()], $this->made[1]['parents']);
        $this->assertSame(['folder-2'], $this->made[2]['parents'], 'the member\'s folder is in Paid');
        $this->assertSame('CLEAN-VIDEO', $this->uploaded);
        $this->assertSame(['folder-3'], $this->uploadMeta['parents']);
        $this->assertStringContainsString('holiday clip', $this->uploadMeta['name']);

        // The member watches it from the site, which reads it from Drive (with the player's range).
        $watch = $this->actingAs($member)->get(route('dub.watch', ['code' => $dub->code]), ['Range' => 'bytes=0-'])->assertStatus(206);
        $this->assertSame('VIDEO-BYTES', $watch->streamedContent());
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/files/drive-file-1') && $r->hasHeader('Range', 'bytes=0-'));
        $this->actingAs($member)->get(route('dub.download', ['code' => $dub->code]))->assertSuccessful()
            ->assertHeader('Content-Disposition', 'attachment; filename="listen-dub-'.$dub->id.'.mp4"');
        $this->actingAs($member)->get(route('dashboard'))->assertOk()->assertSee(route('dub.watch', ['code' => $dub->code]));

        // Past the plan's days the member no longer sees it; the admin still does.
        $this->travel(8)->days();
        $this->actingAs($member)->get(route('dub.watch', ['code' => $dub->code]))->assertNotFound();
        $this->actingAs($member)->get(route('dub.download', ['code' => $dub->code]))->assertNotFound();
        $this->assertSame([], VideoDubbing::recent($member));
        $admin = User::factory()->create(['is_admin' => true]);
        $this->assertSame('VIDEO-BYTES', $this->actingAs($admin)->get(route('admin.video-dubs.file', ['dub' => $dub->id, 'kind' => 'clean']))->assertStatus(206)->streamedContent());
        $this->actingAs($admin)->get(\App\Filament\Resources\VideoDubResource::getUrl('view', ['record' => $dub]))->assertOk()
            ->assertSee('In Google Drive (Listen Studio)')->assertSee(DriveArchive::fileUrl('drive-file-1'));

        // Removed by the admin: gone from Drive too.
        app(VideoDubRunner::class)->remove((int) $dub->id);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/files/drive-file-1'));
        $this->assertNull($dub->fresh()->drive_file_id);
    }

    public function test_a_paid_dub_goes_to_drive_as_soon_as_it_is_ready_in_pieces(): void
    {
        $ffmpeg = \App\Support\AudioFormats::ffmpegPath();
        if (! $ffmpeg) {
            $this->markTestSkipped('ffmpeg is not installed here.');
        }
        $this->connect();
        DriveArchive::$chunkBytes = 32 * 1024;
        $this->fakeEngine($ffmpeg);
        $member = $this->member(dubbing: true, name: 'Nour');
        $dub = $this->dub($member, paid: true);
        Storage::disk('local')->delete($dub->path('dub.mp4'));
        (new Process([$ffmpeg, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=320x240:rate=15', '-f', 'lavfi', '-i', 'sine=frequency=300:duration=6',
            '-t', '6', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', Storage::disk('local')->path($dub->path('source.mp4'))]))->mustRun();
        $dub->update(['status' => VideoDub::QUEUED, 'stage' => 'queued', 'progress' => 0, 'has_clean' => false]);

        app(VideoDubRunner::class)->run((int) $dub->id);

        $dub->refresh();
        $this->assertSame(VideoDub::READY, $dub->status, (string) $dub->error_message);
        $this->assertSame('drive-file-1', $dub->drive_file_id, 'sent as soon as it was ready');
        $this->assertSame('ftyp', substr($this->uploaded, 4, 4), 'the dubbed MP4, whole');
        $this->assertGreaterThan(1, $this->pieces);
        $this->assertSame((int) ceil(strlen($this->uploaded) / (32 * 1024)), $this->pieces, 'each piece once, in order');
        $this->assertSame([], Storage::disk('local')->allFiles($dub->folder), 'nothing left on the server');
    }

    public function test_a_free_video_is_on_the_dashboard_for_a_day_then_kept_in_the_free_folder(): void
    {
        $this->connect();
        $member = $this->member(dubbing: false, name: 'Omar');
        $dub = $this->dub($member, paid: false);

        // While the member's day lasts it stays on the server, to watch with the mark.
        $this->artisan('dubs:prune')->assertSuccessful();
        $this->assertNull($dub->fresh()->drive_file_id);
        $this->actingAs($member)->get(route('dub.watch', ['code' => $dub->code]))->assertOk();
        $watch = route('dub.watch', ['code' => $dub->code]);
        $this->actingAs($member)->get(route('dashboard'))->assertOk()->assertSee($watch)->assertSee(__('ui.dub.kept_free', ['hours' => 24]));
        // Never on the demo: there the studio says it is ready on the dashboard.
        $demo = $this->actingAs($member)->get(route('demo.page'))->assertOk()->assertSee('data-dub-studio', false)->getContent();
        $this->assertStringNotContainsString($watch, $demo);
        $this->assertStringNotContainsString('data-dub-history', $demo);
        $this->assertStringContainsString('"hasVideos":true', $demo);
        $this->assertStringContainsString('#dubVideoSection', $demo);

        $this->travel(25)->hours();
        $this->artisan('dubs:prune')->assertSuccessful();
        $dub->refresh();
        $this->assertSame('drive-file-1', $dub->drive_file_id);
        $this->assertSame(['Free', 'Omar · #'.$member->id], array_column(array_slice($this->made, 1), 'name'));
        $this->assertSame('CLEAN-VIDEO', $this->uploaded, 'the copy without the mark is kept');
        $this->assertFalse(Storage::disk('local')->exists($dub->folder));

        $this->actingAs($member)->get(route('dub.watch', ['code' => $dub->code]))->assertNotFound();
        $this->actingAs($member)->get(route('dashboard'))->assertOk()->assertDontSee($watch);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.video-dubs.file', ['dub' => $dub->id, 'kind' => 'clean']))->assertStatus(206);
    }

    public function test_with_drive_off_videos_stay_on_the_server_for_the_admins_days(): void
    {
        $this->connect();
        SiteSetting::set('video_dubbing_drive_enabled', '0', 'video_dubbing');
        $member = $this->member(dubbing: true, name: 'Lina');
        $dub = $this->dub($member, paid: true);

        $this->artisan('dubs:prune')->assertSuccessful();
        $this->assertNull($dub->fresh()->drive_file_id, 'not sent to Drive');
        $this->assertSame(1, count($this->made), 'only the folder made when connecting');
        $this->actingAs($member)->get(route('dub.watch', ['code' => $dub->code]))->assertOk();

        // The plan's 7 days are over: the member no longer sees it, the server keeps it for 30.
        $this->travel(10)->days();
        $this->artisan('dubs:prune')->assertSuccessful();
        $this->assertTrue(Storage::disk('local')->exists($dub->path('dub.mp4')));
        $this->actingAs($member)->get(route('dub.watch', ['code' => $dub->code]))->assertNotFound();

        $this->travel(21)->days();
        $this->artisan('dubs:prune')->assertSuccessful();
        $this->assertNotNull($dub->fresh()->files_deleted_at);
        $this->assertFalse(Storage::disk('local')->exists($dub->folder));

        // A plan that keeps voices without a limit keeps its videos to watch, and on the server.
        $always = $this->member(dubbing: true, name: 'Hadi');
        Plan::query()->whereKey(Subscription::query()->where('user_id', $always->id)->value('plan_id'))->update(['recent_voices_days' => 0]);
        $kept = $this->dub($always, paid: true);
        $this->travel(90)->days();
        $this->artisan('dubs:prune')->assertSuccessful();
        $this->assertNull($kept->fresh()->files_deleted_at);
        $this->actingAs($always)->get(route('dub.watch', ['code' => $kept->code]))->assertOk();
    }

    public function test_without_drive_connected_nothing_is_sent_and_nothing_breaks(): void
    {
        $member = $this->member(dubbing: true, name: 'Rami');
        $dub = $this->dub($member, paid: true);

        $this->assertFalse(app(VideoDubRunner::class)->archive($dub));
        $this->artisan('dubs:prune')->assertSuccessful();
        $this->assertNull($dub->fresh()->drive_file_id);
        $this->assertSame([], $this->made);
        $this->actingAs($member)->get(route('dub.watch', ['code' => $dub->code]))->assertOk();

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(VideoDubbingSettings::getUrl())->assertOk()
            ->assertSee('Not connected')->assertSee(route('admin.drive.callback'))->assertSee('Google Drive API');
    }

    public function test_the_admin_sets_the_free_video_limits_and_the_upload_box_shows_them(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        Livewire::test(VideoDubbingSettings::class)
            ->fillForm([
                'video_dubbing_free_max_seconds' => 45,
                'video_dubbing_free_keep_hours' => 12,
                'video_dubbing_max_upload_mb' => 120,
                'video_dubbing_free_max_upload_mb' => 40,
                'video_dubbing_drive_enabled' => false,
                'video_dubbing_server_keep_days' => 14,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(45, VideoDubbing::freeMaxSeconds());
        $this->assertSame(12, VideoDubbing::freeKeepHours());
        $this->assertSame(14, VideoDubbing::serverKeepDays());
        $this->assertFalse(DriveArchive::enabled());
        $this->assertSame(120, VideoDubbing::uploadSettingMb());
        $this->assertSame(40, VideoDubbing::uploadSettingMb(true), 'the demo\'s own size');

        $member = $this->member(dubbing: false, name: 'Mona');
        $studio = VideoDubbing::studio($member, request());
        $this->assertSame(45, $studio['max_seconds']);
        $this->assertSame(12, $studio['keep_hours']);
        $this->assertSame(VideoDubbing::maxUploadMb(true), $studio['max_mb'], 'a free member sees the free size');
        $this->assertSame(VideoDubbing::maxUploadMb(), VideoDubbing::studio($this->member(dubbing: true, name: 'Yousef'), request())['max_mb']);
        $this->actingAs($member)->get(route('dub.page'))->assertOk()->assertSee(__('ui.dub.drop_seconds', ['seconds' => 45]));
    }

    public function test_the_admin_sees_and_installs_the_voice_separation(): void
    {
        config(['listen.dubbing.separator.dir' => sys_get_temp_dir().'/separator-'.bin2hex(random_bytes(4))]);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(VideoDubbingSettings::getUrl())->assertOk()
            ->assertSee('Taking the old voices out')->assertSee('Not installed')->assertSee('Install on this server');
        $this->assertFalse(\App\Services\Dubbing\VoiceSeparator::active());

        // The Install button runs it after the reply and keeps how it went for the page.
        $installer = \Mockery::mock(\App\Services\Dubbing\VoiceSeparator::class)->makePartial();
        $installer->shouldReceive('install')->atLeast()->once()->andReturnUsing(function (callable $say): void {
            $say('Testing it…');
        });
        $this->app->instance(\App\Services\Dubbing\VoiceSeparator::class, $installer);
        \App\Services\Dubbing\VoiceSeparator::installAfterResponse();
        $this->assertSame('running', \App\Services\Dubbing\VoiceSeparator::installStatus()['state']);
        $this->app->terminate();
        $this->assertSame('done', \App\Services\Dubbing\VoiceSeparator::installStatus()['state']);

        // Switched off in the settings.
        Livewire::test(VideoDubbingSettings::class)->fillForm(['video_dubbing_separation' => false])->call('save')->assertHasNoFormErrors();
        $this->assertFalse(\App\Services\Dubbing\VoiceSeparator::enabled());
    }

    private function connect(): void
    {
        $this->made = [];
        app(DriveArchive::class)->connect('code-1');
        $this->assertTrue(DriveArchive::connected());
    }

    /** A member on a plan with dubbing, or without a plan (as the demo's visitors are). */
    private function member(bool $dubbing, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'email_verified_at' => now(), 'subscription_status_checked_at' => now()]);
        if (! $dubbing) {
            return $user;
        }
        $plan = Plan::factory()->create(['monthly_chars' => 100000, 'monthly_requests' => 100, 'price_cents' => 2200,
            'video_dubbing_enabled' => true, 'recent_voices_days' => 7]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active',
            'current_period_start' => now()->startOfMonth(), 'current_period_end' => now()->addYear()]);

        return $user;
    }

    /** A finished dub with its videos on the server. */
    private function dub(User $user, bool $paid): VideoDub
    {
        $folder = 'dubs/'.now()->format('Ymd').'/'.bin2hex(random_bytes(6));
        Storage::disk('local')->put($folder.'/dub.mp4', 'CLEAN-VIDEO');
        if (! $paid) {
            Storage::disk('local')->put($folder.'/dub-preview.mp4', 'MARKED-VIDEO');
        }

        return VideoDub::query()->create([
            'user_id' => $user->id, 'status' => VideoDub::READY, 'stage' => 'ready', 'progress' => 100, 'is_free' => ! $paid,
            'language' => 'ar', 'dialect' => 'ar-SA', 'voice' => 'Puck', 'original_name' => 'holiday clip.mp4', 'video_seconds' => 6,
            'folder' => $folder, 'has_clean' => true, 'has_preview' => ! $paid,
            'reservation' => $paid ? ['period' => now()->format('Y-m'), 'billed_to_pack' => false, 'credits' => 90, 'seconds' => 6] : null,
        ]);
    }

    /** The speech engine (its lines) and the key the text model is asked with. */
    private function fakeEngine(string $ffmpeg): void
    {
        Storage::fake('public');
        $keys = \Mockery::mock(\App\Services\Tts\GeminiKeyManager::class);
        $keys->shouldReceive('keysInOrder')->andReturn([['key' => 'k1', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1]]);
        $keys->shouldReceive('recordUsage');
        $tts = \Mockery::mock(\App\Services\Tts\GeminiTtsProvider::class);
        $tts->shouldReceive('synthesize')->andReturnUsing(function () use ($ffmpeg) {
            $path = 'audio/line-'.bin2hex(random_bytes(3)).'.mp3';
            $file = Storage::disk('public')->path($path);
            @mkdir(dirname($file), 0755, true);
            (new Process([$ffmpeg, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=500:duration=2', '-c:a', 'libmp3lame', $file]))->mustRun();

            return ['audio_path' => $path, 'duration_seconds' => 2];
        });
        $this->app->instance(\App\Services\Dubbing\VideoDubber::class, new \App\Services\Dubbing\VideoDubber($keys, $tts));
    }

    /** Google's sign-in and Drive, as far as the site uses them. */
    private function fakeGoogle(): void
    {
        $claims = rtrim(strtr(base64_encode(json_encode(['email' => 'owner@example.com'])), '+/', '-_'), '=');
        Http::fake(function (Request $request) use ($claims) {
            $url = $request->url();
            if (str_starts_with($url, 'https://oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'access-1', 'expires_in' => 3600, 'refresh_token' => 'refresh-1',
                    'scope' => 'openid email '.DriveArchive::SCOPE, 'id_token' => 'head.'.$claims.'.sig']);
            }
            if (str_starts_with($url, 'https://www.googleapis.com/drive/v3/files?') && $request->method() === 'GET') {
                return Http::response(['files' => []]);
            }
            if (str_starts_with($url, 'https://www.googleapis.com/drive/v3/files?') && $request->method() === 'POST') {
                $this->made[] = $request->data();

                return Http::response(['id' => 'folder-'.count($this->made)]);
            }
            if (str_starts_with($url, 'https://www.googleapis.com/upload/drive/v3/files')) {
                $this->uploadMeta = $request->data();

                return Http::response([], 200, ['Location' => 'https://www.googleapis.com/upload/session-1']);
            }
            if ($url === 'https://www.googleapis.com/upload/session-1') {
                // A piece at a time, as Drive takes a resumable upload: 308 and how far it has got, until the last.
                $this->uploaded .= $request->body();
                $this->pieces++;
                preg_match('/bytes (\d+)-(\d+)\/(\d+)/', (string) $request->header('Content-Range')[0], $m);

                return (int) $m[2] + 1 < (int) $m[3]
                    ? Http::response('', 308, ['Range' => 'bytes=0-'.$m[2]])
                    : Http::response(['id' => 'drive-file-1']);
            }
            if (str_contains($url, '/drive/v3/files/drive-file-1') && $request->method() === 'GET') {
                return Http::response('VIDEO-BYTES', 206, ['Content-Range' => 'bytes 0-10/11', 'Content-Length' => '11']);
            }
            if (str_contains($url, 'generativelanguage.googleapis.com')) {
                // The text model, hearing the video.
                return Http::response([
                    'candidates' => [['content' => ['parts' => [['text' => json_encode(['segments' => [['start' => 0.5, 'end' => 3.0, 'original' => 'Hello there.', 'dubbed' => 'هلا والله.']]])]]]]],
                    'usageMetadata' => ['promptTokenCount' => 200, 'candidatesTokenCount' => 50],
                ]);
            }
            if ($request->method() === 'DELETE') {
                return Http::response('', 204);
            }

            return Http::response(['error' => 'not faked: '.$url], 500);
        });
    }
}
