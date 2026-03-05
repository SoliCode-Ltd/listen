<?php

namespace Tests\Unit;

use App\Services\Tts\GeminiTtsProvider;
use Tests\TestCase;

class GeminiTtsProviderTest extends TestCase
{
    private GeminiTtsProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new GeminiTtsProvider();
    }

    public function test_name_returns_gemini(): void
    {
        $this->assertSame('gemini', $this->provider->name());
    }

    public function test_supports_dialogue(): void
    {
        $this->assertTrue($this->provider->supportsDialogue());
    }

    public function test_is_available_returns_false_without_api_key(): void
    {
        config(['services.gemini_tts.api_key' => '']);
        $provider = new GeminiTtsProvider();
        $this->assertFalse($provider->isAvailable());
    }

    public function test_synthesize_throws_on_empty_text(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TTS text is empty');
        $this->provider->synthesize('', 'ar');
    }

    public function test_synthesize_throws_when_api_key_missing(): void
    {
        config(['services.gemini_tts.api_key' => '']);
        $provider = new GeminiTtsProvider();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API key');
        $provider->synthesize('بسم الله', 'ar');
    }
}
