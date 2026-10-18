<?php

namespace Tests\Feature;

use App\Models\VoiceSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceSamplesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_page_has_every_latest_voice_in_its_own_language(): void
    {
        $this->artisan('tts:showcase', ['--import' => true]);
        // An older recording is never on the page.
        VoiceSample::create([
            'title' => 'Old recording', 'sample_text' => 'An older voice.', 'audio_path' => 'audio/old.mp3',
            'language' => 'en', 'category' => 'hero', 'is_active' => true, 'provider' => 'elevenlabs',
        ]);

        app()->setLocale('en');
        $en = $this->getLocalizedRoute('samples')->assertOk();
        $this->assertCount(29, $en->viewData('voices'));
        $this->assertCount(6, $en->viewData('dialogues'));
        $this->assertNotEmpty($en->viewData('moodVoices'));
        $this->assertCount(6, $en->viewData('environments'));
        $this->assertStageCast($en->viewData('stageCast'), 'en');
        // English only: voices, moods, conversations, places and the stage.
        foreach (['voices', 'moodVoices', 'dialogues', 'environments', 'stageCast'] as $key) {
            $this->assertSame(['en'], array_values(array_unique(array_column($en->viewData($key), 'language'))), $key);
        }
        $en->assertSeeInOrder(['id="english-accents"', 'id="moods"', 'id="conversations"', 'id="sound-environments"'], false);
        $en->assertDontSee('id="arabic-dialects"', false);
        $en->assertDontSee('data-samples-lang', false);
        $en->assertSee('<title>English AI Voice Samples: Accents, Moods and Conversations', false);
        $en->assertSee('images/speakers/en-daisy.webp', false);
        $en->assertSee('data-vp-region="uk_ie"', false);
        $en->assertDontSee('images/speakers/ar-', false);
        $en->assertDontSee('An older voice.');
        $en->assertDontSee('&lt;laugh&gt;', false);
        $en->assertDontSee('These voices were created with Listen.');

        app()->setLocale('ar');
        $ar = $this->getLocalizedRoute('samples')->assertOk();
        $this->assertCount(38, $ar->viewData('voices'));
        $this->assertCount(8, $ar->viewData('dialogues'));
        $this->assertCount(6, $ar->viewData('environments'));
        $this->assertStageCast($ar->viewData('stageCast'), 'ar');
        foreach (['voices', 'moodVoices', 'dialogues', 'environments', 'stageCast'] as $key) {
            $this->assertSame(['ar'], array_values(array_unique(array_column($ar->viewData($key), 'language'))), $key);
        }
        $ar->assertSee('id="arabic-dialects"', false);
        $ar->assertDontSee('id="english-accents"', false);
        $ar->assertSee('نماذج الأصوات');
        $ar->assertSee('سعودي (نجدي)');
        $ar->assertSee('data-vp-region="maghreb"', false);
        $ar->assertDontSee('images/speakers/en-', false);
    }

    /**
     * The stage plays other voices than the homepage hero, each once, each with a time for every word.
     *
     * @param  array<int, array<string, mixed>>  $cast
     */
    private function assertStageCast(array $cast, string $language): void
    {
        $this->assertCount(8, $cast);
        $hero = [];
        foreach (\App\Support\HeroCast::CAST[$language] as $person) {
            $hero[] = $person['name'];
            $hero[] = $person['name_ar'] ?? $person['name'];
        }
        $names = array_column($cast, 'name');
        $this->assertSame([], array_values(array_intersect($names, $hero)));
        $this->assertSame($names, array_values(array_unique($names)));
        foreach ($cast as $person) {
            $this->assertCount(count(preg_split('/\s+/u', trim($person['line']))), $person['times'], $person['name']);
            $previous = 0.0;
            foreach ($person['times'] as [$start, $end]) {
                $this->assertGreaterThanOrEqual($previous, $start, $person['name']);
                $this->assertGreaterThan($start, $end, $person['name']);
                $previous = $end;
            }
        }
        // More than one region in the run.
        $this->assertGreaterThan(3, count(array_unique(array_column($cast, 'dialect_label'))));
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
