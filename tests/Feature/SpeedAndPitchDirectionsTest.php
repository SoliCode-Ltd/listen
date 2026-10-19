<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Services\VoiceCloningService;
use App\Support\GeminiTtsModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The premium voices have no speed or pitch setting, so each step of the
 * studio's sliders is a written direction, graded so a bigger step reads as a
 * bigger change. 0.9x, 1.1x and 1.2x (and pitch -3 to +3) used to send nothing.
 */
class SpeedAndPitchDirectionsTest extends TestCase
{
    use RefreshDatabase;

    /** What each speed step sends; 1.0x sends nothing. */
    private const SPEED = [
        '0.5' => 'Speak very slowly and deliberately, at about half a normal pace, taking pauses between phrases.',
        '0.6' => 'Speak slowly and deliberately, about 40% slower than normal, with short pauses between phrases.',
        '0.7' => 'Speak at a slow, measured pace, about 30% slower than normal.',
        '0.8' => 'Speak noticeably slower than a normal pace, about 20% slower.',
        '0.9' => 'Speak slightly slower than a normal pace, about 10% slower.',
        '1.0' => null,
        '1.1' => 'Speak slightly faster than a normal pace, about 10% faster.',
        '1.2' => 'Speak noticeably faster than a normal pace, about 20% faster.',
        '1.3' => 'Speak at a faster pace with energetic delivery, about 30% faster than normal.',
        '1.4' => 'Speak at a faster pace with brisk, energetic delivery, about 40% faster than normal.',
        '1.5' => 'Speak fast, with brisk, energetic delivery, at about one and a half times a normal pace.',
        '1.6' => 'Speak very quickly with rapid, energetic pacing, about 60% faster than normal.',
        '1.7' => 'Speak very quickly with rapid, energetic pacing, about 70% faster than normal.',
        '1.8' => 'Speak very quickly with rapid, energetic pacing, about 80% faster than normal.',
        '1.9' => 'Speak very quickly with rapid, energetic pacing, about 90% faster than normal.',
        '2.0' => 'Speak very quickly with rapid, energetic pacing, at about twice a normal pace.',
    ];

    /** What each pitch step sends; 0 sends nothing. */
    private const PITCH = [
        -10 => 'Use a very deep, low-pitched voice, about 10 semitones below its natural pitch.',
        -9 => 'Use a much deeper, lower-pitched voice, about 9 semitones below its natural pitch.',
        -8 => 'Use a much deeper, lower-pitched voice, about 8 semitones below its natural pitch.',
        -7 => 'Use a much deeper, lower-pitched voice, about 7 semitones below its natural pitch.',
        -6 => 'Use a deeper, lower-pitched voice, about 6 semitones below its natural pitch.',
        -5 => 'Use a deeper, lower-pitched voice, about 5 semitones below its natural pitch.',
        -4 => 'Use a deeper, lower-pitched voice, about 4 semitones below its natural pitch.',
        -3 => "Use a noticeably lower pitch than the voice's natural pitch, about 3 semitones lower.",
        -2 => "Use a slightly lower pitch than the voice's natural pitch, about 2 semitones lower.",
        -1 => "Use a slightly lower pitch than the voice's natural pitch, about 1 semitone lower.",
        0 => null,
        1 => "Use a slightly higher pitch than the voice's natural pitch, about 1 semitone higher.",
        2 => "Use a slightly higher pitch than the voice's natural pitch, about 2 semitones higher.",
        3 => "Use a noticeably higher pitch than the voice's natural pitch, about 3 semitones higher.",
        4 => 'Use a higher-pitched, brighter voice, about 4 semitones above its natural pitch.',
        5 => 'Use a higher-pitched, brighter voice, about 5 semitones above its natural pitch.',
        6 => 'Use a higher-pitched, brighter voice, about 6 semitones above its natural pitch.',
        7 => 'Use a much higher-pitched, brighter voice, about 7 semitones above its natural pitch.',
        8 => 'Use a much higher-pitched, brighter voice, about 8 semitones above its natural pitch.',
        9 => 'Use a much higher-pitched, brighter voice, about 9 semitones above its natural pitch.',
        10 => 'Use a very high-pitched, bright voice, about 10 semitones above its natural pitch.',
    ];

    /** @var array<int, array<string, mixed>> */
    private array $bodies = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
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

