<?php

namespace Tests\Feature;

use App\Services\Tts\GeminiTtsProvider;
use App\Support\SpeakerVoices;
use Tests\TestCase;

class SpeakerVoicesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SpeakerVoices::set([
            'ar-Gemini-HD-Orus' => ['name' => 'Abdulaziz', 'gender' => 'male', 'voice' => 'ar-001-advisor-3'],
            'en-Gemini-HD-Iapetus' => ['name' => 'Miles', 'gender' => 'male', 'voice' => 'en-gb-advisor-9'],
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
        $this->assertSame('ar-001-advisor-3', $this->invoke('resolveGeminiVoice', 'Abdulaziz', 'male'), 'by name too');
        $this->assertSame('male', GeminiTtsProvider::voiceGender('en-gb-advisor-9'));
        // A speaker with no voice of their own keeps the one in their id.
        $this->assertSame('Kore', $this->invoke('resolveGeminiVoice', 'ar-Gemini-HD-Kore', 'female'));
    }

    public function test_a_library_voice_is_named_the_library_way(): void
    {
        $payload = $this->invoke('buildSingleSpeakerPayload', 'مرحبا', 'calm', 'ar-001-advisor-3');
        $this->assertSame(['voice' => 'ar-001-advisor-3'], $payload['generationConfig']['speechConfig']['voiceConfig']);
    }

    public function test_a_dialogue_names_library_and_studio_voices_each_their_own_way(): void
    {
        $speakers = [
            ['name' => 'Fahad', 'voice_name' => 'ar-Gemini-HD-Orus', 'gender' => 'male'],
            ['name' => 'Sawsan', 'voice_name' => 'Zephyr', 'gender' => 'female'],
        ];
        $payload = $this->invoke('buildDialoguePayload', "Fahad: هلا\nSawsan: أهلين", '', $speakers);
        $configs = $payload['generationConfig']['speechConfig']['multiSpeakerVoiceConfig']['speakerVoiceConfigs'];
        $this->assertSame(['voice' => 'ar-001-advisor-3'], $configs[0]['voiceConfig']);
        $this->assertSame(['prebuiltVoiceConfig' => ['voiceName' => 'Zephyr']], $configs[1]['voiceConfig']);
    }

    public function test_no_two_speakers_of_a_language_share_a_voice(): void
    {
        SpeakerVoices::forget();
        $byLanguage = [];
        foreach (SpeakerVoices::all() as $id => $speaker) {
            $byLanguage[substr($id, 0, 2)][] = strtolower($speaker['voice']);
        }

        $this->assertSame(['ar', 'en'], array_keys($byLanguage));
        foreach ($byLanguage as $language => $voices) {
            $this->assertSame(count($voices), count(array_unique($voices)), "two {$language} speakers share a voice");
        }
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod(GeminiTtsProvider::class, $method))->invoke(app(GeminiTtsProvider::class), ...$args);
    }
}
