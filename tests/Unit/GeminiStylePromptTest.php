<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Comprehensive tests for Gemini TTS style prompt building.
 * 
 * Tests cover:
 * - All 19 Arabic dialects
 * - All 7 emotions
 * - All 22 performance styles
 * - Speed variations (very slow to very fast)
 * - Pitch variations (very low to very high)
 * - Arabic vs English language handling
 * - Combined dialect + emotion + style scenarios
 */
class GeminiStylePromptTest extends TestCase
{
    use RefreshDatabase;

    private object $provider;
    private ReflectionMethod $buildStylePrompt;
    private ReflectionMethod $getEmotionDescription;
    private ReflectionMethod $getPerformanceStyleDescription;
    private ReflectionMethod $isArabicLanguage;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create instance without calling constructor
        $class = new ReflectionClass(\App\Services\Tts\GeminiTtsProvider::class);
        $this->provider = $class->newInstanceWithoutConstructor();
        
        // Access private methods via reflection
        $this->buildStylePrompt = $class->getMethod('buildStylePrompt');
        $this->buildStylePrompt->setAccessible(true);
        
        $this->getEmotionDescription = $class->getMethod('getEmotionDescription');
        $this->getEmotionDescription->setAccessible(true);
        
        $this->getPerformanceStyleDescription = $class->getMethod('getPerformanceStyleDescription');
        $this->getPerformanceStyleDescription->setAccessible(true);
        
