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
        $this->assertSame('Sports Commentator', $row['label_en'] ?? null);
        // The Arabic label comes from the Arabic file whatever the page's language.
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', (string) ($row['label_ar'] ?? ''));
    }

    public function test_style_and_emotion_left_on_default_get_the_site_defaults(): void
    {
        $this->assertSame(
            ['performance_style' => \App\Support\TtsStudioConfig::defaultPerformanceStyle('ar'), 'emotion' => \App\Support\TtsStudioConfig::defaultEmotion('ar')],
            \App\Support\TtsStudioConfig::withDefaultChoices(['performance_style' => '', 'emotion' => null], 'ar'),
        );
        $this->assertSame(
            ['performance_style' => 'cinematic', 'emotion' => \App\Support\TtsStudioConfig::defaultEmotion('en')],
            \App\Support\TtsStudioConfig::withDefaultChoices(['performance_style' => 'cinematic'], 'en'),
        );
    }
}
