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

class GeminiTtsModelSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $key = [
            'key' => 'test-gemini-key',
            'name' => 'GEMINI_TTS_API_KEY',
            'index' => 1,
        ];
        $manager = \Mockery::mock(GeminiKeyManager::class);
        $manager->shouldReceive('getAllKeys')->andReturn([$key]);
        $manager->shouldReceive('getAvailableKey')->andReturn($key);
        $manager->shouldReceive('recordUsage')->andReturnNull();
        $this->app->instance(GeminiKeyManager::class, $manager);
    }

    public function test_default_model_keeps_the_current_gemini_request(): void
    {
        $captured = $this->fakeAudio('audio/l16', $this->pcmBytes());

        $result = app(GeminiTtsProvider::class)->synthesize('مرحبا', 'ar', [
            'voice_name' => 'Kore',
            'performance_style' => 'cinematic',
            'emotion' => 'warm',
        ]);

        $this->assertSame(GeminiTtsModel::CURRENT, $result['model']);
        $this->assertStringContainsString('/models/'.GeminiTtsModel::CURRENT.':generateContent', $captured->url);
        $this->assertSame('Kore', $captured->body['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName']);
        $spoken = $captured->body['contents'][0]['parts'][0]['text'];
        $this->assertStringContainsString('مرحبا', $spoken);
        $this->assertStringContainsString('Emotional tone', $spoken);
        $saved = Storage::disk('public')->get($result['audio_path']);
        $this->assertStringEndsWith('.mp3', $result['audio_path']);
        $this->assertTrue(\App\Support\ShareableAudio::isMp3($saved));
    }

    public function test_newest_model_speaks_only_the_transcript_and_saves_mp3(): void
    {
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $wav = $this->wavBytes();
        $captured = $this->fakeAudio('audio/wav', $wav);

        $result = app(GeminiTtsProvider::class)->synthesize('مرحبا بك', 'ar', [
            'voice_name' => 'Kore',
            'performance_style' => 'cinematic',
            'emotion' => 'warm',
            'speed' => 0.85,
        ]);

        $this->assertSame(GeminiTtsModel::FLASH, $result['model']);
        $this->assertStringContainsString('/models/'.GeminiTtsModel::FLASH.':generateContent', $captured->url);
        $part = $captured->body['contents'][0]['parts'][0];
        $this->assertSame('مرحبا بك', $part['text']);
        $this->assertArrayNotHasKey('prebuiltVoiceConfig', $captured->body['generationConfig']['speechConfig']['voiceConfig']);
        $this->assertSame('Kore', $captured->body['generationConfig']['speechConfig']['voiceConfig']['voice']);
        $this->assertStringContainsString('slow', strtolower($part['speech_metadata']['style']));
        $this->assertStringNotContainsString('speech_metadata', $part['text']);
        $saved = Storage::disk('public')->get($result['audio_path']);
        $this->assertStringEndsWith('.mp3', $result['audio_path']);
        $this->assertTrue(\App\Support\ShareableAudio::isMp3($saved));
        $this->assertNotSame($wav, $saved);
    }

    public function test_lite_model_and_unknown_value_are_handled_safely(): void
    {
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH_LITE, 'tts');
        $captured = $this->fakeAudio('audio/wav', $this->wavBytes());

        $lite = app(GeminiTtsProvider::class)->synthesize('Hello there', 'en', [
            'voice_name' => 'Kore',
        ]);
        $this->assertSame(GeminiTtsModel::FLASH_LITE, $lite['model']);
        $this->assertStringContainsString(GeminiTtsModel::FLASH_LITE, $captured->url);

        SiteSetting::set(GeminiTtsModel::SETTING_KEY, 'gemini-not-a-model', 'tts');
        $fallback = $this->fakeAudio('audio/l16', $this->pcmBytes());
        $result = app(GeminiTtsProvider::class)->synthesize('Hello there', 'en', [
            'voice_name' => 'Kore',
        ]);

        $this->assertSame(GeminiTtsModel::CURRENT, $result['model']);
        $this->assertStringContainsString(GeminiTtsModel::CURRENT, $fallback->url);
    }

    public function test_newest_dialogue_does_not_read_speaker_labels(): void
    {
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $captured = $this->fakeAudio('audio/wav', $this->wavBytes());

        app(GeminiTtsProvider::class)->synthesize("Sara: مرحبا\nWaleed: أهلا", 'ar', [
            'speakers' => [
                ['name' => 'Sara', 'voice' => 'Kore'],
                ['name' => 'Waleed', 'voice' => 'Charon'],
            ],
        ]);

        $parts = $captured->body['contents'][0]['parts'];
        $this->assertSame('مرحبا', $parts[0]['text']);
        $this->assertSame('Sara', $parts[0]['speech_metadata']['speaker']);
        $this->assertSame('أهلا', $parts[1]['text']);
        $this->assertSame('Waleed', $parts[1]['speech_metadata']['speaker']);
        $voices = $captured->body['generationConfig']['speechConfig']['multiSpeakerVoiceConfig']['speakerVoiceConfigs'];
        $this->assertSame('Kore', $voices[0]['voiceConfig']['prebuiltVoiceConfig']['voiceName']);
        $this->assertSame('Charon', $voices[1]['voiceConfig']['prebuiltVoiceConfig']['voiceName']);
    }

    public function test_pcm_beginning_with_mpeg_sync_bits_is_saved_as_mp3(): void
    {
        $pcm = "\xff\xff\x00\x00".$this->pcmBytes();
        $this->fakeAudio('audio/l16', $pcm);

        $result = app(GeminiTtsProvider::class)->synthesize('مرحبا', 'ar', [
            'voice_name' => 'Kore',
        ]);

        $saved = Storage::disk('public')->get($result['audio_path']);
        $this->assertStringEndsWith('.mp3', $result['audio_path']);
        $this->assertTrue(\App\Support\ShareableAudio::isMp3($saved));
        $this->assertStringStartsNotWith('RIFF', $saved);
    }

    private function fakeAudio(string $mime, string $bytes): \stdClass
    {
        $captured = new \stdClass;
        $captured->url = '';
        $captured->body = [];
        Http::fake(function ($request) use ($captured, $mime, $bytes) {
            $captured->url = $request->url();
            $captured->body = $request->data();

            return Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'inlineData' => [
                                'mimeType' => $mime,
                                'data' => base64_encode($bytes),
                            ],
                        ]],
                    ],
                ]],
            ]);
        });

        return $captured;
    }

    private function pcmBytes(): string
    {
        return pack('v*', 0, 1200, 2400, 1200, 0, -1200, -2400, -1200);
    }

    private function wavBytes(): string
    {
        $pcm = $this->pcmBytes();
        $dataLength = strlen($pcm);

        return 'RIFF'
            .pack('V', 36 + $dataLength)
            .'WAVE'
            .'fmt '
            .pack('V', 16)
            .pack('v', 1)
            .pack('v', 1)
            .pack('V', 24000)
            .pack('V', 48000)
            .pack('v', 2)
            .pack('v', 16)
            .'data'
            .pack('V', $dataLength)
            .$pcm;
    }
}
