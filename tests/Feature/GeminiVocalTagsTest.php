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
use App\Support\GeminiTtsModel;
use App\Support\GeminiVocalTags;
use App\Support\VoiceTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GeminiVocalTagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_supported_tag_is_kept_as_written(): void
    {
        foreach (GeminiVocalTags::all() as $tag) {
            $prepared = GeminiVocalTags::prepare("Well. <{$tag}> Go on.");
            $this->assertSame("Well. <{$tag}> Go on.", $prepared['text'], $tag);
            $this->assertSame([], $prepared['cues'], $tag);
        }
        $this->assertCount(36, GeminiVocalTags::all());
        $this->assertSame(GeminiVocalTags::all(), array_values(array_unique(GeminiVocalTags::all())));
    }

    public function test_elevenlabs_style_sounds_become_gemini_tags(): void
    {
        $prepared = GeminiVocalTags::prepare('[laughs] Really? [Sighs] Fine. (clears throat) Next. [whispering] Quiet. [pause] Done <Laughing>');

        $this->assertSame('<laugh> Really? <sigh> Fine. <throat-clearing> Next. <whispers> Quiet. <short pause> Done <laugh>', $prepared['text']);
        $this->assertSame([], $prepared['cues']);
    }

    public function test_moods_move_to_the_style_and_are_not_spoken(): void
    {
        $prepared = GeminiVocalTags::prepare("[excited] We won! [sarcastic, dry] Great.\n<happy> يا سلام! <laugh>");

        $this->assertSame("We won! Great.\nيا سلام! <laugh>", $prepared['text']);
        $this->assertSame(['excited', 'sarcastic, dry', 'happy'], $prepared['cues']);
        $this->assertSame("Read warmly.\nDelivery cues from the script: excited, sarcastic, dry, happy.", GeminiVocalTags::withCues('Read warmly.', $prepared['cues']));
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
        $this->assertStringContainsString('Delivery cues from the script: excited.', $part['speech_metadata']['style']);
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
        $this->assertSame('Hello <gasp> there.', $part['text']);
        $this->assertSame("Speak like a radio host.\nDelivery cues from the script: nervous.", $part['speech_metadata']['style']);
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
                && ($content['text'] ?? null) === 'Hi <laugh> there.'
                && ($content['annotations'][0]['style'] ?? null) === "Calm and clear.\nDelivery cues from the script: excited.";
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

        $this->getLocalizedRoute('dashboard')
            ->assertOk()
            ->assertSee('id="vocalTags"', false)
            ->assertSee('data-vocal-tag="laugh"', false)
            ->assertSee('data-vocal-tag="short pause"', false)
            ->assertDontSee('&lt;laugh&gt;</button>', false)
            ->assertSee(__('ui.dashboard.vocal_tags'))
            ->assertSee(__('ui.dashboard.vocal_tags_tip_items')[0]);
    }

    public function test_standard_dashboard_has_no_tag_picker(): void
    {
        $this->actingAs($this->memberOn(VoiceTier::STANDARD));

        $this->getLocalizedRoute('dashboard')
            ->assertOk()
            ->assertDontSee('id="vocalTags"', false);
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
            $this->assertDoesNotMatchRegularExpression('/eleven|gemini|google/i', json_encode(__('ui.dashboard', [], $locale), JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame('المؤثرات الصوتية', __('ui.dashboard.vocal_tags', [], 'ar'));
    }

    public function test_arabic_tag_names_work_like_the_english_ones(): void
    {
        $this->assertCount(36, array_unique(GeminiVocalTags::ARABIC_NAMES));
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

        $html = $this->withSession(['locale' => 'ar'])->withHeader('Accept-Language', 'ar')
            ->get('/ar'.$this->localizedRoute('dashboard'))->getContent();
        if (! str_contains($html, 'vocalTags')) {
            app()->setLocale('ar');
            $html = $this->getLocalizedRoute('dashboard')->assertOk()->getContent();
        }

        $this->assertStringContainsString('data-vocal-tag="ضحكة"', $html);
        $this->assertStringContainsString('data-vocal-tag="وقفة قصيرة"', $html);
        $this->assertStringNotContainsString('data-vocal-tag="laugh"', $html);
        $this->assertStringContainsString('المؤثرات الصوتية', $html);
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
}
