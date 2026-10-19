<?php

namespace Tests\Unit;

use App\Support\AudioFormats;
use App\Support\ShareableAudio;
use Tests\TestCase;

class ShareableAudioTest extends TestCase
{
    public function test_wav_speech_is_packaged_as_mp3(): void
    {
        $packaged = ShareableAudio::package($this->silentWav());

        $this->assertSame('mp3', $packaged['extension']);
        $this->assertTrue(ShareableAudio::isMp3($packaged['bytes']));
        $this->assertFalse(ShareableAudio::isWav($packaged['bytes']));
    }

    public function test_wav_speech_is_packaged_as_mp3_when_web_php_has_no_path(): void
    {
        if (AudioFormats::ffmpegPath() === null) {
            $this->markTestSkipped('ffmpeg is not installed here.');
        }
        $path = getenv('PATH');
        putenv('PATH=');

        try {
            $packaged = ShareableAudio::package($this->silentWav());
        } finally {
            putenv('PATH='.$path);
        }

        $this->assertSame('mp3', $packaged['extension']);
        $this->assertTrue(ShareableAudio::isMp3($packaged['bytes']));
    }

    public function test_an_mp3_is_left_unchanged(): void
    {
        $mp3 = "ID3\x03\x00\x00\x00\x00\x00\x00";

        $packaged = ShareableAudio::package($mp3);

        $this->assertSame('mp3', $packaged['extension']);
        $this->assertSame($mp3, $packaged['bytes']);
    }

    private function silentWav(): string
    {
        $rate = 24000;
        $data = str_repeat("\x00\x00", (int) ($rate * 0.2));

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '
            .pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
            .'data'.pack('V', strlen($data)).$data;
    }
}