            return str_contains($request->url(), '/interactions')
                ? Http::response(['output_audio' => ['data' => base64_encode($wav)]])
                : Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/wav', 'data' => base64_encode($wav)]]]]]]]);
        });
    }

    public function test_every_speed_step_of_the_studio_slider_sends_its_own_direction(): void
    {
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $steps = $this->sliderSteps('speed');
        $this->assertSame(array_keys(self::SPEED), array_map(fn (float $step) => number_format($step, 1), $steps), 'the studio offers exactly these speeds');

        foreach ($steps as $step) {
            $style = $this->styleSentFor(['speed' => $step]);
            $expected = self::SPEED[number_format($step, 1)];
            if ($expected === null) {
                $this->assertDoesNotMatchRegularExpression('/\b(pace|slower|faster|slowly|quickly)\b/', $style, '1.0x sends no speed direction');

                continue;
            }
            $this->assertStringContainsString($expected, $style, $step.'x');
            // Only its own step: no other speed's direction rides along.
            foreach (array_filter(self::SPEED) as $other => $wording) {
                if ($other !== number_format($step, 1)) {
                    $this->assertStringNotContainsString($wording, $style, $step.'x and '.$other.'x');
                }
            }
        }
    }

    public function test_every_pitch_step_of_the_studio_slider_sends_its_own_direction(): void
    {
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $steps = $this->sliderSteps('pitch');
        $this->assertSame(array_keys(self::PITCH), array_map('intval', $steps), 'the studio offers exactly these pitches');

        foreach ($steps as $step) {
            $style = $this->styleSentFor(['pitch' => $step]);
            $expected = self::PITCH[(int) $step];
            if ($expected === null) {
                $this->assertStringNotContainsString('pitch', $style, 'pitch 0 sends no pitch direction');

                continue;
            }
            $this->assertStringContainsString($expected, $style, 'pitch '.$step);
        }
    }

    public function test_a_bigger_step_reads_as_a_bigger_change(): void
    {
        $this->assertCount(15, array_unique(array_filter(self::SPEED)), 'every speed step reads differently');
        $this->assertCount(20, array_unique(array_filter(self::PITCH)), 'every pitch step reads differently');

        // 10% slower is less than 20% slower, and so on, on both sides of 1.0x and of 0.
        $percent = fn (?string $wording): int => match (true) {
            $wording === null => 0,
            str_contains($wording, 'half a normal pace') || str_contains($wording, 'one and a half') => 50,
            str_contains($wording, 'twice') => 100,
            default => (int) preg_replace('/^.*?(\d+)%.*$/', '$1', $wording),
        };
        $slower = array_map($percent, array_slice(self::SPEED, 0, 6, true));
        $faster = array_map($percent, array_slice(self::SPEED, 5, null, true));
        $this->assertSame([50, 40, 30, 20, 10, 0], array_values($slower));
        $this->assertSame([0, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100], array_values($faster));

        $semitones = fn (?string $wording): int => $wording === null ? 0 : (int) preg_replace('/^.*?(\d+) semitones?.*$/', '$1', $wording);
        foreach (self::PITCH as $step => $wording) {
            $this->assertSame(abs($step), $semitones($wording), 'pitch '.$step);
        }
    }

    public function test_values_between_steps_are_read_at_the_nearest_step(): void
    {
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');

        // The developer API takes any value from 0.5 to 2 and from -10 to 10.
        $this->assertStringContainsString(self::SPEED['1.2'], $this->styleSentFor(['speed' => 1.15]));
        $this->assertStringContainsString(self::SPEED['0.9'], $this->styleSentFor(['speed' => 0.85]));
        $this->assertStringContainsString(self::PITCH[-2], $this->styleSentFor(['pitch' => -2.4]));
        $this->assertStringNotContainsString('pace', $this->styleSentFor(['speed' => 1.02]));
    }

    public function test_the_older_prompt_model_gets_the_same_directions(): void
    {
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::CURRENT, 'tts');

        app(GeminiTtsProvider::class)->synthesize('A short line.', 'en', ['voice_name' => 'Kore', 'speed' => 1.1, 'pitch' => -1]);

        $prompt = end($this->bodies)['contents'][0]['parts'][0]['text'];
        $this->assertStringContainsString(self::SPEED['1.1'], $prompt);
        $this->assertStringContainsString(self::PITCH[-1], $prompt);
    }

    public function test_cloned_voices_get_the_same_directions(): void
    {
        config(['services.gemini_tts.api_key' => 'test-key', 'services.gemini_tts.voice_retry_pause' => false]);
        $service = app(VoiceCloningService::class);

        foreach (['0.9' => 2, '1.1' => -3, '1.2' => 7] as $speed => $pitch) {
            $style = $service->deliveryStyle('ar-EG', 'neutral', 'neutral', null, (float) $speed, (float) $pitch);
            $this->assertStringContainsString(self::SPEED[$speed], $style, $speed.'x');
            $this->assertStringContainsString(self::PITCH[$pitch], $style, 'pitch '.$pitch);
        }
        $this->assertSame('', $service->deliveryStyle(null, 'neutral', 'neutral', null, 1.0, 0.0), '1.0x and pitch 0 send nothing');

        $this->bodies = [];
        $service->synthesizeWithClonedVoice('Hello there.', 'en-US', 'voice_x', $service->deliveryStyle(null, 'neutral', 'neutral', null, 0.9, 1.0));
        $sent = $this->bodies[0]['input'][0]['content'][0]['annotations'][0]['style'] ?? '';
        $this->assertSame(self::SPEED['0.9'].' '.self::PITCH[1], $sent);
    }

    /**
     * The direction a stock premium voice was sent for one setting.
     *
     * @param  array<string, float>  $setting
     */
    private function styleSentFor(array $setting): string
    {
        $this->bodies = [];
        app(GeminiTtsProvider::class)->synthesize('A short line to read.', 'en', ['voice_name' => 'Kore', 'performance_style' => 'neutral', 'emotion' => 'neutral'] + $setting);
        $this->assertCount(1, $this->bodies);

        return (string) ($this->bodies[0]['contents'][0]['parts'][0]['speech_metadata']['style'] ?? '');
    }

    /**
     * Every value the studio's slider (components/voice-studio/speed-pitch) can take.
     *
     * @return array<int, float>
     */
    private function sliderSteps(string $name): array
    {
        $html = Blade::render('<x-voice-studio.speed-pitch />');
        $this->assertSame(1, preg_match('/<input type="range" name="'.$name.'"[^>]* min="([-\d.]+)" max="([-\d.]+)" step="([\d.]+)"/', $html, $m), $name.' slider');
        [$min, $max, $step] = [(float) $m[1], (float) $m[2], (float) $m[3]];

        return array_map(fn (int $i): float => round($min + $i * $step, 1), range(0, (int) round(($max - $min) / $step)));
    }
}