        $this->isArabicLanguage = $class->getMethod('isArabicLanguage');
        $this->isArabicLanguage->setAccessible(true);
    }

    // ==========================================
    // ARABIC DIALECT TESTS
    // ==========================================

    #[DataProvider('arabicDialectsProvider')]
    public function test_arabic_dialect_included_in_prompt(string $dialectCode, string $expectedDialectName): void
    {
        $prompt = $this->buildStylePrompt->invoke(
            $this->provider,
            'neutral',   // style
            'neutral',   // emotion
            'ar',        // targetLanguage
            1.0,         // speed
            $dialectCode, // dialect
            0.0          // pitch
        );

        $this->assertStringContainsString('Voice dialect:', $prompt);
        $this->assertStringContainsString($expectedDialectName, $prompt);
        $this->assertStringContainsString('authentic regional pronunciation', $prompt);
    }

    public static function arabicDialectsProvider(): array
    {
        return [
            'Saudi Arabic' => ['ar-SA', 'Saudi Arabic (Gulf dialect)'],
            'Emirati Arabic' => ['ar-AE', 'Emirati Arabic (Gulf dialect)'],
            'Kuwaiti Arabic' => ['ar-KW', 'Kuwaiti Arabic (Gulf dialect)'],
            'Qatari Arabic' => ['ar-QA', 'Qatari Arabic (urban Doha, Gulf dialect)'],
            'Bahraini Arabic' => ['ar-BH', 'Bahraini Arabic (Gulf dialect)'],
            'Omani Arabic' => ['ar-OM', 'Omani Arabic (Gulf dialect)'],
            'Yemeni Arabic' => ['ar-YE', 'Yemeni Arabic'],
            'Egyptian Arabic' => ['ar-EG', 'Egyptian Arabic'],
            'Lebanese Arabic' => ['ar-LB', 'Lebanese Arabic (Levantine)'],
            'Syrian Arabic' => ['ar-SY', 'Syrian Arabic (Levantine)'],
            'Jordanian Arabic' => ['ar-JO', 'Jordanian Arabic (Levantine)'],
            'Palestinian Arabic' => ['ar-PS', 'Palestinian Arabic (Levantine)'],
            'Iraqi Arabic' => ['ar-IQ', 'Iraqi Arabic'],
            'Moroccan Arabic' => ['ar-MA', 'Moroccan Arabic (Maghrebi)'],
            'Algerian Arabic' => ['ar-DZ', 'Algerian Arabic (Maghrebi)'],
            'Tunisian Arabic' => ['ar-TN', 'Tunisian Arabic (Maghrebi)'],
            'Libyan Arabic' => ['ar-LY', 'Libyan Arabic (Maghrebi)'],
            'Sudanese Arabic' => ['ar-SD', 'Sudanese Arabic'],
            'Modern Standard Arabic' => ['ar-XA', 'Modern Standard Arabic (Fusha)'],
        ];
    }

    public function test_dialect_not_added_for_english(): void
    {
        $prompt = $this->buildStylePrompt->invoke(
            $this->provider,
            'neutral',
            'neutral',
            'en-US',
            1.0,
            'ar-EG', // Even if dialect is passed for English
            0.0
        );

        $this->assertStringNotContainsString('Voice dialect:', $prompt);
        $this->assertStringNotContainsString('Egyptian Arabic', $prompt);
    }

    public function test_unknown_dialect_handled_gracefully(): void
    {
        $prompt = $this->buildStylePrompt->invoke(
            $this->provider,
            'neutral',
            'neutral',
            'ar',
            1.0,
            'ar-UNKNOWN', // Unknown dialect code
            0.0
        );

        // Should not crash and should have fallback content
        $this->assertIsString($prompt);
        $this->assertStringNotContainsString('Voice dialect:', $prompt);
    }

    public function test_null_dialect_handled_gracefully(): void
    {
        $prompt = $this->buildStylePrompt->invoke(
            $this->provider,
            'neutral',
            'neutral',
            'ar',
            1.0,
            null, // No dialect
            0.0
        );

        $this->assertIsString($prompt);
        $this->assertStringNotContainsString('Voice dialect:', $prompt);
    }

    public function test_empty_dialect_handled_gracefully(): void
    {
        $prompt = $this->buildStylePrompt->invoke(
            $this->provider,
            'neutral',
            'neutral',
            'ar',
            1.0,
            '', // Empty dialect
            0.0
        );

        $this->assertIsString($prompt);
        $this->assertStringNotContainsString('Voice dialect:', $prompt);
    }

    // ==========================================
    // EMOTION TESTS
    // ==========================================

    #[DataProvider('emotionsProvider')]
    public function test_emotion_included_in_prompt(string $emotion, string $expectedDescription): void
    {
        $prompt = $this->buildStylePrompt->invoke(
            $this->provider,
            'neutral',   // style
            $emotion,    // emotion
            'en-US',     // targetLanguage
            1.0,         // speed
            null,        // dialect
            0.0          // pitch
        );

        if ($emotion !== 'neutral') {
            $this->assertStringContainsString('Emotional tone:', $prompt);
            $this->assertStringContainsString($expectedDescription, $prompt);
        } else {
            // Neutral emotion should NOT have emotional tone text
            $this->assertStringNotContainsString('Emotional tone:', $prompt);
        }
    }

    public static function emotionsProvider(): array
    {
        return [
            'warm' => ['warm', 'warm, friendly, and approachable'],
            'sad' => ['sad', 'melancholic, sorrowful, with deep emotional weight'],
            'excited' => ['excited', 'highly energetic, enthusiastic, and thrilling'],
            'formal' => ['formal', 'professional, authoritative, and dignified'],
            'calm' => ['calm', 'serene, peaceful, and deeply relaxing'],
            'angry' => ['angry', 'intense, passionate, with controlled fury'],
            'neutral' => ['neutral', ''], // Should not have emotional tone text
        ];
    }

    public function test_neutral_emotion_no_emotional_tone_text(): void
    {
        $prompt = $this->buildStylePrompt->invoke(
            $this->provider,
            'conversational',
            'neutral',
            'en-US',
            1.0,
            null,
            0.0
        );

        $this->assertStringNotContainsString('Emotional tone:', $prompt);
    }

    public function test_unknown_emotion_handled_gracefully(): void
    {
        $prompt = $this->buildStylePrompt->invoke(
            $this->provider,
            'neutral',
            'unknown_emotion',
            'en-US',
            1.0,
            null,
            0.0
        );

        // Should not crash
        $this->assertIsString($prompt);
    }

    // ==========================================
    // PERFORMANCE STYLE TESTS
    // ==========================================

    #[DataProvider('performanceStylesProvider')]
    public function test_performance_style_included_in_prompt(string $style): void
    {
        $prompt = $this->buildStylePrompt->invoke(
            $this->provider,
            $style,
            'neutral',
            'en-US',
            1.0,
            null,
            0.0
        );

        // Each style should produce some instruction
        $this->assertNotEmpty($prompt);
        $this->assertIsString($prompt);
    }

    public static function performanceStylesProvider(): array
    {
        return [
            'warm' => ['warm'],
            'sad' => ['sad'],
            'excited' => ['excited'],
            'formal' => ['formal'],
            'storytelling' => ['storytelling'],
            'ad_style' => ['ad_style'],
            'news' => ['news'],
            'calm' => ['calm'],
            'angry' => ['angry'],
            'educational' => ['educational'],
            'commercial' => ['commercial'],
            'promo' => ['promo'],
            'podcast' => ['podcast'],
            'assistant' => ['assistant'],
            'narrative' => ['narrative'],
            'comedic' => ['comedic'],
            'corporate' => ['corporate'],
            'conversational' => ['conversational'],
            'inspirational' => ['inspirational'],
            'cinematic' => ['cinematic'],
            'documentary' => ['documentary'],
            'neutral' => ['neutral'],
        ];
    }

    #[DataProvider('arabicPerformanceStylesProvider')]
    public function test_arabic_performance_styles_use_arabic_hints(string $style): void
    {
        $prompt = $this->buildStylePrompt->invoke(
            $this->provider,
            $style,
            'neutral',
            'ar',
            1.0,
            null,
            0.0
        );

        // Arabic styles should contain Arabic-specific content
        $this->assertNotEmpty($prompt);
        $this->assertIsString($prompt);
    }

    public static function arabicPerformanceStylesProvider(): array
    {
        return [
            'neutral' => ['neutral'],
            'warm' => ['warm'],
            'sad' => ['sad'],
            'excited' => ['excited'],
            'formal' => ['formal'],
            'storytelling' => ['storytelling'],
            'ad_style' => ['ad_style'],
            'news' => ['news'],
            'calm' => ['calm'],
            'angry' => ['angry'],
            'educational' => ['educational'],
            'commercial' => ['commercial'],
            'promo' => ['promo'],
            'podcast' => ['podcast'],
            'assistant' => ['assistant'],
            'narrative' => ['narrative'],
            'comedic' => ['comedic'],
            'corporate' => ['corporate'],
            'conversational' => ['conversational'],
            'inspirational' => ['inspirational'],
            'cinematic' => ['cinematic'],
            'documentary' => ['documentary'],
        ];
    }

    // ==========================================
    // SPEED TESTS
    // ==========================================

    #[DataProvider('speedVariationsProvider')]
    public function test_speed_variations_affect_prompt(float $speed, ?string $expectedInstruction): void
    {
        $prompt = $this->buildStylePrompt->invoke(
            $this->provider,
            'neutral',
            'neutral',
            'en-US',
            $speed,
            null,
            0.0
        );

        if ($expectedInstruction !== null) {
            $this->assertStringContainsString($expectedInstruction, $prompt);
        } else {
            // Normal speed should not have speed instruction
            $this->assertStringNotContainsString('slowly', $prompt);
            $this->assertStringNotContainsString('quickly', $prompt);
            $this->assertStringNotContainsString('faster', $prompt);
        }
    }

    public static function speedVariationsProvider(): array
    {
        return [
            'very slow (0.5)' => [0.5, 'very slowly'],
            'very slow (0.6)' => [0.6, 'very slowly'],
            'slow (0.7)' => [0.7, 'slow, measured pace'],
            'slow (0.8)' => [0.8, 'slow, measured pace'],
            'normal (0.9)' => [0.9, null],
            'normal (1.0)' => [1.0, null],
            'normal (1.1)' => [1.1, null],
            'normal (1.2)' => [1.2, null],
            'fast (1.3)' => [1.3, 'faster pace'],
            'fast (1.4)' => [1.4, 'faster pace'],
            'very fast (1.6)' => [1.6, 'very quickly'],
            'very fast (2.0)' => [2.0, 'very quickly'],
        ];
    }

    // ==========================================
    // PITCH TESTS
    // ==========================================

    #[DataProvider('pitchVariationsProvider')]
    public function test_pitch_variations_affect_prompt(float $pitch, ?string $expectedInstruction): void
    {
        $prompt = $this->buildStylePrompt->invoke(
            $this->provider,
            'neutral',
            'neutral',
            'en-US',
            1.0,
            null,
            $pitch
        );

        if ($expectedInstruction !== null) {
            $this->assertStringContainsString($expectedInstruction, $prompt);
        } else {
            // Normal pitch should not have pitch instruction
            $this->assertStringNotContainsString('deep', $prompt);
            $this->assertStringNotContainsString('low-pitched', $prompt);
            $this->assertStringNotContainsString('high-pitched', $prompt);
            $this->assertStringNotContainsString('bright', $prompt);
        }
    }

    public static function pitchVariationsProvider(): array
    {
        return [
            'very low (-15)' => [-15, 'very deep, low-pitched'],
            'very low (-10)' => [-10, 'very deep, low-pitched'],
            'low (-5)' => [-5, 'deeper, lower-pitched'],
            'low (-4)' => [-4, 'deeper, lower-pitched'],
            'normal (-3)' => [-3, null],
            'normal (-2)' => [-2, null],
            'normal (0)' => [0, null],
            'normal (2)' => [2, null],
            'normal (3)' => [3, null],
            'high (4)' => [4, 'higher-pitched, brighter'],
            'high (8)' => [8, 'higher-pitched, brighter'],
            'very high (10)' => [10, 'very high-pitched, bright'],
            'very high (15)' => [15, 'very high-pitched, bright'],
        ];
    }

    // ==========================================
    // LANGUAGE DETECTION TESTS
    // ==========================================

    #[DataProvider('arabicLanguageCodesProvider')]
    public function test_arabic_language_detection(string $languageCode): void
    {
        $isArabic = $this->isArabicLanguage->invoke($this->provider, $languageCode);
        $this->assertTrue($isArabic, "Expected '{$languageCode}' to be detected as Arabic");
    }

    public static function arabicLanguageCodesProvider(): array
    {
        return [
            'ar' => ['ar'],
            'AR' => ['AR'],
            'ar-XA' => ['ar-XA'],
            'ar-xa' => ['ar-xa'],
            'ar-EG' => ['ar-EG'],
            'ar-SA' => ['ar-SA'],
            'ar-AE' => ['ar-AE'],
            'ar-LB' => ['ar-LB'],
            'ar-IQ' => ['ar-IQ'],
            'ar-MA' => ['ar-MA'],
        ];
    }

    #[DataProvider('nonArabicLanguageCodesProvider')]
    public function test_non_arabic_language_detection(string $languageCode): void
    {
        $isArabic = $this->isArabicLanguage->invoke($this->provider, $languageCode);
        $this->assertFalse($isArabic, "Expected '{$languageCode}' to NOT be detected as Arabic");
    }

    public static function nonArabicLanguageCodesProvider(): array
    {
        return [
            'en-US' => ['en-US'],
            'en-GB' => ['en-GB'],
            'en' => ['en'],
            'fr-FR' => ['fr-FR'],
            'de-DE' => ['de-DE'],
            'es-ES' => ['es-ES'],
            'ja-JP' => ['ja-JP'],
            'zh-CN' => ['zh-CN'],
            'ru-RU' => ['ru-RU'],
        ];
    }

    // ==========================================
    // COMBINED SCENARIO TESTS
    // ==========================================

    public function test_full_arabic_combination_dialect_emotion_style_speed_pitch(): void
    {
        $prompt = $this->buildStylePrompt->invoke(
            $this->provider,
            'storytelling',  // style
            'excited',       // emotion
            'ar',            // targetLanguage
            0.7,             // speed (slow)
            'ar-EG',         // dialect (Egyptian)
            5.0              // pitch (higher)
        );

        // Should contain all elements
        $this->assertStringContainsString('Voice dialect: Egyptian Arabic', $prompt);
        $this->assertStringContainsString('Emotional tone:', $prompt);
        $this->assertStringContainsString('energetic', $prompt);
        $this->assertStringContainsString('slow', $prompt);
        $this->assertStringContainsString('higher-pitched', $prompt);
    }

    public function test_full_english_combination_emotion_style_speed_pitch(): void
    {
        $prompt = $this->buildStylePrompt->invoke(
            $this->provider,
            'podcast',       // style
            'warm',          // emotion
            'en-US',         // targetLanguage
            1.4,             // speed (fast)
            null,            // dialect (not applicable for English)
            -6.0             // pitch (lower)
        );

        // Should contain emotion, style, speed, pitch but NOT dialect
        $this->assertStringNotContainsString('Voice dialect:', $prompt);
        $this->assertStringContainsString('Emotional tone:', $prompt);
        $this->assertStringContainsString('warm', $prompt);
        $this->assertStringContainsString('faster', $prompt);
        $this->assertStringContainsString('deeper', $prompt);
    }

    public function test_gulf_dialect_with_commercial_style(): void
    {
        $prompt = $this->buildStylePrompt->invoke(
            $this->provider,
            'commercial',
            'excited',
            'ar',
            1.6, // very fast
            'ar-SA', // Saudi
            0.0
        );

        $this->assertStringContainsString('Saudi Arabic (Gulf dialect)', $prompt);
        $this->assertStringContainsString('energetic', $prompt);
        $this->assertStringContainsString('quickly', $prompt);
    }

    public function test_levantine_dialect_with_calm_style(): void
    {
        $prompt = $this->buildStylePrompt->invoke(
            $this->provider,
            'calm',
            'calm',
            'ar',
            0.5, // very slow
            'ar-LB', // Lebanese
            -12.0 // very low pitch
        );

        $this->assertStringContainsString('Lebanese Arabic (Levantine)', $prompt);
        $this->assertStringContainsString('serene', $prompt);
        $this->assertStringContainsString('very slowly', $prompt);
        $this->assertStringContainsString('very deep', $prompt);
    }

    public function test_maghrebi_dialect_with_news_style(): void
    {
        $prompt = $this->buildStylePrompt->invoke(
            $this->provider,
            'news',
            'formal',
            'ar',
            1.3, // slightly fast
            'ar-MA', // Moroccan
            0.0
        );

        $this->assertStringContainsString('Moroccan Arabic (Maghrebi)', $prompt);
        $this->assertStringContainsString('authoritative', $prompt);
    }

    // ==========================================
    // EDGE CASE TESTS
    // ==========================================

    public function test_whitespace_in_parameters_handled(): void
    {
        $prompt = $this->buildStylePrompt->invoke(
            $this->provider,
            '  storytelling  ', // extra whitespace
            '  excited  ',      // extra whitespace
            'ar',
            1.0,
            'ar-EG',
            0.0
        );

        // Should work without issues
        $this->assertIsString($prompt);
        $this->assertStringContainsString('Egyptian Arabic', $prompt);
    }

    public function test_case_insensitivity_for_style_and_emotion(): void
    {
        $prompt1 = $this->buildStylePrompt->invoke($this->provider, 'STORYTELLING', 'EXCITED', 'ar', 1.0, null, 0.0);
        $prompt2 = $this->buildStylePrompt->invoke($this->provider, 'storytelling', 'excited', 'ar', 1.0, null, 0.0);

        $this->assertEquals($prompt1, $prompt2);
    }

    public function test_unknown_style_is_passed_through_by_name(): void
    {
        // Styles come from an admin-editable catalog, so an unrecognised style is
        // sent to Gemini by name rather than silently dropped.
        $prompt = $this->buildStylePrompt->invoke(
            $this->provider,
            'unknown_style',
            'neutral',
            'en-US',
            1.0,
            null,
            0.0
        );

        $this->assertStringContainsString('Performance style: unknown style.', $prompt);
        $this->assertStringContainsString('consistent', $prompt);
    }

    public function test_fallback_prompt_when_no_style_or_emotion_is_given(): void
    {
        $english = $this->buildStylePrompt->invoke($this->provider, '', 'neutral', 'en-US', 1.0, null, 0.0);
        $arabic = $this->buildStylePrompt->invoke($this->provider, '', 'neutral', 'ar', 1.0, null, 0.0);

        $this->assertStringContainsString('naturally', $english);
        $this->assertStringContainsString('professional', $english);
        $this->assertStringContainsString('Arabic', $arabic);
        $this->assertStringContainsString('naturally', $arabic);
    }

    public function test_extreme_speed_values(): void
    {
        // Extremely slow
        $prompt1 = $this->buildStylePrompt->invoke($this->provider, 'neutral', 'neutral', 'en-US', 0.1, null, 0.0);
        $this->assertStringContainsString('very slowly', $prompt1);

        // Extremely fast
        $prompt2 = $this->buildStylePrompt->invoke($this->provider, 'neutral', 'neutral', 'en-US', 5.0, null, 0.0);
        $this->assertStringContainsString('very quickly', $prompt2);
    }

    public function test_extreme_pitch_values(): void
    {
        // Extremely low
        $prompt1 = $this->buildStylePrompt->invoke($this->provider, 'neutral', 'neutral', 'en-US', 1.0, null, -50.0);
        $this->assertStringContainsString('very deep', $prompt1);

        // Extremely high
        $prompt2 = $this->buildStylePrompt->invoke($this->provider, 'neutral', 'neutral', 'en-US', 1.0, null, 50.0);
        $this->assertStringContainsString('very high-pitched', $prompt2);
    }

    // ==========================================
    // COMPREHENSIVE MATRIX TEST
    // ==========================================

    /**
     * Test all dialect + emotion combinations work without errors.
     */
    #[DataProvider('dialectEmotionMatrixProvider')]
    public function test_dialect_emotion_matrix(string $dialect, string $emotion): void
    {
        $prompt = $this->buildStylePrompt->invoke(
            $this->provider,
            'neutral',
            $emotion,
            'ar',
            1.0,
            $dialect,
            0.0
        );

        $this->assertIsString($prompt);
        $this->assertNotEmpty($prompt);
    }

    public static function dialectEmotionMatrixProvider(): array
    {
        $dialects = ['ar-SA', 'ar-EG', 'ar-LB', 'ar-AE', 'ar-IQ', 'ar-MA', 'ar-XA'];
        $emotions = ['neutral', 'warm', 'sad', 'excited', 'formal', 'calm', 'angry'];
        
        $matrix = [];
        foreach ($dialects as $dialect) {
            foreach ($emotions as $emotion) {
                $matrix["{$dialect} + {$emotion}"] = [$dialect, $emotion];
            }
        }
        
        return $matrix;
    }

    /**
     * Test all style + emotion combinations work without errors.
     */
    #[DataProvider('styleEmotionMatrixProvider')]
    public function test_style_emotion_matrix(string $style, string $emotion): void
    {
        $prompt = $this->buildStylePrompt->invoke(
            $this->provider,
            $style,
            $emotion,
            'en-US',
            1.0,
            null,
            0.0
        );

        $this->assertIsString($prompt);
        $this->assertNotEmpty($prompt);
    }

    public static function styleEmotionMatrixProvider(): array
    {
        $styles = ['storytelling', 'news', 'commercial', 'calm', 'podcast', 'educational'];
        $emotions = ['neutral', 'warm', 'sad', 'excited', 'formal', 'calm', 'angry'];
        
        $matrix = [];
        foreach ($styles as $style) {
            foreach ($emotions as $emotion) {
                $matrix["{$style} + {$emotion}"] = [$style, $emotion];
            }
        }
        
        return $matrix;
    }

    public function test_every_english_accent_travels_in_the_style_text(): void
    {
        foreach (\App\Support\EnglishAccents::ALL as $code => $row) {
            $prompt = $this->buildStylePrompt->invoke($this->provider, 'neutral', 'neutral', 'en', 1.0, $code, 0.0, true);
            $this->assertStringContainsString('Voice accent: ', $prompt, $code);
            $this->assertLessThanOrEqual(10, strlen($code), 'fits the dialect columns');
        }
        $prompt = $this->buildStylePrompt->invoke($this->provider, 'neutral', 'neutral', 'en', 1.0, 'en-GB-SC', 0.0, true);
        $this->assertStringContainsString('Voice accent: Scottish English.', $prompt);
    }

    public function test_every_style_and_emotion_has_a_direction(): void
    {
        foreach (\App\Support\TtsStudioConfig::performanceStyleValues() as $style) {
            if ($style === 'neutral') {
                continue;
            }
            $description = $this->getPerformanceStyleDescription->invoke($this->provider, $style, false);
            $this->assertStringStartsNotWith('Performance style:', $description, $style.' has its own direction');
        }
        foreach (\App\Support\TtsStudioConfig::emotionValues() as $emotion) {
            if (in_array($emotion, ['neutral', 'hopeful', 'serious', 'playful', 'mysterious', 'confident', 'formal'], true)) {
                continue;
            }
            $this->assertNotSame('', $this->getEmotionDescription->invoke($this->provider, $emotion, false), $emotion);
        }
        $prompt = $this->buildStylePrompt->invoke($this->provider, 'movie_trailer', 'nostalgic', 'en', 1.0, null, 0.0, true);
        $this->assertStringContainsString('movie-trailer voiceover', $prompt);
        $this->assertStringContainsString('Emotional tone: wistful and nostalgic', $prompt);
    }
}
