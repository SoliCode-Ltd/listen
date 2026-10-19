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
 * In a dialogue a mood or delivery tag ([angry]) used to colour every line of
 * the dialogue. The structured speech format gives each line (part) its own
 * speaker and its own direction, so a tag now directs its own turn only.
 */
class DialogueTagsTest extends TestCase
{
    use RefreshDatabase;

    private const ANGRY = 'Delivery cues from the script: angry and intense.';

    private const CUES = 'Delivery cues from the script:';

    /** @var array<int, array<string, mixed>> */
    private array $bodies = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['services.gemini_tts.dialect_rewrite' => false]);
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $key = ['key' => 'test-gemini-key', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1];
        $manager = \Mockery::mock(GeminiKeyManager::class);
        $manager->shouldReceive('getAllKeys')->andReturn([$key]);
        $manager->shouldReceive('getAvailableKey')->andReturn($key);
        $manager->shouldReceive('recordUsage')->andReturnNull();
        $this->app->instance(GeminiKeyManager::class, $manager);

        $pcm = pack('v*', 0, 1200, -1200, 0);
        $wav = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
        Http::fake(function ($request) use ($wav) {
            $this->bodies[] = $request->data();

            return Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/wav', 'data' => base64_encode($wav)]]]]]]]);
        });
    }

    public function test_a_tag_on_one_line_stays_on_that_line(): void
    {
        $parts = $this->dialogue("Sara: [angry] You lied to me!\nOmar: I did not.\nSara: Fine, let us talk.");

        $this->assertSame(['You lied to me!', 'I did not.', 'Fine, let us talk.'], array_column($parts, 'text'));
        $this->assertSame(['Sara', 'Omar', 'Sara'], $this->speakers($parts));
        $this->assertStringContainsString(self::ANGRY, $parts[0]['speech_metadata']['style']);
        $this->assertStringNotContainsString(self::CUES, $parts[1]['speech_metadata']['style'], 'the other speaker is not angry');
        $this->assertStringNotContainsString(self::CUES, $parts[2]['speech_metadata']['style'], 'nor is her next turn');
    }

    public function test_a_tag_inside_a_line_directs_the_words_after_it(): void
    {
        $parts = $this->dialogue("Sara: I am fine. [angry] But you lied!\nOmar: Calm down.");

        $this->assertSame(['I am fine.', 'But you lied!', 'Calm down.'], array_column($parts, 'text'));
        $this->assertSame(['Sara', 'Sara', 'Omar'], $this->speakers($parts));
        $this->assertStringNotContainsString(self::CUES, $parts[0]['speech_metadata']['style']);
        $this->assertStringContainsString(self::ANGRY, $parts[1]['speech_metadata']['style']);
        $this->assertStringNotContainsString(self::CUES, $parts[2]['speech_metadata']['style']);
    }

    public function test_a_tag_lasts_to_the_end_of_its_turn_only(): void
    {
        // A line without a name goes on with the same speaker's turn.
        $parts = $this->dialogue("Sara: [sad] I waited for you.\nAll night long.\nOmar: [excited] I got the job!\nSara: Really?");

        $this->assertSame(['Sara', 'Sara', 'Omar', 'Sara'], $this->speakers($parts));
        $this->assertStringContainsString('Delivery cues from the script: sad and subdued.', $parts[0]['speech_metadata']['style']);
        $this->assertStringContainsString('Delivery cues from the script: sad and subdued.', $parts[1]['speech_metadata']['style']);
        $this->assertStringContainsString('Delivery cues from the script: excited and energetic.', $parts[2]['speech_metadata']['style']);
        $this->assertStringNotContainsString('sad', $parts[2]['speech_metadata']['style']);
        $this->assertStringNotContainsString(self::CUES, $parts[3]['speech_metadata']['style']);
    }

    public function test_a_tag_written_after_the_words_directs_its_own_turn(): void
    {
        $parts = $this->dialogue("Sara: Fine. [angry]\nOmar: Okay then.");

        $this->assertSame(['Fine.', 'Okay then.'], array_column($parts, 'text'));
        $this->assertStringContainsString(self::ANGRY, $parts[0]['speech_metadata']['style'], 'not dropped: its own turn');
        $this->assertStringNotContainsString(self::CUES, $parts[1]['speech_metadata']['style']);
    }

    public function test_a_line_of_a_mood_alone_directs_the_next_turn(): void
    {
        $parts = $this->dialogue("Sara: We need to talk.\nOmar: [scared]\nOmar: What happened?\nSara: Nothing bad.\n[nervous]\nOmar: Tell me.");

        $this->assertSame(['We need to talk.', 'What happened?', 'Nothing bad.', 'Tell me.'], array_column($parts, 'text'));
        $this->assertStringNotContainsString(self::CUES, $parts[0]['speech_metadata']['style']);
        $this->assertStringContainsString('Delivery cues from the script: scared and tense.', $parts[1]['speech_metadata']['style']);
        // A mood on a line of its own after her words is part of her turn, with nothing after it there.
        $this->assertStringContainsString('Delivery cues from the script: nervous and hesitant.', $parts[2]['speech_metadata']['style']);
        $this->assertStringNotContainsString(self::CUES, $parts[3]['speech_metadata']['style']);
    }

    public function test_sounds_stay_on_their_line_and_a_line_of_one_sound_is_still_spoken(): void
    {
        $parts = $this->dialogue("Sara: Guess what? [excited] I won!\nOmar: <laugh>\nSara: [laughs] Yes, really.");

        $this->assertSame(['Guess what?', 'I won!', '<laugh>', '<laugh> Yes, really.'], array_column($parts, 'text'));
        $this->assertSame(['Sara', 'Sara', 'Omar', 'Sara'], $this->speakers($parts));
        $this->assertStringContainsString('Delivery cues from the script: excited and energetic.', $parts[1]['speech_metadata']['style']);
        $this->assertStringNotContainsString(self::CUES, $parts[2]['speech_metadata']['style']);
        // The sounds are still performed, not read.
        $this->assertStringContainsString('<laugh> means:', $parts[2]['speech_metadata']['style']);
    }

    public function test_a_speaker_with_their_own_dialect_keeps_it_with_the_tag_and_arabic_tags_work_too(): void
    {
        $this->bodies = [];
        app(GeminiTtsProvider::class)->synthesize("سلطان: [غاضب] وش هالكلام؟\nنورة: اهدى شوية.", 'ar', [
            'speakers' => [
                ['name' => 'سلطان', 'voice_name' => 'Puck', 'dialect' => 'ar-SA'],
                ['name' => 'نورة', 'voice_name' => 'Kore', 'dialect' => 'ar-EG'],
            ],
        ]);

        $parts = $this->bodies[0]['contents'][0]['parts'];
        $this->assertSame(['وش هالكلام؟', 'اهدى شوية.'], array_column($parts, 'text'));
        $this->assertStringContainsString('Saudi Arabic', $parts[0]['speech_metadata']['style']);
        $this->assertStringContainsString(self::ANGRY, $parts[0]['speech_metadata']['style']);
        $this->assertStringContainsString('Cairene Egyptian', $parts[1]['speech_metadata']['style']);
        $this->assertStringNotContainsString(self::CUES, $parts[1]['speech_metadata']['style']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dialogue(string $script): array
    {
        $this->bodies = [];
        app(GeminiTtsProvider::class)->synthesize($script, 'en', [
            'speakers' => [
                ['name' => 'Sara', 'voice_name' => 'Kore'],
                ['name' => 'Omar', 'voice_name' => 'Puck'],
            ],
        ]);
        $this->assertCount(1, $this->bodies, 'one request for the dialogue');

        return $this->bodies[0]['contents'][0]['parts'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $parts
     * @return array<int, string>
     */
    private function speakers(array $parts): array
    {
        return array_map(fn (array $part): string => $part['speech_metadata']['speaker'], $parts);
    }
}
