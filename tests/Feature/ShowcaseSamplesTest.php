<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\VoiceSample;
use App\Support\VoiceFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShowcaseSamplesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_showcase_sample_has_its_audio_and_a_sign_off(): void
    {
        $data = require database_path('seeders/data/showcase_samples.php');

        foreach ($data['samples'] as $sample) {
            $this->assertFileExists(public_path('audio/showcase/'.$sample['slug'].'.mp3'), $sample['slug']);
            $this->assertArrayHasKey($sample['language'].($sample['kind'] === 'dialogue' ? '_dialogue' : ''), $data['sign_off']);
        }
        $this->assertStringContainsString('Listen', $data['sign_off']['en']);
        $this->assertStringContainsString('إسمع', $data['sign_off']['ar']);
    }

    public function test_import_adds_the_samples_and_switches_off_the_old_ones(): void
    {
        $old = VoiceSample::create([
            'title' => 'Old hero', 'sample_text' => 'old', 'audio_path' => 'audio/old.wav',
            'language' => 'ar', 'category' => 'hero', 'is_active' => true,
        ]);

        $this->artisan('tts:showcase', ['--import' => true])->assertSuccessful();

        $this->assertFalse($old->fresh()->is_active);
        $najdi = VoiceSample::where('audio_path', 'audio/showcase/ar-sa-najdi-ad.mp3')->firstOrFail();
        $this->assertSame('ar-SA', $najdi->dialect);
        $this->assertSame('SA', $najdi->country);
        $this->assertSame('gemini-3.8-flash-tts', $najdi->metadata['model']);
        $this->assertGreaterThan(0, $najdi->duration_seconds);

        $dialogue = VoiceSample::where('audio_path', 'audio/showcase/dlg-ar-sa-podcast.mp3')->firstOrFail();
        $this->assertSame('dialogue', $dialogue->category);
        $this->assertCount(4, $dialogue->metadata['turns']);

        // Arabic-accent English is not Argentina.
        $this->assertNull(VoiceSample::where('dialect', 'en-AR')->value('country'));

        // Running it again updates in place.
        $count = VoiceSample::count();
        $this->artisan('tts:showcase', ['--import' => true])->assertSuccessful();
        $this->assertSame($count, VoiceSample::count());
    }

    public function test_generate_needs_a_key_and_never_calls_out_without_one(): void
    {
        config(['services.gemini_tts.api_key' => '']);
        Http::fake();

        $this->artisan('tts:showcase', ['--generate' => true])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_home_shows_avatars_voices_and_dialogues_in_the_page_language(): void
    {
        $this->artisan('tts:showcase', ['--import' => true]);

        app()->setLocale('en');
        $en = $this->getLocalizedRoute('home')->assertOk();
        $en->assertSee('images/flags/gb-sct.svg', false);
        $en->assertSee('images/speakers/en-daisy.webp', false);
        $en->assertSee('data-vp-dialogue', false);
        $en->assertSee('data-vp-region="uk_ie"', false);
        $en->assertSee('css/voice-showcase.css', false);
        $en->assertDontSee('images/flags/sa.svg', false);
        $en->assertDontSee('images/speakers/ar-', false);
        $en->assertDontSee('flagcdn.com', false);
        $en->assertDontSee('&lt;laugh&gt;', false);
        // The spoken sign-off stays in the audio but is not printed.
        $en->assertDontSee('These voices were created with Listen.');
        $this->assertCount(29, $en->viewData('showcaseVoices'));
        $this->assertCount(6, $en->viewData('showcaseDialogues'));

        app()->setLocale('ar');
        $ar = $this->getLocalizedRoute('home')->assertOk();
        $ar->assertSee('سعودي (نجدي)');
        $ar->assertSee('images/flags/sa.svg', false);
        $ar->assertSee('images/speakers/ar-sultan.webp', false);
        $ar->assertSee('data-vp-region="maghreb"', false);
        $ar->assertDontSee('images/speakers/en-', false);
        $ar->assertDontSee('هذه الأصوات من إنتاج منصة إسمع.');
        $ar->assertDontSee('images/flags/gb-sct.svg', false);
        $this->assertCount(38, $ar->viewData('showcaseVoices'));
        $this->assertCount(8, $ar->viewData('showcaseDialogues'));

        // Every dialogue line knows when it is spoken.
        foreach (array_merge($en->viewData('showcaseDialogues'), $ar->viewData('showcaseDialogues')) as $dialogue) {
            foreach ($dialogue['turns'] as $i => $turn) {
                $this->assertNotNull($turn['start'], $dialogue['title'].' line '.$i);
                $this->assertGreaterThan($turn['start'], $turn['end']);
            }
        }
        $ar->assertSee('data-start="0.', false);

        // No model numbers on the page.
        foreach ([$en->getContent(), $ar->getContent()] as $html) {
            $this->assertDoesNotMatchRegularExpression('/\b3\.8\b|model 3|نموذج الصوت/u', strip_tags($html));
        }
    }

    public function test_every_active_speaker_has_an_avatar(): void
    {
        $this->seed(\Database\Seeders\VoiceSeeder::class);

        $missing = \App\Models\Voice::query()->where('is_active', true)->get()
            ->filter(fn ($voice) => \App\Support\SpeakerAvatar::url($voice->display_name, $voice->language) === null)
            ->map(fn ($voice) => $voice->language.' '.$voice->display_name)->values()->all();
        $this->assertSame([], $missing);

        $catalog = \App\Support\VoiceCatalog::publicCatalog();
        $this->assertStringContainsString('images/speakers/', (string) $catalog['ar'][0]['avatar']);
    }

    public function test_voice_flags(): void
    {
        $this->assertSame('sa', VoiceFlag::code('ar-SA-HJ'));
        $this->assertSame('gb-sct', VoiceFlag::code('en-GB-SC'));
        $this->assertSame('us', VoiceFlag::code('en-US-NY', 'US'));
        $this->assertNull(VoiceFlag::code('ar-XA', 'XA'));
        $this->assertNull(VoiceFlag::code('en-AR', 'AR'));
        $this->assertSame('مصري (قاهري)', VoiceFlag::label('ar-EG', true));
    }
}
