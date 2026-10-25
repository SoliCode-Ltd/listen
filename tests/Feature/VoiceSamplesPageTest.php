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
        // Every speaker once, in their own accent.
        $this->assertCount(87, $en->viewData('voices'));
        $this->assertCount(6, $en->viewData('dialogues'));
        $this->assertNotEmpty($en->viewData('moodVoices'));
        $this->assertCount(6, $en->viewData('environments'));
        $this->assertStageCast($en->viewData('stageCast'), 'en');
        // English only: voices, moods, conversations, places and the stage (the speakers
        // who speak another language natively are grouped apart).
        foreach (['voices', 'moodVoices', 'dialogues', 'environments', 'stageCast'] as $key) {
            $english = array_filter($en->viewData($key), fn (array $card): bool => ($card['region'] ?? '') !== 'languages');
            $this->assertSame(['en'], array_values(array_unique(array_column($english, 'language'))), $key);
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
        $this->assertCount(155, $ar->viewData('voices'));
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

    public function test_the_voice_library_speakers_join_the_page_each_once(): void
    {
        $this->artisan('tts:showcase', ['--import' => true]);
        $moods = array_keys(__('ui.samples.moods'));

        foreach (['en' => 87, 'ar' => 155] as $language => $speakers) {
            app()->setLocale($language);
            $page = $this->getLocalizedRoute('samples')->assertOk();
            $voices = $page->viewData('voices');

            // No speaker, clip or line twice.
            foreach (['avatar', 'audio_url', 'text'] as $key) {
                $values = array_column($voices, $key);
                $this->assertSame($values, array_values(array_unique($values)), $language.' '.$key);
            }
            // Every speaker is on the page once, in their own dialect or accent, so every one is heard.
            $this->assertCount($speakers, $voices);
            $own = [];
            foreach (\App\Support\VoicePreviews::lines() as $slug => $line) {
                if (str_starts_with($slug, $language.'-')) {
                    $own[] = $line['dialect'];
                }
            }
            $this->assertEqualsCanonicalizing(array_values(array_unique($own)), array_values(array_unique(array_column($voices, 'dialect'))));
            foreach ($voices as $card) {
                $this->assertNotNull($card['avatar'], $card['speaker']);
                $this->assertContains($card['emotion'], $moods, $card['speaker']);
                $this->assertNotSame('', $card['title'], $card['speaker']);
            }
            $this->assertCount(count($voices), $page->viewData('moodVoices'));
        }

        app()->setLocale('en');
        $en = $this->getLocalizedRoute('samples')->assertOk();
        $byAvatar = [];
        foreach ($en->viewData('voices') as $card) {
            $byAvatar[basename((string) parse_url($card['avatar'], PHP_URL_PATH))] = $card;
        }
        // Theodore's homepage sample is in his own accent (British): it stays, his preview is left out.
        $this->assertStringNotContainsString('voice-previews', $byAvatar['en-theodore.webp']['audio_url']);
        $en->assertDontSee('voice-previews/en-theodore.mp3', false);
        // Daisy's homepage sample is in another accent: her own (Appalachian) preview is there instead.
        $daisy = $byAvatar['en-daisy.webp'];
        $this->assertStringContainsString('voice-previews/en-daisy.mp3', $daisy['audio_url']);
        $this->assertSame(['en-US-AP', 'americas', 'Coach', 'confident'], [$daisy['dialect'], $daisy['region'], $daisy['title'], $daisy['emotion']]);
        // Hannah has no homepage sample: her preview, as a trip leader.
        $hannah = $byAvatar['en-hannah.webp'];
        $this->assertStringContainsString('voice-previews/en-hannah.mp3', $hannah['audio_url']);
        $this->assertSame(['americas', 'Trip leader', 'confident'], [$hannah['region'], $hannah['title'], $hannah['emotion']]);
        // Chloe speaks French: in "Other languages", marked as French.
        $chloe = $byAvatar['en-chloe.webp'];
        $this->assertSame(['languages', 'fr', 'French (France)'], [$chloe['region'], $chloe['language'], $chloe['dialect_label']]);
        $en->assertSee('data-vp-region="languages"', false);
        $en->assertSee('Other languages');

        app()->setLocale('ar');
        $ar = $this->getLocalizedRoute('samples')->assertOk();
        // An Arabic preview card: the speaker's Arabic name and title, in their region.
        $ar->assertSee('voice-previews/ar-hassan.mp3', false);
        $hassan = collect($ar->viewData('voices'))->first(fn (array $card): bool => str_contains($card['audio_url'], 'ar-hassan.mp3'));
        $this->assertSame(['gulf', 'قبطان قارب', 'حسن'], [$hassan['region'], $hassan['title'], $hassan['speaker']]);
        $ar->assertDontSee('data-vp-region="languages"', false);
    }

    public function test_every_preview_has_a_card_title_and_mood(): void
    {
        $previews = array_keys(\App\Support\VoicePreviews::lines());
        $cards = (require database_path('seeders/data/voice_preview_cards.php'))['cards'];
        $this->assertEqualsCanonicalizing($previews, array_keys($cards));
        $moods = array_keys(__('ui.samples.moods'));
        foreach ($cards as $slug => [$title, $mood]) {
            $this->assertContains($mood, $moods, $slug);
            // Arabic titles for the Arabic page, English ones for the English page.
            $this->assertSame(str_starts_with($slug, 'ar-'), (bool) preg_match('/\p{Arabic}/u', $title), $slug);
        }
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
