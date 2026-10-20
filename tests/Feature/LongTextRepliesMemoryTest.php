<?php

namespace Tests\Feature;

use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Services\VoiceCloningService;
use App\Support\WavPcm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A long text is read in batches of pieces, each reply a few MB of base64
 * audio. The HTTP client's pooled requests hold themselves in reference
 * cycles, so without a collection after each batch every reply stayed in
 * memory until the whole text was read: an hour of them is more than live's
 * 256 MB. Three times the text must take about the same memory. Speech is
 * faked (50 s a piece); the take is saved as WAV to keep the test quick.
 */
class LongTextRepliesMemoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'services.gemini_tts.ffmpeg_path' => 'none',
            'services.gemini_tts.api_key' => 'test-key',
            'services.gemini_tts.retry_pause_ms' => 0,
            'services.gemini_tts.voice_retry_pause' => false,
            'services.gemini_tts.dialect_rewrite' => false,
            'services.gemini_tts.max_chars_per_request' => 200,
            'services.gemini_tts.max_parallel_requests' => 4,
            'services.gemini_tts.model' => 'gemini-3.8-flash-tts',
        ]);
        $key = ['key' => 'test-gemini-key', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1];
        $manager = \Mockery::mock(GeminiKeyManager::class);
        $manager->shouldReceive('getAllKeys')->andReturn([$key]);
        $manager->shouldReceive('getAvailableKey')->andReturn($key);
        $manager->shouldReceive('keysInOrder')->andReturn([$key]);
        $manager->shouldReceive('isOutForToday')->andReturn(false);
        $manager->shouldReceive('recordUsage')->andReturnNull();
        $manager->shouldReceive('markKeyExhausted')->andReturnNull();
        $this->app->instance(GeminiKeyManager::class, $manager);
    }

    public function test_a_long_premium_text_does_not_keep_its_replies(): void
    {
        $pcm = $this->fiftySeconds();
        $this->fake(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/L16;codec=pcm;rate=24000', 'data' => base64_encode($pcm)]]]]]]]);
        unset($pcm);

        $growth = $this->growth(fn (string $text) => app(GeminiTtsProvider::class)->synthesize($text, 'en', ['voice_name' => 'Kore', 'gender' => 'female']));

        $this->assertLessThan(8 * 1048576, $growth, 'three times the text, about the same memory');
    }

    public function test_a_long_cloned_script_does_not_keep_its_replies(): void
    {
        $pcm = $this->fiftySeconds();
        $this->fake(['output_audio' => ['data' => base64_encode(WavPcm::header(strlen($pcm), 24000).$pcm)]]);
        unset($pcm);

        $growth = $this->growth(fn (string $text) => app(VoiceCloningService::class)->synthesizeWithClonedVoice($text, 'en-US', 'voice_long'));

        $this->assertLessThan(8 * 1048576, $growth, 'three times the text, about the same memory');
    }

    public function test_a_full_round_over_three_keys_stays_well_within_the_live_servers_memory(): void
    {
        $keys = [
            ['key' => 'k1', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1],
            ['key' => 'k2', 'name' => 'GEMINI_TTS_API_KEY_2', 'index' => 2],
            ['key' => 'k3', 'name' => 'GEMINI_TTS_API_KEY_3', 'index' => 3],
        ];
        $manager = \Mockery::mock(GeminiKeyManager::class);
        $manager->shouldReceive('getAllKeys')->andReturn($keys);
        $manager->shouldReceive('getAvailableKey')->andReturn($keys[0]);
        $manager->shouldReceive('keysWithRoom')->andReturn($keys);
        $manager->shouldReceive('recordUsage')->andReturnNull();
        $this->app->instance(GeminiKeyManager::class, $manager);
        // Twelve pieces read at once, each a long piece's 70 seconds of audio (900 characters).
        $pcm = str_repeat($this->fiftySeconds(), 2);
        $pcm = substr($pcm, 0, 70 * 48000);
        $this->fake(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/L16;codec=pcm;rate=24000', 'data' => base64_encode($pcm)]]]]]]]);
        unset($pcm);
        $text = trim(str_repeat('The rain had stopped by the time we reached the old bridge over the river and waited there. ', 24));

        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();
        app(GeminiTtsProvider::class)->synthesize($text, 'en', ['voice_name' => 'Kore', 'gender' => 'female']);
        $peak = memory_get_peak_usage() - $before;

        // Live's web server allows 512 MB (a voice made after the reply raises it to 1 GB); the app itself takes about 40.
        $this->assertLessThan(150 * 1048576, $peak, 'a round of twelve long pieces: '.round($peak / 1048576).' MB');
    }

    /**
     * How much more memory 24 pieces take than 8.
     */
    private function growth(callable $speak): int
    {
        $peak = function (int $pieces) use ($speak): int {
            $text = trim(str_repeat('The rain had stopped by the time we reached the old bridge over the river and waited there. ', $pieces * 2));
            gc_collect_cycles();
            $before = memory_get_usage();
            memory_reset_peak_usage();
            $speak($text);

            return memory_get_peak_usage() - $before;
        };

        $short = $peak(8);

        return $peak(24) - $short;
    }

    /**
     * Every request answers with $body. The fake keeps every reply for its
     * assertions, which the real client does not: that is turned off here.
     *
     * @param  array<string, mixed>  $body
     */
    private function fake(array $body): void
    {
        $json = json_encode($body);
        Http::fake(fn () => Http::response($json, 200, ['Content-Type' => 'application/json']));
        (fn () => $this->recording = false)->call(Http::getFacadeRoot());
    }

    private function fiftySeconds(): string
    {
        $second = [];
        for ($i = 0; $i < 24000; $i++) {
            $second[] = (int) round(6000 * sin(2 * M_PI * 220 * $i / 24000));
        }

        return str_repeat(pack('v*', ...$second), 50);
    }
}
