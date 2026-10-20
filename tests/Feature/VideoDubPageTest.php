<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Models\VideoDub;
use App\Models\Voice;
use App\Services\DemoLinkedAccounts;
use App\Services\Dubbing\VideoDubber;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Support\AudioFormats;
use App\Support\VideoDubbing;
use App\Support\VoiceCatalog;
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
        // A visitor sees the whole studio, with signing up where the upload goes.
        $this->get(route('dub.page'))->assertOk()->assertSee('data-dub-studio', false)->assertSee('"state":"guest"', false)
            ->assertSee(__('ui.dub.hero_title'))->assertDontSee('New: video dubbing');
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
        $this->assertSame('Puck', $dub->voice, 'the studio voice card resolves to its speech engine voice');
        $this->assertSame('conversational', $dub->performance_style);
        $this->assertNull($dub->emotion, 'left on "As in the video": each line takes the original\'s');
        $this->assertTrue($dub->keep_background);
        $this->assertSame(100, $dub->progress);
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
        $this->assertSame('ui.dub.error_no_speech', $dub->error_message, 'kept as a key');
        $this->assertSame(__('ui.dub.error_no_speech'), $dub->errorText());
        app()->setLocale('ar');
        $this->assertSame(__('ui.dub.error_no_speech', [], 'ar'), $dub->errorText(), 'said in the reader\'s language');
        app()->setLocale('en');
        $this->assertSame(0, (int) UsageCounter::query()->where('user_id', $user->id)->value('total_chars'), 'given back');

        $this->travel(49)->hours();
        $this->artisan('dubs:prune')->assertSuccessful();
        $this->assertNotNull($dub->fresh()->files_deleted_at);
        $this->assertFalse(Storage::disk('local')->exists($dub->folder));
    }

    public function test_the_admin_sees_every_dub_with_its_figures_lines_and_videos(): void
    {
        $this->openToEveryone();
        $this->fakeEngine();
        $member = $this->member(dubbing: true);
        $sent = $this->actingAs($member)->post(route('dub.store'), $this->form(), ['Accept' => 'application/json'])->assertStatus(202);
        $dub = VideoDub::query()->findOrFail($sent->json('id'));
        $this->assertNotNull(VideoDubbing::cost($dub->report)['total'] ?? null, 'a finished dub has a cost');

        // A member cannot open the admin's copy of a video.
        $this->get(route('admin.video-dubs.file', ['dub' => $dub->id, 'kind' => 'clean']))->assertForbidden();

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(\App\Filament\Resources\VideoDubResource::getUrl('index'))
            ->assertOk()->assertSee('Dub my video')->assertSee('Dubs today')->assertSee($member->email)->assertSee('Saudi (Najdi)');
        $this->actingAs($admin)->get(\App\Filament\Resources\VideoDubResource::getUrl('view', ['record' => $dub]))
            ->assertOk()->assertSee('Othman (Puck)')->assertSee('هلا والله.')->assertSee('Hello there.')
            ->assertSee('/admin-video/dubs/'.$dub->id.'/clean', false)->assertSee('90 credits', false);
        $this->get(route('admin.video-dubs.file', ['dub' => $dub->id, 'kind' => 'clean']))->assertOk();
        $this->get(route('filament.admin.pages.video-dubbing-settings'))->assertOk();
    }

    public function test_the_admin_list_opens_after_a_free_dub_and_a_failed_one(): void
    {
        // As on a server whose settings were cached before dubbing had prices.
        config(['listen.dubbing' => array_diff_key(config('listen.dubbing'), ['prices' => 1])]);
        $this->openToEveryone();
        $this->fakeEngine();
        $member = $this->member(dubbing: false);
        $sent = $this->actingAs($member)->post(route('dub.store'), $this->form(), ['Accept' => 'application/json'])->assertStatus(202);
        $this->fakeEngine(segments: []);
        VideoDub::query()->whereKey($sent->json('id'))->update(['is_free' => false]);
        $this->actingAs($member)->post(route('dub.store'), $this->form(), ['Accept' => 'application/json']);
        VideoDub::query()->create(['user_id' => $member->id, 'status' => VideoDub::PROCESSING, 'is_free' => true, 'dialect' => 'ar-SA-FF', 'voice' => 'Puck', 'video_seconds' => 34]);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(\App\Filament\Resources\VideoDubResource::getUrl('index'))->assertOk();
        foreach (VideoDub::query()->get() as $dub) {
            $this->actingAs($admin)->get(\App\Filament\Resources\VideoDubResource::getUrl('view', ['record' => $dub]))->assertOk();
        }
    }

    public function test_the_demo_switches_between_text_to_speech_and_the_free_dub(): void
    {
        // The demo is for accounts without a plan.
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_status_checked_at' => now()]);
        $this->form();
        // Not open yet: the demo is as it was.
        $this->actingAs($user)->get(route('demo.page'))->assertOk()->assertDontSee('data-studio-switch', false)->assertDontSee('data-dub-studio', false);

        $this->openToEveryone();
        $page = $this->actingAs($user)->get(route('demo.page'))->assertOk()
            ->assertSee('data-studio-switch', false)->assertSee('data-dub-studio', false)->assertSee('data-studio-host', false)
            ->assertSee(__('ui.dub.mode_dub'))->assertSee('"state":"free"', false)
            ->assertSee('js/dub-studio.js', false)->getContent();
        // The text studio keeps its own fields; the dubbing studio's are its own.
        $this->assertSame(1, substr_count($page, 'id="voice_key"'));
        $this->assertSame(1, substr_count($page, 'id="dub_voice_key"'));
        $this->assertLessThan(strpos($page, 'js/voice-studio.js'), strpos($page, 'js/dub-studio.js'), 'the selects are filled before the pickers are drawn');
    }

    public function test_the_dashboard_has_its_own_dubbing_section_with_what_each_dub_took(): void
    {
        $this->openToEveryone();
        $this->fakeEngine();
        $member = $this->member(dubbing: true);
        Plan::query()->update(['video_dubs_per_month' => 5]);
        $this->actingAs($member)->post(route('dub.store'), $this->form(), ['Accept' => 'application/json'])->assertStatus(202);

        $this->actingAs($member)->get(route('dashboard'))->assertOk()
            ->assertSee('href="#dubVideoSection"', false)->assertSee('id="dubVideoSection"', false)
            ->assertSee(__('ui.dub.ledger_title'))->assertSee(__('ui.dub.stat_videos_of', ['limit' => 5]))
            ->assertSee('holiday clip.mp4')->assertSee('"state":"paid"', false)
            // Six seconds of video, ninety credits, in the row and the month's total.
            ->assertSee('<td class="num">90</td>', false)
            ->assertSee(__('ui.dub.rule', ['credits' => 15, 'example' => '450']));
    }

    public function test_a_plan_sets_its_videos_a_month(): void
    {
        $this->openToEveryone();
        $this->fakeEngine();
        $member = $this->member(dubbing: true);
        Plan::query()->update(['video_dubs_per_month' => 1]);

        $this->actingAs($member)->post(route('dub.store'), $this->form(), ['Accept' => 'application/json'])
            ->assertStatus(202)->assertJsonPath('studio.state', 'limit')->assertJsonPath('studio.usage.dubs_used', 1);
        $this->actingAs($member)->post(route('dub.store'), $this->form(), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.video.0', trans_choice('ui.dub.month_used', 1, ['count' => 1]));
        $this->assertSame(1, VideoDub::query()->count());
        $this->assertSame(90, (int) UsageCounter::query()->where('user_id', $member->id)->value('total_chars'), 'the refused one took nothing');

        // A failed dub does not count against the month.
        VideoDub::query()->update(['status' => VideoDub::FAILED]);
        $this->assertSame('paid', VideoDubbing::studio($member, request())['state']);
    }

    public function test_plan_cards_say_what_dubbing_includes_once_it_is_open(): void
    {
        $plan = Plan::factory()->create(['price_cents' => 900, 'video_dubbing_enabled' => true, 'video_dubs_per_month' => 20, 'video_dub_max_seconds' => 90]);
        $this->assertNull(\App\Support\PlanPricingLines::value($plan, 'video_dubbing', 'en'), 'hidden while only the admin sees dubbing');

        $this->openToEveryone();
        $this->assertSame('20 videos a month, up to 90 s each', \App\Support\PlanPricingLines::value($plan, 'video_dubbing', 'en'));
        $plan->update(['video_dubs_per_month' => 0]);
        $this->assertSame('Up to 90 s a video, from your voice time', \App\Support\PlanPricingLines::value($plan->fresh(), 'video_dubbing', 'en'));
        $this->assertNull(\App\Support\PlanPricingLines::value(Plan::factory()->create(['video_dubbing_enabled' => false]), 'video_dubbing', 'en'));
    }

    public function test_the_page_follows_a_dub_step_by_step(): void
    {
        $this->openToEveryone();
        $member = $this->member(dubbing: true);
        $dub = VideoDub::query()->create(['user_id' => $member->id, 'status' => VideoDub::PROCESSING, 'stage' => 'voicing', 'progress' => 47,
            'is_free' => false, 'dialect' => 'ar-EG', 'voice' => 'Puck', 'video_seconds' => 20]);

        $this->actingAs($member)->getJson(route('dub.status', ['id' => $dub->id]))
            ->assertOk()->assertJsonPath('stage', 'voicing')->assertJsonPath('progress', 47)->assertJsonPath('watch_url', null);
    }

    public function test_a_video_can_be_dubbed_into_english_with_an_accent(): void
    {
        $this->openToEveryone();
        $this->fakeEngine();
        $member = $this->member(dubbing: true);
        $english = Voice::query()->create(['internal_voice_id' => 'gemini-en-oliver', 'language' => 'en', 'gender' => 'male', 'tier' => 'hd',
            'display_name' => 'Oliver', 'is_active' => true, 'provider' => 'gemini', 'provider_voice_id' => 'Achird']);
        $form = ['language' => 'en', 'dialect' => 'en-GB', 'voice_key' => VoiceCatalog::keyFromId((int) $english->id)] + $this->form();

        // An Arabic voice or an Arabic dialect is not an English dub.
        $this->actingAs($member)->post(route('dub.store'), ['dialect' => 'ar-SA'] + $form, ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('dialect');
        $this->actingAs($member)->post(route('dub.store'), ['voice_key' => $this->form()['voice_key']] + $form, ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('voice_key');

        $sent = $this->actingAs($member)->post(route('dub.store'), $form, ['Accept' => 'application/json'])->assertStatus(202);
        $dub = VideoDub::query()->findOrFail($sent->json('id'));
        $this->assertSame(['en', 'en-GB', 'Achird', VideoDub::READY], [$dub->language, $dub->dialect, $dub->voice, $dub->status]);

        // The studio offers both, each with its own voices and its dialects or accents.
        $studio = VideoDubbing::studio($member, request());
        $this->assertSame(['ar', 'en'], array_keys($studio['languages']));
        $this->assertSame('Oliver', $studio['languages']['en']['voices'][0]['label']);
        $this->assertArrayHasKey('en-GB', $studio['languages']['en']['dialects']);
        $this->assertArrayHasKey('ar-SA', $studio['languages']['ar']['dialects']);
    }

    public function test_a_voice_the_library_does_not_offer_is_refused(): void
    {
        $this->openToEveryone();
        $user = $this->member(dubbing: true);

        $this->actingAs($user)->post(route('dub.store'), ['voice_key' => 'voice_999999'] + $this->form(), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('voice_key');
        $this->assertSame(0, VideoDub::query()->count());
    }

    public function test_the_page_shows_the_studio_voice_library(): void
    {
        $this->openToEveryone();
        $this->form();
        $this->actingAs($this->member(dubbing: true))->get(route('dub.page'))->assertOk()
            ->assertSee('data-vs-panel', false)->assertSee('data-vs-library', false)->assertSee('name="performance_style"', false)
            ->assertSee('name="emotion"', false)->assertSee('Othman');
    }

    public function test_a_video_too_long_or_without_sound_is_refused_before_anything_is_spent(): void
    {
        $this->openToEveryone();
        $user = $this->member(dubbing: true);
        // The plan's longest video.
        Plan::query()->update(['video_dub_max_seconds' => 5]);

        $this->actingAs($user)->post(route('dub.store'), $this->form(), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.video.0', __('ui.dub.too_long', ['seconds' => 5]));
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
        $voice = Voice::query()->firstOrCreate(['internal_voice_id' => 'gemini-ar-othman'], ['language' => 'ar', 'gender' => 'male', 'tier' => 'hd',
            'display_name' => 'Othman', 'is_active' => true, 'provider' => 'gemini', 'provider_voice_id' => 'Puck']);

        return [
            'video' => new UploadedFile($this->video, 'holiday clip.mp4', 'video/mp4', null, true),
            'dialect' => 'ar-SA',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
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
