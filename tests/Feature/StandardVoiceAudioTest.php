<?php

namespace Tests\Feature;

use App\Services\Tts\GoogleCloudTtsProvider;
use App\Support\AudioDuration;
use App\Support\AudioFormats;
use App\Support\ShareableAudio;
use App\Support\WavPcm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Standard voices are asked for uncompressed audio (LINEAR16) and compressed
 * once here into the MP3 members get, instead of taking the engine's own MP3
 * (32 kbps at 24 kHz) and compressing that again for downloads. A server
 * that cannot compress asks for MP3 as before. Speech is faked.
 */
class StandardVoiceAudioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'services.google_tts.api_key' => 'test-google-key',
            'services.google_tts.endpoint' => 'https://texttospeech.googleapis.com/v1/text:synthesize',
        ]);
    }

    public function test_uncompressed_audio_is_what_they_ask_for_unless_told_otherwise(): void
    {
        $this->assertSame('LINEAR16', config('services.google_tts.audio_encoding'));
    }

    public function test_a_standard_voice_is_compressed_once_here_into_the_same_mp3(): void
    {
        $this->requireFfmpeg();
        $sent = $this->fakeEngine($this->linear16(0.3, 2.0, 0.3));

        $result = $this->speak('Hello there, and welcome.');

        $this->assertSame(['LINEAR16'], array_unique(array_column($sent->audioConfig, 'audioEncoding')));
        $this->assertStringEndsWith('.mp3', $result['audio_path'], 'named and stored as before');
        $mp3 = (string) Storage::disk('public')->get($result['audio_path']);
        $this->assertTrue(ShareableAudio::isMp3($mp3));
        // Encoded here, at the site's MP3 rate, not the engine's 32 kbps.
        $this->assertSame('160000', $this->probe($mp3, 'stream=bit_rate'));
        // 0.2 s before the voice and its own 0.3 s after it are kept.
        $this->assertEqualsWithDelta(0.2 + 2.0 + 0.3, AudioDuration::ofStoredFile($result['audio_path']), 0.08);
    }

    public function test_a_long_standard_text_is_joined_end_to_end_from_its_uncompressed_pieces(): void
    {
        $this->requireFfmpeg();
        $sent = $this->fakeEngine($this->linear16(0.1, 3.0, 0.1));
        $text = trim(str_repeat('هذا نص عربي طويل لاختبار القطع والجمع في الصوت العادي. ', 60));
        $this->assertGreaterThan(GoogleCloudTtsProvider::MAX_INPUT_BYTES * 2, strlen($text));

        $result = $this->speak($text, 'ar');

        $pieces = count($sent->audioConfig);
        $this->assertGreaterThan(2, $pieces);
        $this->assertSame(['LINEAR16'], array_unique(array_column($sent->audioConfig, 'audioEncoding')));
        // Each piece as the engine read it, nothing added between them (as before).
        $this->assertEqualsWithDelta($pieces * 3.2, AudioDuration::ofStoredFile($result['audio_path']), 0.1);
    }

    public function test_without_ffmpeg_they_ask_for_mp3_and_store_it_as_it_comes(): void
    {
        config(['services.gemini_tts.ffmpeg_path' => 'none']);
        $sent = $this->fakeEngine('ID3engine-mp3');

        $result = $this->speak('Hello there.');

        $this->assertSame(['MP3'], array_unique(array_column($sent->audioConfig, 'audioEncoding')));
        $this->assertStringEndsWith('.mp3', $result['audio_path']);
        $this->assertSame('ID3engine-mp3', Storage::disk('public')->get($result['audio_path']));
    }

    public function test_mp3_can_still_be_chosen_in_the_config(): void
    {
        config(['services.google_tts.audio_encoding' => 'MP3']);
        $sent = $this->fakeEngine('ID3engine-mp3');

        $result = $this->speak('Hello there.');

        $this->assertSame(['MP3'], array_unique(array_column($sent->audioConfig, 'audioEncoding')));
        $this->assertSame('ID3engine-mp3', Storage::disk('public')->get($result['audio_path']));
    }

    public function test_a_long_text_does_not_hold_more_memory_the_longer_it_is(): void
    {
        // 50 s pieces: 8 of them, then 24 (a third of an hour): only a batch is ever held.
        $this->fakeEngine($this->linear16(0.1, 50.0, 0.1));
        // The fake keeps every reply for assertions; the real client keeps none.
        (fn () => $this->recording = false)->call(Http::getFacadeRoot());
        $sentence = 'هذا نص عربي طويل لاختبار الذاكرة في الصوت العادي. ';
        $peak = function (int $pieces) use ($sentence): int {
            // About one piece of text each (the engine takes 1,500 bytes a request).
            $text = trim(str_repeat($sentence, (int) floor($pieces * GoogleCloudTtsProvider::MAX_INPUT_BYTES / strlen($sentence) * 0.97)));
            gc_collect_cycles();
            $before = memory_get_usage();
            memory_reset_peak_usage();
            $this->speak($text, 'ar');

            return memory_get_peak_usage() - $before;
        };

        $short = $peak(8);
        $long = $peak(24);

        $this->assertLessThan(8 * 1048576, $long - $short, 'three times the text, about the same memory');
    }

    /**
     * @return array<string, mixed>
     */
    private function speak(string $text, string $language = 'en'): array
    {
        return app(GoogleCloudTtsProvider::class)->synthesize($text, $language, ['tier' => 'standard', 'gender' => 'female']);
    }

    /**
     * Every request answers with $audio; what was asked for is recorded.
     */
    private function fakeEngine(string $audio): \stdClass
    {
        $sent = new \stdClass;
        $sent->audioConfig = [];
        $body = ['audioContent' => base64_encode($audio)];
        Http::fake(function ($request) use ($sent, $body) {
            $sent->audioConfig[] = $request->data()['audioConfig'];

            return Http::response($body);
        });

        return $sent;
    }

    /**
     * What the engine sends for LINEAR16: a WAV, 24 kHz mono, here quiet, a 220 Hz voice, quiet.
     */
    private function linear16(float $before, float $voice, float $after): string
    {
        $second = [];
        for ($i = 0; $i < 24000; $i++) {
            $second[] = (int) round(6000 * sin(2 * M_PI * 220 * $i / 24000));
        }
        $second = pack('v*', ...$second);
        $pcm = str_repeat("\0", 2 * (int) round($before * 24000))
            .substr(str_repeat($second, (int) ceil($voice)), 0, 2 * (int) round($voice * 24000))
            .str_repeat("\0", 2 * (int) round($after * 24000));

        return WavPcm::header(strlen($pcm), 24000).$pcm;
    }

    private function probe(string $audio, string $entries): string
    {
        $file = sys_get_temp_dir().'/listen-std-'.bin2hex(random_bytes(4));
        file_put_contents($file, $audio);
        $probe = new Process([dirname((string) AudioFormats::ffmpegPath()).'/ffprobe', '-v', 'error', '-show_entries', $entries, '-of', 'csv=p=0', $file]);
        $out = trim($probe->mustRun()->getOutput());
        unlink($file);

        return $out;
    }

    private function requireFfmpeg(): void
    {
        if (AudioFormats::ffmpegPath() === null) {
            $this->markTestSkipped('ffmpeg is not installed here.');
        }
    }
}
