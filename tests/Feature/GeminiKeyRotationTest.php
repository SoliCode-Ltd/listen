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
 * The site's Gemini keys are used in turn: a key Google has cut off for the
 * day is skipped until the reset, and members still get their audio.
 */
class GeminiKeyRotationTest extends TestCase
{
    use RefreshDatabase;

    private const DAILY = ['error' => ['code' => 429, 'message' => 'Rate limit exceeded for model gemini-3.8-flash-tts (limit: 100 requests per day on Tier 1).', 'status' => 'RESOURCE_EXHAUSTED']];

    private function threeKeys(): GeminiKeyManager
    {
        $manager = \Mockery::mock(GeminiKeyManager::class)->makePartial();
        $manager->shouldReceive('getAllKeys')->andReturn([
            ['key' => 'k1', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1],
            ['key' => 'k2', 'name' => 'GEMINI_TTS_API_KEY_2', 'index' => 2],
            ['key' => 'k3', 'name' => 'GEMINI_TTS_API_KEY_3', 'index' => 3],
        ]);
        $this->app->instance(GeminiKeyManager::class, $manager);

        return $manager;
    }

    public function test_a_key_out_for_the_day_is_skipped_until_the_reset(): void
    {
        $manager = $this->threeKeys();
        $model = GeminiTtsModel::FLASH;

        $this->assertSame('GEMINI_TTS_API_KEY', $manager->getAvailableKey($model)['name']);
        $manager->markOutForToday('GEMINI_TTS_API_KEY', $model);
        $this->assertSame('GEMINI_TTS_API_KEY_2', $manager->getAvailableKey($model)['name']);
        $this->assertSame('GEMINI_TTS_API_KEY', $manager->getAvailableKey('gemini-3.5-flash')['name'], 'other models keep the key');
        $this->assertSame(['GEMINI_TTS_API_KEY_2', 'GEMINI_TTS_API_KEY_3', 'GEMINI_TTS_API_KEY'], array_column($manager->keysInOrder($model), 'name'), 'cut-off keys go last');

        $this->travelTo(now('America/Los_Angeles')->addDay()->startOfDay()->addMinute());
        $this->assertFalse($manager->isOutForToday('GEMINI_TTS_API_KEY', $model), 'back after Google resets the day');
    }

    public function test_daily_and_per_minute_limits_are_told_apart(): void
    {
        $this->assertTrue(GeminiKeyManager::isDailyQuotaError(429, json_encode(self::DAILY)));
        $this->assertFalse(GeminiKeyManager::isDailyQuotaError(429, '{"error":{"message":"Rate limit exceeded: requests per minute"}}'));
        $this->assertFalse(GeminiKeyManager::isDailyQuotaError(500, 'per day'));
    }

    public function test_stock_voice_moves_on_from_a_key_out_for_the_day_and_stays_off_it(): void
    {
        Storage::fake('public');
        config(['services.gemini_tts.api_key' => '', 'services.gemini_tts.voice_retry_pause' => false, 'services.gemini_tts.dialect_rewrite' => false]);
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $this->threeKeys();
        $calls = [];
        Http::fake(function ($request) use (&$calls) {
            $key = $request->header('x-goog-api-key')[0] ?? '';
            $calls[] = $key;

            return $key === 'k1'
                ? Http::response(self::DAILY, 429)
                : Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/L16;rate=24000', 'data' => base64_encode(str_repeat("\0", 4800))]]]]]]]);
        });

        app(GeminiTtsProvider::class)->synthesize('مرحبا بكم', 'ar', ['voice_name' => 'Kore']);
        $this->assertSame(['k1', 'k2'], $calls, 'the member gets audio from the next key');

        $calls = [];
        $this->travel(5)->minutes();
        app(GeminiTtsProvider::class)->synthesize('أهلا وسهلا', 'ar', ['voice_name' => 'Kore']);
        $this->assertSame(['k2'], $calls, 'later requests skip the cut-off key');
    }
}
