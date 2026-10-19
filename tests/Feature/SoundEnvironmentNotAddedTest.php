<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\User;
use App\Support\AudioFormats;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\InteractsWithDeveloperApi;
use Tests\TestCase;

/**
 * When the server cannot add the sound environment a member chose, they get
 * the clean voice, charged as always. The history and the developer API no
 * longer say the environment is in it, the finished voice tells the member so
 * in their language, and Reuse still offers the environment they chose.
 */
class SoundEnvironmentNotAddedTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_an_environment_that_fails_is_not_claimed_and_the_member_is_told(): void
    {
        $this->ffmpegFails();
        $user = $this->apiMember($this->apiPlan(['sound_effects_enabled' => true]));
        $note = 'Your voice is ready, but the “Phone call” sound environment could not be added this time, so this is the clean voice.';

        $response = $this->studio($user)->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', $note)
            ->assertJsonPath('job.note', $note);

        $voice = AudioGeneration::query()->where('user_id', $user->id)->sole();
        $this->assertSame('done', $voice->status);
        $this->assertStringStartsNotWith('audio/effects/', $voice->audio_path, 'the clean voice');
        $this->get('/media/'.$voice->audio_path)->assertOk();
        $this->assertArrayNotHasKey('audio_effect', $voice->settings_snapshot);
        $this->assertSame('phone', $voice->settings_snapshot['audio_effect_not_added']);
        $this->assertSame('phone', $voice->reusableSettings()['audio_effect'], 'Reuse still offers it');
        $this->assertSame(60, $this->creditsSpent($user), 'charged for the audio, as always');

        $plain = $this->issueKey($user)['plain'];
        $this->apiGet($plain, '/history/'.$voice->id)->assertOk()->assertJsonPath('audio_effect', null);

        // A long text is made after the reply: the page hears it when it checks on the voice.
        $this->actingAs($user)->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ])->getJson($this->localizedRoute('dashboard.jobs.show', ['job' => $voice->id]))->assertOk()->assertJsonPath('job.note', $note);

        app()->setLocale('ar');
        $this->studio($user)->assertOk()->assertJsonPath('job.note', 'صوتك جاهز، لكن تعذّرت إضافة بيئة الصوت «مكالمة هاتفية» هذه المرة، فهذا هو الصوت النقي.');
    }

    public function test_the_studio_shows_the_note_with_the_finished_voice(): void
    {
        $html = $this->actingAs($this->apiMember())->getLocalizedRoute('dashboard')->assertOk()->getContent();

        $this->assertStringContainsString("if (job?.note) showFormMessage('note', String(job.note));", $html);
    }

    public function test_an_environment_that_is_added_has_no_note(): void
    {
        if (AudioFormats::ffmpegPath() === null) {
            $this->markTestSkipped('ffmpeg is not installed here.');
        }
        $user = $this->apiMember($this->apiPlan(['sound_effects_enabled' => true]));

        $this->studio($user)->assertOk()
            ->assertJsonPath('message', __('ui.backend.dashboard_voice_generated'))
            ->assertJsonPath('job.note', null);

        $voice = AudioGeneration::query()->where('user_id', $user->id)->sole();
        $this->assertStringStartsWith('audio/effects/', $voice->audio_path);
        $this->assertSame('phone', $voice->settings_snapshot['audio_effect']);
        $this->assertArrayNotHasKey('audio_effect_not_added', $voice->settings_snapshot);
    }

    /** The server has ffmpeg, so sound environments are offered, but every run of it fails. */
    private function ffmpegFails(): void
    {
        config(['services.gemini_tts.ffmpeg_path' => '/bin/false']);
    }

    private function studio(User $user): TestResponse
    {
        $voice = \App\Models\Voice::query()->where('internal_voice_id', 'en-Gemini-HD-Kore')->first() ?? $this->premiumVoice();

        return $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Hello there, this is a test.',
            'target_language' => 'en',
            'voice_tier' => 'hd',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
            'audio_effect' => 'phone',
        ]);
    }
}
