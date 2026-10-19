<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Support\GeminiTtsModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The older prompt model reads the direction and the script as one block of
 * text. A single voice got them run together, and a dialogue speaker with
 * their own dialect got their direction written like a line of the script
 * ("Name: direction"), which that voice could read aloud. Directions are now
 * director's and speaker notes under their own headings, set apart from the
 * transcript. The newer model, which takes the direction apart from the
 * spoken text, is unchanged.
 */
class LegacyDirectorsNotesTest extends TestCase
{
    use RefreshDatabase;

    private const NOTES = "### DIRECTOR'S NOTES\nThese notes direct the performance. Never read them aloud; read aloud only the transcript below, exactly as written.\n";

    /** @var array<int, array<string, mixed>> */
    private array $bodies = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['services.gemini_tts.dialect_rewrite' => false]);
        $key = ['key' => 'test-gemini-key', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1];
        $manager = \Mockery::mock(GeminiKeyManager::class);
        $manager->shouldReceive('getAllKeys')->andReturn([$key]);
        $manager->shouldReceive('getAvailableKey')->andReturn($key);
        $manager->shouldReceive('recordUsage')->andReturnNull();
        $this->app->instance(GeminiKeyManager::class, $manager);

        Http::fake(function ($request) {
            $this->bodies[] = $request->data();

            return Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/l16', 'data' => base64_encode(pack('v*', 0, 1200, -1200, 0))]]]]]]]);
        });
    }

    public function test_a_single_voice_gets_its_direction_as_notes_apart_from_the_transcript(): void
    {
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::CURRENT, 'tts');
        $script = 'مرحبا بكم في حلقة جديدة من البرنامج.';

        app(GeminiTtsProvider::class)->synthesize($script, 'ar', ['voice_name' => 'Kore', 'dialect' => 'ar-EG', 'emotion' => 'warm']);

        $prompt = $this->bodies[0]['contents'][0]['parts'][0]['text'];
        $this->assertStringStartsWith(self::NOTES.'Voice dialect: Egyptian Arabic.', $prompt);
        [$notes, $transcript] = explode("\n\n### TRANSCRIPT\n", $prompt);
        $this->assertSame($script, $transcript, 'the transcript section holds the script alone');
        $this->assertStringContainsString('Emotional tone: ', $notes);
        $this->assertStringNotContainsString($script, $notes);
    }

    public function test_a_long_text_carries_the_notes_on_every_piece(): void
    {
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::CURRENT, 'tts');
        config(['services.gemini_tts.max_chars_per_request' => 200]);
        $script = implode("\n", array_fill(0, 6, 'كان يا ما كان في قديم الزمان رجل طيب يسكن في بغداد.'));

        app(GeminiTtsProvider::class)->synthesize($script, 'ar', ['voice_name' => 'Kore', 'dialect' => 'ar-IQ']);

        $this->assertGreaterThan(1, count($this->bodies));
        $spoken = [];
        foreach ($this->bodies as $body) {
            $prompt = $body['contents'][0]['parts'][0]['text'];
            $this->assertStringStartsWith(self::NOTES.'Voice dialect: Iraqi Arabic.', $prompt);
            $spoken[] = explode("\n\n### TRANSCRIPT\n", $prompt)[1];
        }
        $this->assertSame(preg_split('/\s+/u', $script), preg_split('/\s+/u', implode(' ', $spoken)));
    }

    public function test_a_dialogue_speakers_own_dialect_is_a_speaker_note_not_a_line_of_the_script(): void
    {
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::CURRENT, 'tts');
        $script = "سلطان: هلا والله، نورتي البرنامج!\nنورة: أهلاً بيك، مبسوطة إني هنا.";

        app(GeminiTtsProvider::class)->synthesize($script, 'ar', [
            'speakers' => [
                ['name' => 'سلطان', 'voice_name' => 'Puck', 'dialect' => 'ar-SA'],
                ['name' => 'نورة', 'voice_name' => 'Kore', 'dialect' => 'ar-EG'],
            ],
        ]);

        $body = $this->bodies[0];
        $this->assertArrayHasKey('multiSpeakerVoiceConfig', $body['generationConfig']['speechConfig']);
        $prompt = $body['contents'][0]['parts'][0]['text'];
        $this->assertStringStartsWith(self::NOTES, $prompt);
        [$notes, $transcript] = explode("\n\n### TRANSCRIPT\n", $prompt);
        $this->assertSame($script, $transcript, 'the script, as written, is the only part with speaker lines');

        // Each speaker's own direction is a note for their lines, never "Name: direction".
        $this->assertStringContainsString("\n\n### SPEAKER NOTES\n- For the lines of سلطان only, never read aloud — Voice dialect: Saudi Arabic (Gulf dialect).", $notes);
        $this->assertStringContainsString("\n- For the lines of نورة only, never read aloud — Voice dialect: Egyptian Arabic.", $notes);
        foreach (['سلطان', 'نورة'] as $name) {
            $this->assertDoesNotMatchRegularExpression('/^\s*'.preg_quote($name, '/').'\s*:/mu', $notes, $name.' has no script-like line in the notes');
        }
    }

    public function test_the_newer_model_is_unchanged(): void
    {
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');

        app(GeminiTtsProvider::class)->synthesize('مرحبا بكم.', 'ar', ['voice_name' => 'Kore', 'dialect' => 'ar-EG']);
        app(GeminiTtsProvider::class)->synthesize("سلطان: هلا.\nنورة: أهلاً.", 'ar', [
            'speakers' => [
                ['name' => 'سلطان', 'voice_name' => 'Puck', 'dialect' => 'ar-SA'],
                ['name' => 'نورة', 'voice_name' => 'Kore'],
            ],
        ]);

        $single = $this->bodies[0]['contents'][0]['parts'][0];
        $this->assertSame('مرحبا بكم.', $single['text']);
        $this->assertStringStartsWith('Voice dialect: Egyptian Arabic.', $single['speech_metadata']['style']);
        $dialogue = $this->bodies[1]['contents'][0]['parts'];
        $this->assertSame(['هلا.', 'أهلاً.'], array_column($dialogue, 'text'));
        $this->assertStringNotContainsString('NOTES', json_encode($this->bodies, JSON_UNESCAPED_UNICODE));
    }
}
