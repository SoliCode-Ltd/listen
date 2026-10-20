<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Models\VideoDub;
use App\Services\DemoLinkedAccounts;
use App\Services\Dubbing\VideoDubber;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Support\AudioFormats;
use App\Support\VideoDubbing;
use App\Support\VoiceCloningAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * "Dub my video" from the page, on a real video with the text model and the
 * speech engine faked: hidden until an admin opens it, one free marked dub
 * per person (a new account in the same browser gets none), a plan with
 * dubbing pays a second of voice per second of video and downloads the clean
 * copy, and a dub that fails gives its credits back.
 */
class VideoDubPageTest extends TestCase
{
    use RefreshDatabase;

    private string $video;

    protected function setUp(): void
    {
        parent::setUp();
        if (! AudioFormats::ffmpegPath()) {
            $this->markTestSkipped('ffmpeg is not installed here.');
        }
        Storage::fake('public');
        Storage::fake('local');
        // Routes have no locale prefix in tests (TestCase::localizedRoute).
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ]);
        $this->video = sys_get_temp_dir().'/dub-page-'.bin2hex(random_bytes(4)).'.mp4';
        (new Process([AudioFormats::ffmpegPath(), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=320x240:rate=15', '-f', 'lavfi', '-i', 'sine=frequency=300:duration=6',
            '-t', '6', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $this->video]))->mustRun();
    }

    protected function tearDown(): void
    {
        @unlink($this->video);
        parent::tearDown();
    }

    public function test_the_page_is_hidden_until_an_admin_opens_it(): void
    {
        $this->get(route('dub.page'))->assertNotFound();
        $this->actingAs(User::factory()->create())->get(route('dub.page'))->assertNotFound();
        $this->actingAs(User::factory()->create(['email' => VoiceCloningAccess::PREVIEW_EMAIL]))->get(route('dub.page'))->assertOk();

        $this->openToEveryone();
        auth()->logout();
        $this->get(route('dub.page'))->assertOk()->assertSee(__('ui.dub.guest_title'));
    }

    public function test_one_free_marked_dub_per_person(): void
    {
        $this->openToEveryone();
        $this->fakeEngine();
        $user = $this->member(dubbing: false);

        $sent = $this->actingAs($user)->withCookie(DemoLinkedAccounts::BROWSER_COOKIE, 'browser-one-aaaaaaaaaaaaaaaaaaaa')
            ->post(route('dub.store'), $this->form(), ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('free', true);

        $dub = VideoDub::query()->findOrFail($sent->json('id'));
        $this->assertSame(VideoDub::READY, $dub->status, $dub->error_message ?? '');
        $this->assertTrue($dub->has_preview);
        $this->assertNull($dub->reservation, 'a free dub costs the member nothing');
        $this->assertFalse(Storage::disk('local')->exists($dub->path('source.mp4')), 'the upload is deleted once dubbed');
        $this->assertSame(0, (int) UsageCounter::query()->where('user_id', $user->id)->value('total_chars'));

        $this->get(route('dub.watch', ['id' => $dub->id]))->assertOk();
        // The clean copy needs a plan with dubbing.
        $this->get(route('dub.download', ['id' => $dub->id]))->assertRedirect();

        $this->post(route('dub.store'), $this->form(), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.video.0', __('ui.dub.free_used'));

        // A new account in the same browser shares the free dub.
        $other = $this->member(dubbing: false);
        $this->actingAs($other)->withCookie(DemoLinkedAccounts::BROWSER_COOKIE, 'browser-one-aaaaaaaaaaaaaaaaaaaa')
            ->post(route('dub.store'), $this->form(), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.video.0', __('ui.dub.free_used'));

        // Nobody else can watch it.
        $this->get(route('dub.watch', ['id' => $dub->id]))->assertNotFound();
    }

    public function test_a_plan_with_dubbing_pays_in_credits_and_downloads_the_clean_copy(): void
    {
        $this->openToEveryone();
        $this->fakeEngine();
        $user = $this->member(dubbing: true);

        $sent = $this->actingAs($user)->post(route('dub.store'), $this->form(), ['Accept' => 'application/json'])
            ->assertStatus(202)->assertJsonPath('free', false);
        $dub = VideoDub::query()->findOrFail($sent->json('id'));

        $this->assertSame(VideoDub::READY, $dub->status, $dub->error_message ?? '');
        $this->assertTrue($dub->has_clean);
        $this->assertFalse($dub->has_preview, 'no marked copy for a paid dub');
        $this->assertSame(VideoDubbing::estimate(6.0)['credits'], (int) UsageCounter::query()->where('user_id', $user->id)->value('total_chars'));
        $this->assertSame(90, VideoDubbing::estimate(6.0)['credits'], 'six seconds of video, six seconds of voice');

        $this->get(route('dub.download', ['id' => $dub->id]))->assertOk()->assertDownload('listen-dub-'.$dub->id.'.mp4');
    }

    public function test_a_failed_dub_gives_its_credits_back_and_old_files_are_deleted(): void
    {
        $this->openToEveryone();
        $this->fakeEngine(segments: []);
        $user = $this->member(dubbing: true);

        $sent = $this->actingAs($user)->post(route('dub.store'), $this->form(), ['Accept' => 'application/json'])->assertStatus(202);
        $dub = VideoDub::query()->findOrFail($sent->json('id'));

        $this->assertSame(VideoDub::FAILED, $dub->status);
        $this->assertSame(__('ui.dub.error_no_speech'), $dub->error_message);
        $this->assertSame(0, (int) UsageCounter::query()->where('user_id', $user->id)->value('total_chars'), 'given back');

        $this->travel(49)->hours();
        $this->artisan('dubs:prune')->assertSuccessful();
        $this->assertNotNull($dub->fresh()->files_deleted_at);
        $this->assertFalse(Storage::disk('local')->exists($dub->folder));
    }

    public function test_a_video_too_long_or_without_sound_is_refused_before_anything_is_spent(): void
    {
        $this->openToEveryone();
        $user = $this->member(dubbing: true);
        config(['listen.dubbing.max_seconds' => 4]);

        $this->actingAs($user)->post(route('dub.store'), $this->form(), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.video.0', __('ui.dub.too_long', ['seconds' => 4]));
        $this->assertSame(0, VideoDub::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function openToEveryone(): void
    {
        SiteSetting::set('video_dubbing_audience', VideoDubbing::AUDIENCE_EVERYONE, 'video_dubbing');
    }

    private function member(bool $dubbing): User
    {
        $plan = Plan::factory()->create(['monthly_chars' => 100000, 'monthly_requests' => 100, 'price_cents' => $dubbing ? 2200 : 0,
            'video_dubbing_enabled' => $dubbing]);
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active',
            'current_period_start' => now()->startOfMonth(), 'current_period_end' => now()->endOfMonth()]);

        return $user;
    }

    /** @return array<string, mixed> */
    private function form(): array
    {
        return [
            'video' => new UploadedFile($this->video, 'holiday clip.mp4', 'video/mp4', null, true),
            'dialect' => 'ar-SA',
            'voice' => 'Puck',
            'performance_style' => 'conversational',
        ];
    }

    /** @param  array<int, array<string, mixed>>|null  $segments */
    private function fakeEngine(?array $segments = null): void
    {
        $segments ??= [['start' => 0.5, 'end' => 3.0, 'original' => 'Hello there.', 'dubbed' => 'هلا والله.']];
        $ffmpeg = AudioFormats::ffmpegPath();
        $keys = \Mockery::mock(GeminiKeyManager::class);
        $keys->shouldReceive('keysInOrder')->andReturn([['key' => 'k1', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1]]);
        $keys->shouldReceive('recordUsage');
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['segments' => $segments])]]]]],
            'usageMetadata' => ['promptTokenCount' => 200, 'candidatesTokenCount' => 50],
        ])]);
        $tts = \Mockery::mock(GeminiTtsProvider::class);
        $tts->shouldReceive('synthesize')->andReturnUsing(function () use ($ffmpeg) {
            $path = 'audio/line-'.bin2hex(random_bytes(3)).'.mp3';
            $file = Storage::disk('public')->path($path);
            @mkdir(dirname($file), 0755, true);
            (new Process([$ffmpeg, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=500:duration=2', '-c:a', 'libmp3lame', $file]))->mustRun();

            return ['audio_path' => $path, 'duration_seconds' => 2];
        });
        $this->app->instance(VideoDubber::class, new VideoDubber($keys, $tts));
    }
}
