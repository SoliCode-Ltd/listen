<?php

namespace Tests\Feature;

use App\Services\Tts\GeminiTtsProvider;
use App\Support\AudioDuration;
use App\Support\AudioFormats;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Live's web PHP may use 256 MB, the app included, and the longest studio
 * text is about an hour of audio: 170 MB of 24 kHz speech for each copy held.
 * The pieces are written to a file as they come in, packaged from there by
 * ffmpeg and streamed to the disk, so the recording is never held whole.
 * Speech is synthetic: no engine is called.
 */
class LongTakeMemoryTest extends TestCase
{
    private const PIECES = 60;

    private const PIECE_SECONDS = 60;

    private const PCM_BYTES = self::PIECES * self::PIECE_SECONDS * 24000 * 2;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_an_hour_of_audio_is_joined_and_saved_in_a_fraction_of_its_size(): void
    {
        [$path, $peak] = $this->saveAnHour();

        // Well under one copy: only a batch of pieces and small buffers are ever held.
        $this->assertLessThan((int) (0.25 * self::PCM_BYTES), $peak, 'peak '.round($peak / 1048576).' MB above the start');
        $this->assertSame(AudioFormats::serverCanConvert() ? 'mp3' : 'wav', pathinfo($path, PATHINFO_EXTENSION));
        $this->assertEqualsWithDelta(self::PIECES * self::PIECE_SECONDS + (self::PIECES - 1) * 0.3, AudioDuration::ofStoredFile($path), 2.0);
    }

    public function test_without_ffmpeg_an_hour_is_saved_as_wav_in_a_fraction_of_its_size(): void
    {
        config(['services.gemini_tts.ffmpeg_path' => 'none']);

        [$path, $peak] = $this->saveAnHour();

        $this->assertLessThan((int) (0.25 * self::PCM_BYTES), $peak, 'peak '.round($peak / 1048576).' MB above the start');
        $this->assertStringEndsWith('.wav', $path);
        // Measured from its header, not read whole.
        $this->assertEqualsWithDelta(self::PIECES * self::PIECE_SECONDS + (self::PIECES - 1) * 0.3, AudioDuration::ofStoredFile($path), 0.01);
    }

    /**
     * Joins and saves an hour, the pieces made one at a time as the batches
     * would bring them in.
     *
     * @return array{0: string, 1: int} the saved file and the peak memory above the start
     */
    private function saveAnHour(): array
    {
        $provider = app(GeminiTtsProvider::class);
        $save = \Closure::bind(fn (iterable $parts): string => $this->saveJoinedAudio($this->joinAudioParts($parts)), $provider, GeminiTtsProvider::class);
        $second = $this->speechLikeSecond();
        $pieces = (function () use ($second): \Generator {
            for ($i = 0; $i < self::PIECES; $i++) {
                yield $i => ['bytes' => str_repeat($second, self::PIECE_SECONDS), 'mime_type' => 'audio/L16;codec=pcm;rate=24000'];
            }
        })();

        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();
        $path = $save($pieces);
        $peak = memory_get_peak_usage() - $before;

        Storage::disk('public')->assertExists($path);

        return [$path, $peak];
    }

    /**
     * One second of 24 kHz 16-bit PCM that behaves like speech for the level
     * and the edges: four 160 ms syllables of a 180 Hz tone with a few overtones.
     */
    private function speechLikeSecond(): string
    {
        $samples = [];
        for ($i = 0; $i < 24000; $i++) {
            $t = $i / 24000;
            $inSyllable = fmod($t, 0.25) < 0.16;
            $samples[] = $inSyllable
                ? (int) round(6000 * (sin(2 * M_PI * 180 * $t) + 0.5 * sin(2 * M_PI * 360 * $t) + 0.25 * sin(2 * M_PI * 1440 * $t)))
                : 0;
        }

        return pack('v*', ...$samples);
    }
}
