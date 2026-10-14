<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\TranslationService;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Services\Tts\GoogleCloudTtsProvider;
use App\Services\VoiceCloningService;
use App\Support\ArabicDialects;
use App\Support\GeminiTtsModel;
use App\Support\GeminiVocalTags;
use App\Support\TtsStudioConfig;
use App\Support\VoiceTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GeminiVocalTagsTest extends TestCase
{
    private const PERFORM = 'The transcript contains sound tags in angle brackets. Never read a tag aloud. At each tag, stop speaking and actually perform that sound with your voice, clearly and fully, then continue. ';

    use RefreshDatabase;

    public function test_every_supported_tag_is_kept_as_written(): void
    {
        foreach (GeminiVocalTags::all() as $tag) {
            $prepared = GeminiVocalTags::prepare("Well. <{$tag}> Go on.");
            $this->assertSame("Well. <{$tag}> Go on.", $prepared['text'], $tag);
            $this->assertSame([], $prepared['cues'], $tag);
        }
        $this->assertCount(48, GeminiVocalTags::all());
        $this->assertSame(GeminiVocalTags::all(), array_values(array_unique(GeminiVocalTags::all())));
    }

    public function test_bracketed_sounds_become_gemini_tags(): void
    {
        $prepared = GeminiVocalTags::prepare('[laughs] Really? [Sighs] Fine. (clears throat) Next. [whispering] Quiet. [pause] Done <Laughing>');

        $this->assertSame('<laugh> Really? <sigh> Fine. <throat-clearing> Next. <whispers> Quiet. <short pause> Done <laugh>', $prepared['text']);
        $this->assertSame([], $prepared['cues']);
    }

    public function test_moods_move_to_the_style_and_are_not_spoken(): void
    {
        $prepared = GeminiVocalTags::prepare("[excited] We won! [sarcastic, dry] Great.\n<happy> يا سلام! <laugh>");

        $this->assertSame("We won! Great.\nيا سلام! <laugh>", $prepared['text']);
        $this->assertSame(['excited and energetic', 'sarcastic, dry', 'happy and bright'], $prepared['cues']);
        $this->assertSame("Read warmly.\nDelivery cues from the script: excited and energetic, sarcastic, dry, happy and bright.", GeminiVocalTags::withCues('Read warmly.', $prepared['cues']));
        $this->assertSame('Read warmly.', GeminiVocalTags::withCues(' Read warmly. ', []));
    }

    public function test_ordinary_brackets_are_left_alone(): void
    {
        $text = 'See note [1] and [sic], ask (WHO) about it, as a < b and c > d, or (see page 4).';

        $prepared = GeminiVocalTags::prepare($text);

        $this->assertSame($text, $prepared['text']);
        $this->assertSame([], $prepared['cues']);
        $this->assertSame($text, GeminiVocalTags::strip($text));
        $this->assertSame($text, GeminiVocalTags::forLegacyModel($text));
    }

    public function test_older_model_and_standard_voices(): void
    {
        $this->assertSame('Hi [laugh] there [short pause] ok <happy>', GeminiVocalTags::forLegacyModel('Hi <laugh> there <short pause> ok <happy>'));

        $clean = GoogleCloudTtsProvider::stripPerformanceMarkup("Hi <laugh> there.\n<Short Pause> Ok <sigh>, fine.");
        $this->assertSame("Hi there.\nOk, fine.", $clean);
    }

    public function test_gemini_38_request_carries_tags_in_the_text_and_moods_in_the_style(): void
    {
        $this->fakeGeminiKeys();
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $captured = $this->fakeAudio();

        app(GeminiTtsProvider::class)->synthesize('[excited] We did it! [laughs] يا سلام.', 'ar', [
            'voice_name' => 'Kore',
            'performance_style' => 'neutral',
            'emotion' => 'neutral',
        ]);

        $part = $captured->bodies[0]['contents'][0]['parts'][0];
        $this->assertSame('We did it! <laugh> يا سلام.', $part['text']);
        $this->assertStringContainsString('Delivery cues from the script: excited and energetic.', $part['speech_metadata']['style']);
    }

    public function test_gemini_38_custom_prompt_keeps_the_cues_too(): void
    {
        $this->fakeGeminiKeys();
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $captured = $this->fakeAudio();

        app(GeminiTtsProvider::class)->synthesize('[nervous] Hello <gasp> there.', 'en', [
            'voice_name' => 'Kore',
            'style_prompt' => 'Speak like a radio host.',
        ]);

        $part = $captured->bodies[0]['contents'][0]['parts'][0];
        $this->assertSame('Hello. <gasp> there.', $part['text']);
        $this->assertSame("Speak like a radio host.\nDelivery cues from the script: nervous and hesitant.\n"
            .self::PERFORM.'<gasp> means: a sharp, audible gasp of surprise.', $part['speech_metadata']['style']);
    }

    public function test_gemini_38_dialogue_keeps_tags_on_each_turn(): void
    {
        $this->fakeGeminiKeys();
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $captured = $this->fakeAudio();

        app(GeminiTtsProvider::class)->synthesize("Sara: [laughs] You came!\nOmar: [sighs] Finally.", 'en', [
            'speakers' => [
                ['name' => 'Sara', 'voice_name' => 'Kore'],
                ['name' => 'Omar', 'voice_name' => 'Puck'],
            ],
        ]);

        $parts = $captured->bodies[0]['contents'][0]['parts'];
        $this->assertSame('<laugh> You came!', $parts[0]['text']);
        $this->assertSame('Sara', $parts[0]['speech_metadata']['speaker']);
        $this->assertSame('<sigh> Finally.', $parts[1]['text']);
        $this->assertSame('Omar', $parts[1]['speech_metadata']['speaker']);
    }

    public function test_only_tags_is_rejected_as_empty(): void
    {
        $this->fakeGeminiKeys();
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $this->fakeAudio();

        $this->expectExceptionMessage('TTS text is empty.');
        app(GeminiTtsProvider::class)->synthesize('[excited] <laugh>', 'en', ['voice_name' => 'Kore']);
    }

    public function test_gemini_25_request_gets_bracketed_directions(): void
    {
        $this->fakeGeminiKeys();
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::CURRENT, 'tts');
        $captured = $this->fakeAudio();

        app(GeminiTtsProvider::class)->synthesize('Hello <laugh> friend.', 'en', ['voice_name' => 'Kore']);

        $spoken = $captured->bodies[0]['contents'][0]['parts'][0]['text'];
        $this->assertStringContainsString('Hello [laugh] friend.', $spoken);
        $this->assertStringNotContainsString('<laugh>', $spoken);
    }

    public function test_cloned_voice_gets_tags_in_the_text_and_moods_in_the_style(): void
    {
        Storage::fake('public');
        config(['services.gemini_tts.api_key' => 'test-key', 'services.gemini_tts.voice_retry_pause' => false]);
        Http::fake(fn () => Http::response(['output_audio' => ['data' => base64_encode($this->wavBytes())]]));

        app(VoiceCloningService::class)->synthesizeWithClonedVoice('[excited] Hi [laughs] there.', 'en-US', 'voice_x', 'Calm and clear.');

        Http::assertSent(function ($request): bool {
            $content = $request->data()['input'][0]['content'][0] ?? [];

            return str_contains($request->url(), '/interactions')
                && ($content['text'] ?? null) === 'Hi. <laugh> there.'
                && ($content['annotations'][0]['style'] ?? null) === "Calm and clear.\nDelivery cues from the script: excited and energetic.\n"
                    .self::PERFORM.'<laugh> means: real, audible laughter (ha ha ha).';
        });
    }

    public function test_translation_keeps_tags_in_english(): void
    {
        Http::fake(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertStringNotContainsString('laugh', $query['q']);
            $this->assertStringNotContainsString('excited', $query['q']);
            // The translator keeps the placeholders and translates the words.
            $out = str_replace(['Hello', 'friend'], ['مرحبا', 'صديقي'], $query['q']);

            return Http::response([[[$out, $query['q']]]]);
        });

        $translated = app(TranslationService::class)->maybeTranslate('[excited] Hello <laugh> friend.', 'en', 'ar', true);

        $this->assertSame('[excited] مرحبا <laugh> صديقي.', $translated);
    }

    public function test_restore_drops_unknown_placeholders(): void
    {
        $this->assertSame('a <laugh> b', GeminiVocalTags::restore('a [[0]] b [[7]]', ['<laugh>']));
        $protected = GeminiVocalTags::protect('Note [1] stays, [laughs] goes.');
        $this->assertSame(['[laughs]'], $protected['tags']);
        $this->assertStringContainsString('[1]', $protected['text']);
    }

    public function test_premium_dashboard_shows_the_tag_picker(): void
    {
        $this->actingAs($this->memberOn(VoiceTier::HD));

        $html = $this->getLocalizedRoute('dashboard')
            ->assertOk()
            ->assertSee('data-vs-panel', false)
            ->assertSee('data-vs-backdrop', false)
            ->assertSee('js/voice-studio.js', false)
            ->assertSee('css/voice-studio.css', false)
            ->assertDontSee('data-vs-tab="sounds"', false)
            ->assertSee(__('ui.dashboard.vocal_tags_tip_items')[0])
            ->getContent();

        // The toolbar above the text is built from the tag list in the page.
        $tags = collect($this->studioMeta($html)['tagMenus']);
        $this->assertSame('laugh', $tags->firstWhere('t', 'laugh')['l']);
        $this->assertSame('sounds', $tags->firstWhere('t', 'short pause')['k']);
        $this->assertSame('delivery', $tags->firstWhere('t', 'excited')['k']);
        $this->assertSame('mood', $tags->firstWhere('t', 'excited')['g']);
        $this->assertSame('pace', $tags->firstWhere('t', 'child voice')['g']);
        $this->assertNull($tags->firstWhere('t', 'storytelling'), 'style tags stay out of the menus');
        $this->assertCount(count(GeminiVocalTags::all()) + count(GeminiVocalTags::deliveries()) - count(GeminiVocalTags::DELIVERY_GROUPS['style']), $tags);
    }

    public function test_standard_dashboard_has_no_tag_picker(): void
    {
        $this->actingAs($this->memberOn(VoiceTier::STANDARD));

        $html = $this->getLocalizedRoute('dashboard')
            ->assertOk()
            ->assertSee('data-vs-panel', false)
            ->assertSee('data-vs-library', false)
            ->assertSee('name="voice_key"', false)
            ->assertDontSee('data-vs-pick="dialect"', false)
            ->getContent();

        $this->assertSame([], $this->studioMeta($html)['tagMenus']);
    }

    public function test_tag_texts_exist_in_english_and_arabic(): void
    {
        foreach (['en', 'ar'] as $locale) {
            foreach (['vocal_tags', 'vocal_tags_hint', 'vocal_tags_tips'] as $key) {
                $this->assertNotSame('ui.dashboard.'.$key, __('ui.dashboard.'.$key, [], $locale), $locale.' '.$key);
            }
            foreach (array_keys(GeminiVocalTags::GROUPS) as $group) {
                $this->assertNotSame('ui.dashboard.vocal_tag_groups.'.$group, __('ui.dashboard.vocal_tag_groups.'.$group, [], $locale), $locale.' '.$group);
            }
            $this->assertCount(6, __('ui.dashboard.vocal_tags_tip_items', [], $locale));
            // Members never see other companies' names in the studio.
            $this->assertDoesNotMatchRegularExpression('/\b(?:eleven\w*|openai|gemini|google)\b/i', json_encode(__('ui.dashboard', [], $locale), JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame('وسوم الصوت', __('ui.dashboard.vocal_tags', [], 'ar'));
        $this->assertSame('طريقة الأداء', __('ui.dashboard.vocal_tab_delivery', [], 'ar'));
    }

    public function test_arabic_tag_names_work_like_the_english_ones(): void
    {
        $this->assertCount(48, array_unique(GeminiVocalTags::ARABIC_NAMES));
        $this->assertSame(GeminiVocalTags::all(), array_keys(GeminiVocalTags::ARABIC_NAMES), 'every tag has an Arabic name, in order');

        foreach (GeminiVocalTags::ARABIC_NAMES as $tag => $name) {
            $this->assertSame($tag, GeminiVocalTags::canonical($name), $name);
            $this->assertSame("مرحبا <{$tag}> بك.", GeminiVocalTags::prepare("مرحبا <{$name}> بك.")['text'], $name);
        }

        // Vowel marks and the ة/ه, أ/ا spellings people type either way.
        $this->assertSame('<laugh> <laugh> <sigh> <short pause>', GeminiVocalTags::prepare('<ضحكه> <ضَحْكة> <تنهيده> <وقفه قصيرة>')['text']);
        $this->assertSame('مرحبا [sigh]', GeminiVocalTags::forLegacyModel('مرحبا <تنهيدة>'));
        $this->assertSame('مرحبا بك.', GoogleCloudTtsProvider::stripPerformanceMarkup('مرحبا <تنهيدة> بك.'));
        $this->assertSame('ضحكة', GeminiVocalTags::label('laugh', 'ar'));
        $this->assertSame('laugh', GeminiVocalTags::label('laugh', 'en'));
    }

    public function test_arabic_tags_reach_gemini_as_english_tags(): void
    {
        $this->fakeGeminiKeys();
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $captured = $this->fakeAudio();

        app(GeminiTtsProvider::class)->synthesize('يا سلام! <ضحكة> ما توقعت هذا. <وقفة طويلة> يلا.', 'ar', ['voice_name' => 'Kore']);

        $this->assertSame('يا سلام! <laugh> ما توقعت هذا. <long pause> يلا.', $captured->bodies[0]['contents'][0]['parts'][0]['text']);
    }

    public function test_arabic_dashboard_picker_is_in_arabic(): void
    {
        $this->actingAs($this->memberOn(VoiceTier::HD));
        app()->setLocale('ar');

        $meta = $this->studioMeta($this->getLocalizedRoute('dashboard')->assertOk()->getContent());

        $labels = array_column($meta['tagMenus'], 'l');
        foreach (['ضحكة', 'وقفة قصيرة', 'متحمس', 'تصفيق', 'بصوت طفل'] as $label) {
            $this->assertContains($label, $labels);
        }
        $this->assertNotContains('laugh', $labels);
        $this->assertSame('أصوات حيّة', $meta['i18n']['toolbar_reactions']);
        $this->assertSame('مكتبة الأصوات', $meta['i18n']['title']);
    }

    public function test_delivery_tags_split_the_script_into_directed_parts(): void
    {
        $this->fakeGeminiKeys();
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $captured = $this->fakeAudio();

        app(GeminiTtsProvider::class)->synthesize('مرحبا بكم. [متحمس] فزنا بالمباراة! <ضحكة> [هادئ] والآن لنرتاح.', 'ar', [
            'voice_name' => 'Kore',
            'style_prompt' => 'Read clearly.',
        ]);

        $this->assertCount(3, $captured->bodies, 'one request per part');
        $parts = array_map(fn ($b) => $b['contents'][0]['parts'][0], $captured->bodies);
        $this->assertSame(['مرحبا بكم.', 'فزنا بالمباراة! <laugh>', 'والآن لنرتاح.'], array_column($parts, 'text'));
        $this->assertSame('Read clearly.', $parts[0]['speech_metadata']['style']);
        $this->assertSame("Read clearly.\nDelivery cues from the script: excited and energetic.\n"
            .self::PERFORM.'<laugh> means: real, audible laughter (ha ha ha).', $parts[1]['speech_metadata']['style']);
        $this->assertSame("Read clearly.\nDelivery cues from the script: calm and relaxed.", $parts[2]['speech_metadata']['style']);
    }

    public function test_every_delivery_tag_has_arabic_and_reads_back(): void
    {
        $this->assertCount(58, GeminiVocalTags::deliveries());
        $this->assertSame(GeminiVocalTags::deliveries(), array_keys(GeminiVocalTags::DELIVERY_ARABIC));
        foreach (GeminiVocalTags::DELIVERY_ARABIC as $tag => $name) {
            $this->assertSame($tag, GeminiVocalTags::delivery($name), $name);
            $this->assertSame($tag, GeminiVocalTags::delivery(strtoupper($tag)), $tag);
            $this->assertNull(GeminiVocalTags::canonical($name), 'delivery names never clash with sounds: '.$name);
        }
        $this->assertSame('Hi [excited] there', GeminiVocalTags::forLegacyModel('Hi [متحمس] there'));
        $this->assertSame('Hi there.', GoogleCloudTtsProvider::stripPerformanceMarkup('Hi [متحمس] there.'));
        $this->assertSame('متحمس', GeminiVocalTags::deliveryLabel('excited', 'ar'));
    }

    public function test_cloned_voice_reads_each_delivery_part_with_its_direction(): void
    {
        Storage::fake('public');
        config(['services.gemini_tts.api_key' => 'test-key', 'services.gemini_tts.voice_retry_pause' => false]);
        Http::fake(fn () => Http::response(['output_audio' => ['data' => base64_encode($this->wavBytes())]]));

        app(VoiceCloningService::class)->synthesizeWithClonedVoice('Hello. [sad] I lost it.', 'en-US', 'voice_x', 'Clear.');

        $styles = [];
        Http::assertSent(function ($request) use (&$styles): bool {
            $content = $request->data()['input'][0]['content'][0] ?? [];
            $styles[$content['text'] ?? ''] = $content['annotations'][0]['style'] ?? null;

            return true;
        });
        $this->assertSame('Clear.', $styles['Hello.']);
        $this->assertSame("Clear.\nDelivery cues from the script: sad and subdued.", $styles['I lost it.']);
    }

    public function test_language_and_choices_sit_on_the_text_box_and_speakers_in_the_library(): void
    {
        $this->actingAs($this->memberOn(VoiceTier::HD));

        $html = $this->getLocalizedRoute('dashboard')->assertOk()->getContent();

        $this->assertStringNotContainsString('class="perf-panel"', $html, 'the separate panel is gone');
        $this->assertStringNotContainsString('data-vs-tab=', $html, 'no tabs in the panel any more');
        // On the text box: language, dialect, style and emotion.
        $editor = substr($html, strpos($html, 'class="vs-editor-card"'));
        $editor = substr($editor, 0, strpos($editor, 'id="contentTextarea"'));
        foreach (['data-vs-setbar', 'name="target_language"', 'name="dialect"', 'data-vs-pick="dialect"', 'name="performance_style"', 'data-vs-pick="performance_style"', 'name="emotion"', 'data-vs-pick="emotion"'] as $field) {
            $this->assertStringContainsString($field, $editor, $field.' is on the text box');
        }
        // In the panel: the voice library, then the fine-tuning.
        $panel = substr($html, strpos($html, 'data-vs-panel'));
        $panel = substr($panel, 0, strpos($panel, 'data-vs-meta'));
        foreach (['data-vs-library', 'name="voice_key"', 'data-vs-lib-tab="favorites"', 'data-vs-lib-gender="female"', 'name="speed"', 'name="pitch"', 'id="translateToggle"'] as $field) {
            $this->assertStringContainsString($field, $panel, $field.' is in the panel');
        }
        $this->assertStringNotContainsString('name="target_language"', $panel);
        $this->assertStringNotContainsString('name="performance_style" id="performance_style" required', $html);
        // Sliders: the badge carries the unit and is kept up to date by the studio script;
        // the page's text-field style no longer turns the slider into a thick box.
        $this->assertStringContainsString('id="speedVal" data-unit="×"', $html);
        $this->assertStringNotContainsString("oninput=\"document.getElementById('speedVal')", $html);
        $this->assertStringContainsString('.editor-panel input:not([type="range"])', $html);
        // The editor and the generate button sit together in the main column.
        $main = substr($html, strpos($html, 'class="vs-main"'), 20000);
        $this->assertStringContainsString('id="contentTextarea"', $main);
        $this->assertStringContainsString('id="generateBtn"', $main);
    }

    public function test_arabic_studio_has_the_library_and_every_dialect_has_a_country(): void
    {
        $this->actingAs($this->memberOn(VoiceTier::HD));

        app()->setLocale('ar');
        $html = $this->getLocalizedRoute('dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('مكتبة الأصوات', $html);
        $this->assertStringContainsString('المفضلة', $html);
        $this->assertStringContainsString('data-vs-pick="dialect"', $html);
        $this->assertStringContainsString('data-vs-pick="performance_style"', $html);
        $this->assertStringContainsString('data-vs-pick="emotion"', $html);
        $this->assertStringContainsString('<option value="">افتراضي</option>', $html);
        // The choices show the Arabic names of the styles.
        $this->assertMatchesRegularExpression('/<option value="cinematic">[^<]*[\x{0600}-\x{06FF}]/u', $html);

        // Every dialect belongs to a listed country, and every country has a dialect.
        $countries = [];
        foreach (array_keys(ArabicDialects::ALL) as $code) {
            $country = ArabicDialects::countryOf($code);
            $this->assertNotNull($country, $code);
            $countries[$country] = true;
        }
        $this->assertSame(array_keys(ArabicDialects::COUNTRIES), array_values(array_intersect(array_keys(ArabicDialects::COUNTRIES), array_keys($countries))));
        $this->assertCount(count(ArabicDialects::COUNTRIES), $countries);
        $this->assertSame('EG', ArabicDialects::countryOf('ar-EG-AL'));
        $this->assertSame('TR', ArabicDialects::countryOf('ar-TR-HT'));
        $this->assertNull(ArabicDialects::countryOf('en-US'));
    }

    public function test_demo_page_has_the_same_studio_panel(): void
    {
        $this->actingAs(User::factory()->create());

        $html = $this->getLocalizedRoute('demo.page')->assertOk()->getContent();

        foreach (['data-vs-panel', 'data-vs-editor', 'data-vs-backdrop', 'data-vs-setbar', 'data-vs-library', 'data-vs-hd-only', 'data-vs-pick="dialect"', 'data-vs-pick="emotion"', 'data-vs-meta', 'id="voice_tier"', 'id="voice_key"', 'id="studioPanel"', 'js/voice-studio-icons.js', 'js/voice-studio.js'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString('[calm] How long have you been telling yourself', $html, 'the Premium sample uses the tags');
    }

    public function test_qatar_offers_doha_and_bedouin_accents_with_their_markers(): void
    {
        $this->assertSame('QA', ArabicDialects::countryOf('ar-QA'));
        $this->assertSame('QA', ArabicDialects::countryOf('ar-QA-BD'));
        $this->assertStringContainsString('ريّال', (string) ArabicDialects::markers('ar-QA'));
        $this->assertStringContainsString('ج kept as j', (string) ArabicDialects::markers('ar-QA-BD'));
        $this->assertStringContainsString('Doha', (string) ArabicDialects::cloneInstruction('ar-QA'));
        $this->assertStringContainsString('Bedouin', (string) ArabicDialects::cloneInstruction('ar-QA-BD'));
    }

    public function test_every_arab_dialect_is_offered_with_directions(): void
    {
        $codes = TtsStudioConfig::dialectValues('ar');
        $this->assertCount(122, $codes);
        foreach (['ar-XA-KH', 'ar-XA-ED', 'ar-XA-DR', 'ar-TD', 'ar-SS', 'ar-IR', 'ar-TR-MR', 'ar-TR-HT', 'ar-DZ-JJ', 'ar-EG-WH', 'ar-IQ-OB', 'ar-QA-OL', 'ar-XA-NW', 'ar-XA-CL', 'ar-XA-WH', 'ar-SA-TH', 'ar-AE-BD', 'ar-BH-BA', 'ar-LB-MT', 'ar-SY-CO', 'ar-PS-FL', 'ar-EG-SH', 'ar-SD-WE', 'ar-IQ-NJ', 'ar-YE-TH', 'ar-MA-FS', 'ar-DZ-TL', 'ar-TN-SA', 'ar-LY-FZ', 'ar-LB-TR', 'ar-SY-DZ', 'ar-JO-HR', 'ar-PS-HB', 'ar-IQ-AN', 'ar-YE-TZ', 'ar-MA-MK', 'ar-DZ-CO', 'ar-TN-SF', 'ar-XA', 'ar-SA', 'ar-SA-HJ', 'ar-QA', 'ar-QA-BD', 'ar-BH', 'ar-YE', 'ar-PS', 'ar-DZ', 'ar-LY', 'ar-SD', 'ar-MR', 'ar-EG-SA'] as $code) {
            $this->assertContains($code, $codes);
            $this->assertNotNull(ArabicDialects::prompt($code), $code);
            $this->assertNotNull(ArabicDialects::cloneInstruction($code), $code);
            $this->assertLessThanOrEqual(10, strlen($code), 'fits the dialect columns');
        }
        $row = collect(TtsStudioConfig::dialectCatalogForLanguage('ar'))->firstWhere('value', 'ar-MR');
        $this->assertSame('موريتاني (حساني)', $row['label_ar']);
        $this->assertSame('maghreb', $row['region']);
    }

    public function test_english_accents_cloned_voices_and_panel_groups(): void
    {
        $codes = TtsStudioConfig::dialectValues('en');
        $this->assertCount(count(\App\Support\EnglishAccents::ALL), $codes);
        foreach (['en-US', 'en-US-SO', 'en-GB-SC', 'en-IE', 'en-NG', 'en-AR'] as $code) {
            $this->assertContains($code, $codes);
            $this->assertNotNull(\App\Support\EnglishAccents::countryOf($code), $code);
        }
        $service = app(VoiceCloningService::class);
        $this->assertStringContainsString('Irish English accent', $service->deliveryStyle('en-IE'));
        $this->assertStringContainsString('Sfaxi Tunisian Arabic', $service->deliveryStyle('ar-TN-SF'));
        $this->assertStringContainsString('Emotional tone: nostalgic.', $service->deliveryStyle(null, 'neutral', 'nostalgic'));

        // Style tags repeat the whole-script style: gone from the panel, still read when typed.
        $this->assertArrayNotHasKey('style', GeminiVocalTags::panelDeliveryGroups());
        $this->assertSame('storytelling', GeminiVocalTags::delivery('storytelling'));
        $this->assertCount(58, GeminiVocalTags::deliveries());
    }

    public function test_stock_voice_script_is_rewritten_into_the_chosen_dialect(): void
    {
        $this->fakeGeminiKeys();
        config(['services.gemini_tts.dialect_rewrite' => true, 'services.gemini_tts.api_key' => 'test-key']);
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $bodies = [];
        Http::fake(function ($request) use (&$bodies) {
            $bodies[] = ['url' => $request->url(), 'body' => $request->data()];
            if (str_contains($request->url(), 'gemini-3.5-flash:generateContent')) {
                return Http::response(['candidates' => [['content' => ['parts' => [['text' => 'أنا عاوز أروح دلوك [[0]] إكده']]]]]]);
            }

            return Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/L16;rate=24000', 'data' => base64_encode(str_repeat("\0", 4800))]]]]]]]);
        });

        app(GeminiTtsProvider::class)->synthesize('أنا عايز أروح دلوقتي <ضحكة> كده', 'ar', ['voice_name' => 'Iapetus', 'dialect' => 'ar-EG-SA']);

        $rewrite = collect($bodies)->first(fn ($b) => str_contains($b['url'], 'gemini-3.5-flash'));
        $this->assertNotNull($rewrite, 'the script goes through the dialect rewrite');
        $prompt = $rewrite['body']['contents'][0]['parts'][0]['text'];
        $this->assertStringContainsString('ق as g', $prompt, 'with the Saidi markers');
        $this->assertStringContainsString('گ for a g sound', $prompt);
        $this->assertStringContainsString('[[0]]', $prompt, 'tags locked during the rewrite');

        $speech = collect($bodies)->first(fn ($b) => str_contains($b['url'], 'gemini-3.8-flash-tts'));
        $part = $speech['body']['contents'][0]['parts'][0];
        $this->assertSame('أنا عاوز أروح دلوك. <laugh> إكده', $part['text']);
        $this->assertStringContainsString('Dialect markers to make clearly audible: Saidi', $part['speech_metadata']['style']);
    }

    /**
     * The studio data the page hands its script (components/voice-studio/panel).
     *
     * @return array<string, mixed>
     */
    private function studioMeta(string $html): array
    {
        $this->assertSame(1, preg_match('/<script type="application\/json" data-vs-meta>(.*?)<\/script>/s', $html, $m));

        return json_decode($m[1], true);
    }

    private function memberOn(string $tier): User
    {
        $plan = Plan::factory()->create(['voice_tier' => $tier, 'is_active' => true]);
        $user = User::factory()->create();
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }

    private function fakeGeminiKeys(): void
    {
        Storage::fake('public');
        $key = ['key' => 'test-gemini-key', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1];
        $manager = \Mockery::mock(GeminiKeyManager::class);
        $manager->shouldReceive('getAllKeys')->andReturn([$key]);
        $manager->shouldReceive('getAvailableKey')->andReturn($key);
        $manager->shouldReceive('recordUsage')->andReturnNull();
        $this->app->instance(GeminiKeyManager::class, $manager);
    }

    private function fakeAudio(): \stdClass
    {
        $captured = new \stdClass;
        $captured->bodies = [];
        $wav = $this->wavBytes();
        Http::fake(function ($request) use ($captured, $wav) {
            $captured->bodies[] = $request->data();

            return Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/wav', 'data' => base64_encode($wav)]]]],
                ]],
            ]);
        });

        return $captured;
    }

    private function wavBytes(): string
    {
        $pcm = pack('v*', 0, 1200, 2400, 1200, 0, -1200, -2400, -1200);

        return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('V', 16).pack('v', 1).pack('v', 1)
            .pack('V', 24000).pack('V', 48000).pack('v', 2).pack('v', 16).'data'.pack('V', strlen($pcm)).$pcm;
    }

    public function test_sound_tags_get_their_own_beat_and_a_perform_instruction(): void
    {
        $performed = GeminiVocalTags::forPerformance('انا مش عايز وجع دماغ يا واد انت <scream> ده انت مستفز جدا', 'Egyptian Arabic.');
        $this->assertSame('انا مش عايز وجع دماغ يا واد انت. <scream> ده انت مستفز جدا', $performed['text']);
        $this->assertSame("Egyptian Arabic.\n".self::PERFORM.'<scream> means: a real, loud scream.', $performed['style']);

        // Already a beat, a line break kept, pauses left alone, no tags: unchanged text.
        $this->assertSame('يا سلام! <laugh> تمام', GeminiVocalTags::forPerformance('يا سلام! <laugh> تمام', '')['text']);
        $this->assertSame("A: hi\n<laugh> B: ok", GeminiVocalTags::forPerformance("A: hi\n<laugh> B: ok", '')['text']);
        $this->assertSame(['text' => 'wait <short pause> now', 'style' => 'Calm.'], GeminiVocalTags::forPerformance('wait <short pause> now', 'Calm.'));
        $this->assertSame(['text' => 'no tags', 'style' => 'Calm.'], GeminiVocalTags::forPerformance('no tags', 'Calm.'));

        // Every sound the panel offers has a direction.
        foreach (GeminiVocalTags::GROUPS as $group => $tags) {
            foreach ($tags as $tag) {
                if ($group === 'pauses' || $tag === 'whispers') {
                    continue;
                }
                $this->assertStringContainsString('<'.$tag.'> means:', GeminiVocalTags::forPerformance('x <'.$tag.'>', '')['style'], $tag);
            }
        }
    }
}
