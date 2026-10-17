<?php

namespace Tests\Feature;

use App\Models\VoiceSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceSamplesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_has_every_latest_voice_in_both_languages(): void
    {
        $this->artisan('tts:showcase', ['--import' => true]);
        // An older recording is never on the page.
        VoiceSample::create([
            'title' => 'Old recording', 'sample_text' => 'An older voice.', 'audio_path' => 'audio/old.mp3',
            'language' => 'en', 'category' => 'hero', 'is_active' => true, 'provider' => 'elevenlabs',
        ]);

        app()->setLocale('en');
        $en = $this->getLocalizedRoute('samples')->assertOk();
        $this->assertCount(38, $en->viewData('arabicVoices'));
        $this->assertCount(29, $en->viewData('englishVoices'));
        $this->assertCount(14, $en->viewData('dialogues'));
        $this->assertNotEmpty($en->viewData('moodVoices'));
        $this->assertCount(12, $en->viewData('environments'));
        $this->assertCount(10, $en->viewData('stageCast'));
        // The page language comes first: on the stage and in the sections.
        $this->assertSame('en', $en->viewData('stageCast')[0]['language']);
        $this->assertSame('en', $en->viewData('environments')[0]['language']);
        $en->assertSeeInOrder(['id="english-accents"', 'id="arabic-dialects"', 'id="moods"', 'id="conversations"', 'id="sound-environments"'], false);
        $en->assertSee('<title>AI Voice Samples: Arabic Dialects and English Accents', false);
        $en->assertSee('images/speakers/ar-sultan.webp', false);
        $en->assertSee('images/speakers/en-daisy.webp', false);
        $en->assertSee('data-vp-region="maghreb"', false);
        $en->assertSee('data-vp-region="uk_ie"', false);
        $en->assertDontSee('An older voice.');
        $en->assertDontSee('&lt;laugh&gt;', false);
        $en->assertDontSee('These voices were created with Listen.');

        app()->setLocale('ar');
        $ar = $this->getLocalizedRoute('samples')->assertOk();
        $this->assertSame('ar', $ar->viewData('stageCast')[0]['language']);
        $ar->assertSeeInOrder(['id="arabic-dialects"', 'id="english-accents"'], false);
        $ar->assertSee('نماذج الأصوات');
        $ar->assertSee('سعودي (نجدي)');
        // English names keep their order inside an Arabic sentence.
        $ar->assertSee('<bdi>Daisy</bdi> و<bdi>Theodore</bdi>', false);
    }

    public function test_the_menu_footer_and_home_link_to_the_page(): void
    {
        $this->artisan('tts:showcase', ['--import' => true]);

        app()->setLocale('en');
        $home = $this->getLocalizedRoute('home')->assertOk();
        $home->assertSee('href="'.route('samples').'"', false);
        $home->assertSee('>Voice samples</a>', false);
        // "Show all" opens the samples page at the matching section, instead of expanding in place.
        $home->assertSee('class="vs-more-btn" href="'.route('samples').'#english-accents"', false);
        $home->assertSee('class="vs-more-btn" href="'.route('samples').'#conversations"', false);
        $home->assertDontSee('data-vp-more', false);

        app()->setLocale('ar');
        $this->getLocalizedRoute('home')->assertOk()
            ->assertSee('class="vs-more-btn" href="'.route('samples').'#arabic-dialects"', false);
    }

    public function test_the_page_is_in_the_sitemap(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertSee('/voice-samples', false);
    }
}
