<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\VoiceSample;
use App\Support\SiteDesign;
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

    public function test_new_design_home_shows_flags_voices_and_dialogues(): void
    {
        SiteSetting::set(SiteDesign::SETTING_KEY, SiteDesign::NEW);
        $this->artisan('tts:showcase', ['--import' => true]);

        $en = $this->get('/en')->assertOk();
        $en->assertSee('images/flags/sa.svg', false);
        $en->assertSee('images/flags/gb-sct.svg', false);
        $en->assertSee('data-nt-dialogue', false);
        $en->assertSee('These voices were created with Listen.');
        $en->assertSee('Saudi (Najdi)');
        $en->assertDontSee('flagcdn.com', false);
        $en->assertDontSee('&lt;laugh&gt;', false);

        $ar = $this->get('/ar')->assertOk();
        $ar->assertSee('سعودي (نجدي)');
        $ar->assertSee('هذا الصوت من إنتاج منصة إسمع.');
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
