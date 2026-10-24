<?php

namespace Tests\Feature;

use App\Services\Tts\GeminiTtsProvider;
use App\Support\GeminiTtsModel;
use App\Support\SpeakerVoices;
use Tests\TestCase;

class SpeakerVoicesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SpeakerVoices::set([
            'ar-Gemini-HD-Orus' => ['voice' => 'ar-001-advisor-3', 'fallback' => 'Orus'],
            'en-Gemini-HD-Iapetus' => ['voice' => 'en-gb-advisor-9', 'fallback' => 'Iapetus'],
        ]);
    }

    protected function tearDown(): void
    {
        SpeakerVoices::forget();
        parent::tearDown();
    }

    public function test_a_speaker_speaks_with_their_own_voice(): void
    {
        $this->assertSame('ar-001-advisor-3', $this->invoke('resolveGeminiVoice', 'ar-Gemini-HD-Orus', 'male'));
        $this->assertSame('en-gb-advisor-9', $this->invoke('resolveGeminiVoice', 'EN-GEMINI-HD-IAPETUS', 'male'));
        // A speaker with no voice of their own keeps the one in their id.
        $this->assertSame('Kore', $this->invoke('resolveGeminiVoice', 'ar-Gemini-HD-Kore', 'female'));
    }

    public function test_the_newest_model_gets_the_library_voice_and_the_older_one_the_fallback(): void
    {
        $new = $this->invoke('buildSingleSpeakerPayload', GeminiTtsModel::FLASH, 'مرحبا', 'calm', 'ar-001-advisor-3', 'ar-XA');
        $this->assertSame(['voice' => 'ar-001-advisor-3'], $new['generationConfig']['speechConfig']['voiceConfig']);

        $old = $this->invoke('buildSingleSpeakerPayload', GeminiTtsModel::CURRENT, 'مرحبا', 'calm', 'ar-001-advisor-3', 'ar-XA');
        $this->assertSame(['prebuiltVoiceConfig' => ['voiceName' => 'Orus']], $old['generationConfig']['speechConfig']['voiceConfig']);
    }

    public function test_a_dialogue_names_library_and_studio_voices_each_their_own_way(): void
    {
        $speakers = [
            ['name' => 'Fahad', 'voice_name' => 'ar-Gemini-HD-Orus', 'gender' => 'male'],
            ['name' => 'Sawsan', 'voice_name' => 'Zephyr', 'gender' => 'female'],
        ];
        $new = $this->invoke('buildDialoguePayload', GeminiTtsModel::FLASH, "Fahad: هلا\nSawsan: أهلين", '', $speakers, 'ar-XA');
        $configs = $new['generationConfig']['speechConfig']['multiSpeakerVoiceConfig']['speakerVoiceConfigs'];
        $this->assertSame(['voice' => 'ar-001-advisor-3'], $configs[0]['voiceConfig']);
        $this->assertSame(['prebuiltVoiceConfig' => ['voiceName' => 'Zephyr']], $configs[1]['voiceConfig']);

        $old = $this->invoke('buildDialoguePayload', GeminiTtsModel::CURRENT, "Fahad: هلا\nSawsan: أهلين", '', $speakers, 'ar-XA');
        $configs = $old['generationConfig']['speechConfig']['multiSpeakerVoiceConfig']['speakerVoiceConfigs'];
        $this->assertSame(['prebuiltVoiceConfig' => ['voiceName' => 'Orus']], $configs[0]['voiceConfig']);
    }

    public function test_every_assigned_voice_is_used_once(): void
    {
        SpeakerVoices::forget();
        $voices = array_column(SpeakerVoices::all(), 'voice');
        $this->assertSame(count($voices), count(array_unique($voices)), 'two speakers share a voice');
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod(GeminiTtsProvider::class, $method))->invoke(app(GeminiTtsProvider::class), ...$args);
    }
}
