<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Support\TtsStudioConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TtsStudioConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_catalog_entries_are_merged_with_defaults(): void
    {
        SiteSetting::set('tts_performance_styles_catalog', json_encode([
            ['value' => 'brand_voice', 'label' => 'Brand Voice', 'label_ar' => 'Brand Voice AR'],
        ], JSON_UNESCAPED_UNICODE), 'tts');

        $values = TtsStudioConfig::performanceStyleValues();

        $this->assertContains('promo', $values);
        $this->assertContains('brand_voice', $values);
    }

    public function test_invalid_default_setting_falls_back_to_safe_default(): void
    {
        SiteSetting::set('tts_default_emotion_ar', 'non_existing_emotion', 'tts');

        $this->assertSame('confident', TtsStudioConfig::defaultEmotion('ar'));
    }

    public function test_missing_translation_keys_fall_back_to_human_readable_labels(): void
    {
        app()->setLocale('zz');

        $row = collect(TtsStudioConfig::performanceStyleCatalog())
            ->firstWhere('value', 'sports_commentator');

        $this->assertIsArray($row);
        $this->assertSame('Sports Commentator', $row['label'] ?? null);
        $this->assertSame('Sports Commentator', $row['label_ar'] ?? null);
    }
}
