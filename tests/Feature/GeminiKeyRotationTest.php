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

    private const SPENDING_CAP = ['error' => ['code' => 429, 'message' => 'Your project has exceeded its monthly spending cap.', 'status' => 'RESOURCE_EXHAUSTED']];

    private const PER_MINUTE = ['error' => ['code' => 429, 'message' => 'You exceeded your current quota, please check your plan and billing details. Please retry in 20s.', 'status' => 'RESOURCE_EXHAUSTED']];

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

    public function test_a_spending_cap_is_told_apart_from_an_ordinary_rate_limit(): void
    {
        $this->assertTrue(GeminiKeyManager::isSpendingCapError(429, json_encode(self::SPENDING_CAP)));
        $this->assertTrue(GeminiKeyManager::isSpendingCapError(429, '{"error":{"message":"Your prepayment credits are depleted.","status":"RESOURCE_EXHAUSTED"}}'));
        $this->assertTrue(GeminiKeyManager::isSpendingCapError(403, '{"error":{"message":"The billing account for the project is closed."}}'));

        // Every ordinary 429 asks to check the plan and billing details: that is not a cap.
        $this->assertFalse(GeminiKeyManager::isSpendingCapError(429, json_encode(self::PER_MINUTE)));
        $this->assertFalse(GeminiKeyManager::isSpendingCapError(429, json_encode(self::DAILY)));
        $this->assertFalse(GeminiKeyManager::isSpendingCapError(500, json_encode(self::SPENDING_CAP)));
    }

    public function test_a_key_stopped_by_its_spending_cap_is_out_on_every_model_for_an_hour(): void
    {
        $manager = $this->threeKeys();

        $manager->markOutForSpendingCap('GEMINI_TTS_API_KEY');

        foreach ([GeminiTtsModel::FLASH, GeminiTtsModel::CURRENT, 'gemini-3.5-flash'] as $model) {
            $this->assertTrue($manager->isOutForToday('GEMINI_TTS_API_KEY', $model), $model);
            $this->assertSame('GEMINI_TTS_API_KEY_2', $manager->getAvailableKey($model)['name'], $model);
        }
        $this->assertSame(['GEMINI_TTS_API_KEY_2', 'GEMINI_TTS_API_KEY_3', 'GEMINI_TTS_API_KEY'], array_column($manager->keysInOrder(GeminiTtsModel::FLASH), 'name'), 'a capped key goes last');

        $this->travel(59)->minutes();
        $this->assertTrue($manager->isOutForToday('GEMINI_TTS_API_KEY', GeminiTtsModel::FLASH), 'not tried again within the hour');
        $this->travel(2)->minutes();
        $this->assertFalse($manager->isOutForToday('GEMINI_TTS_API_KEY', GeminiTtsModel::FLASH), 'tried again after it, in case the cap was raised');
    }

    public function test_stock_voice_moves_on_from_a_capped_key_and_stays_off_it(): void
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
                ? Http::response(self::SPENDING_CAP, 429)
                : Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/L16;rate=24000', 'data' => base64_encode(str_repeat("\0", 4800))]]]]]]]);
        });

        app(GeminiTtsProvider::class)->synthesize('مرحبا بكم', 'ar', ['voice_name' => 'Kore']);
        $this->assertSame(['k1', 'k2'], $calls, 'the member gets audio from the next key');

        $calls = [];
        $this->travel(5)->minutes();
        app(GeminiTtsProvider::class)->synthesize('أهلا وسهلا', 'ar', ['voice_name' => 'Kore']);
        $this->assertSame(['k2'], $calls, 'later requests do not try the capped key first');
    }

    public function test_stock_voice_moves_on_from_a_key_out_for_the_day_and_stays_off_it(): void
    {
        // Midday in California: the five minutes below never cross the provider's midnight reset.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-14 12:00:00', 'America/Los_Angeles'));
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

    public function test_a_piece_that_hits_its_keys_limit_is_sent_on_another_key_not_the_same_one(): void
    {
        Storage::fake('public');
        config([
            'services.gemini_tts.api_key' => '', 'services.gemini_tts.dialect_rewrite' => false,
            'services.gemini_tts.retry_pause_ms' => 0, 'services.gemini_tts.max_chars_per_request' => 200,
        ]);
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $manager = $this->threeKeys();
        $calls = ['k1' => 0, 'k2' => 0, 'k3' => 0];
        Http::fake(function ($request) use (&$calls) {
            $key = $request->header('x-goog-api-key')[0] ?? '';
            $calls[$key]++;

            return $key === 'k1'
                ? Http::response(['error' => ['code' => 429, 'message' => 'Rate limit exceeded: requests per minute.', 'status' => 'RESOURCE_EXHAUSTED']], 429)
                : Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/L16;rate=24000', 'data' => base64_encode(str_repeat("\0", 4800))]]]]]]]);
        });
        // Three pieces, sent together on the first key.
        $sentence = trim(str_repeat('The quick brown fox jumps over the lazy dog. ', 3));
        $text = implode("\n", [$sentence, $sentence, $sentence]);

        $result = app(GeminiTtsProvider::class)->synthesize($text, 'en', ['voice_name' => 'Kore']);

        Storage::disk('public')->assertExists($result['audio_path']);
        $this->assertSame(['k1' => 3, 'k2' => 3, 'k3' => 0], $calls, 'each piece went once to the limited key, then to the next one');
        $this->assertSame($manager->getKeyStats('GEMINI_TTS_API_KEY')['rpm_limit'], $manager->getKeyStats('GEMINI_TTS_API_KEY')['rpm_used'], 'the limited key is set aside for the minute');
        $this->assertSame(3, $manager->getKeyStats('GEMINI_TTS_API_KEY_2')['rpm_used'], 'the usage is counted on the key that answered');
    }

    public function test_a_keys_minute_counters_start_again_after_the_minute_under_steady_use(): void
    {
        $manager = new GeminiKeyManager;

        // A request every half minute: each one used to restart the minute.
        $manager->recordUsage('GEMINI_TTS_API_KEY', 400);
        $this->travel(30)->seconds();
        $manager->recordUsage('GEMINI_TTS_API_KEY', 400);
        $this->travel(29)->seconds();
        $manager->recordUsage('GEMINI_TTS_API_KEY', 400);

        $stats = $manager->getKeyStats('GEMINI_TTS_API_KEY');
        $this->assertSame(3, $stats['rpm_used']);
        $this->assertSame(300, $stats['tpm_used']);

        $this->travel(2)->seconds();
        $stats = $manager->getKeyStats('GEMINI_TTS_API_KEY');
        $this->assertSame(0, $stats['rpm_used'], 'a minute after the first request the window is over');
        $this->assertSame(0, $stats['tpm_used']);
        $this->assertSame(3, $stats['rpd_used'], 'the day still counts all three');

        $manager->recordUsage('GEMINI_TTS_API_KEY', 400);
        $this->assertSame(1, $manager->getKeyStats('GEMINI_TTS_API_KEY')['rpm_used'], 'a new minute starts');
    }

    public function test_a_key_google_refuses_is_skipped_and_the_member_still_gets_audio(): void
    {
        Storage::fake('public');
        config(['services.gemini_tts.api_key' => '', 'services.gemini_tts.voice_retry_pause' => false, 'services.gemini_tts.dialect_rewrite' => false]);
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $manager = $this->threeKeys();
        $manager->markOutForToday('GEMINI_TTS_API_KEY', GeminiTtsModel::FLASH);
        $manager->markOutForToday('GEMINI_TTS_API_KEY_2', GeminiTtsModel::FLASH);
        $calls = [];
        Http::fake(function ($request) use (&$calls) {
            $key = $request->header('x-goog-api-key')[0] ?? '';
            $calls[] = $key;

            return $key === 'k3'
                ? Http::response(['error' => ['code' => 400, 'message' => 'API key not valid. Please pass a valid API key.', 'status' => 'INVALID_ARGUMENT', 'details' => [['reason' => 'API_KEY_INVALID']]]], 400)
                : Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/L16;rate=24000', 'data' => base64_encode(str_repeat("\0", 4800))]]]]]]]);
        });

        app(GeminiTtsProvider::class)->synthesize('مرحبا بكم', 'ar', ['voice_name' => 'Kore']);
        $this->assertSame('k3', $calls[0], 'the only key with room was tried first');
        $this->assertNotContains('k3', array_slice($calls, 1), 'then the refused key is set aside');
        $this->assertTrue($manager->isOutForToday('GEMINI_TTS_API_KEY_3', GeminiTtsModel::FLASH));
    }
}
