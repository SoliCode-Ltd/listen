<?php

namespace Tests\Unit;

use App\Services\Tts\ArabicVoiceMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArabicVoiceMapperTest extends TestCase
{
    use RefreshDatabase;

    private ArabicVoiceMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new ArabicVoiceMapper();
    }

    // ─── Static Accessors ─────────────────────────────────────

    public function test_get_dialects_follows_the_studio_catalog(): void
    {
        $dialects = ArabicVoiceMapper::getDialects();

        $this->assertNotEmpty($dialects);
        $this->assertArrayHasKey('ar-SA', $dialects);
        $this->assertArrayHasKey('ar-EG', $dialects);
        $this->assertArrayHasKey('ar-XA', $dialects);
        // The admin-editable studio catalog decides which dialects are offered.
        $this->assertSame(
            array_column(\App\Support\TtsStudioConfig::dialectCatalogForLanguage('ar'), 'value'),
            array_keys($dialects)
        );
    }

    public function test_get_emotions_follows_the_studio_catalog(): void
    {
        $emotions = ArabicVoiceMapper::getEmotions();

        $this->assertNotEmpty($emotions);
        $this->assertArrayHasKey('neutral', $emotions);
        $this->assertArrayHasKey('warm', $emotions);
        $this->assertArrayHasKey('angry', $emotions);
        $this->assertSame(
            array_column(\App\Support\TtsStudioConfig::emotionCatalog(), 'value'),
            array_keys($emotions)
        );
    }

    public function test_emotions_have_required_keys(): void
    {
        foreach (ArabicVoiceMapper::getEmotions() as $key => $emotion) {
            $this->assertArrayHasKey('name', $emotion, "Emotion '$key' missing name");
            $this->assertArrayHasKey('name_ar', $emotion, "Emotion '$key' missing name_ar");
            $this->assertArrayHasKey('icon', $emotion, "Emotion '$key' missing icon");
        }
    }

    public function test_dialects_have_required_keys(): void
    {
        foreach (ArabicVoiceMapper::getDialects() as $code => $dialect) {
            $this->assertArrayHasKey('name', $dialect, "Dialect '$code' missing name");
            $this->assertArrayHasKey('name_ar', $dialect, "Dialect '$code' missing name_ar");
            $this->assertArrayHasKey('region', $dialect, "Dialect '$code' missing region");
        }
    }

    // ─── Region Grouping ──────────────────────────────────────

    public function test_dialects_by_region_groups_correctly(): void
    {
        $grouped = ArabicVoiceMapper::getDialectsByRegion();

        $this->assertArrayHasKey('gulf', $grouped);
        $this->assertArrayHasKey('levant', $grouped);
        $this->assertArrayHasKey('north_africa', $grouped);
        $this->assertArrayHasKey('maghreb', $grouped);
        $this->assertArrayHasKey('msa', $grouped);

        // Saudi in gulf
        $this->assertArrayHasKey('ar-SA', $grouped['gulf']);
        // Egyptian in north_africa
        $this->assertArrayHasKey('ar-EG', $grouped['north_africa']);
        // Lebanese in levant
        $this->assertArrayHasKey('ar-LB', $grouped['levant']);
        // Moroccan in maghreb
        $this->assertArrayHasKey('ar-MA', $grouped['maghreb']);
        // MSA
        $this->assertArrayHasKey('ar-XA', $grouped['msa']);
    }

    public function test_dialect_region_values_are_valid(): void
    {
        $validRegions = ['gulf', 'levant', 'north_africa', 'maghreb', 'msa'];

        foreach (ArabicVoiceMapper::getDialects() as $code => $dialect) {
            $this->assertContains(
                $dialect['region'],
                $validRegions,
                "Dialect '$code' has unexpected region '{$dialect['region']}'",
            );
        }
    }

    // ─── mapToProviderConfig – Google Cloud ───────────────────

    public function test_map_google_cloud_returns_correct_structure(): void
    {
        $config = $this->mapper->mapToProviderConfig('google_cloud', [
            'dialect' => 'ar-SA',
            'emotion' => 'neutral',
            'gender' => 'female',
        ]);

        $this->assertArrayHasKey('voice_name', $config);
        $this->assertArrayHasKey('style', $config);
        $this->assertArrayHasKey('speed', $config);
        $this->assertArrayHasKey('pitch', $config);
        $this->assertArrayHasKey('dialect', $config);
        $this->assertArrayHasKey('gender', $config);
        // Google Cloud doesn't support style prompts
        $this->assertNull($config['style']);
        $this->assertSame('FEMALE', $config['gender']);
    }

    public function test_map_google_cloud_excited_increases_speed_and_pitch(): void
    {
        $neutral = $this->mapper->mapToProviderConfig('google_cloud', [
            'dialect' => 'ar-XA',
            'emotion' => 'neutral',
            'gender' => 'male',
            'speed' => 1.0,
            'pitch' => 0.0,
        ]);

        $excited = $this->mapper->mapToProviderConfig('google_cloud', [
            'dialect' => 'ar-XA',
            'emotion' => 'excited',
            'gender' => 'male',
            'speed' => 1.0,
            'pitch' => 0.0,
        ]);

        $this->assertGreaterThan($neutral['speed'], $excited['speed']);
        $this->assertGreaterThan($neutral['pitch'], $excited['pitch']);
    }

    public function test_map_google_cloud_sad_decreases_speed_and_pitch(): void
    {
        $neutral = $this->mapper->mapToProviderConfig('google_cloud', [
            'dialect' => 'ar-XA',
            'emotion' => 'neutral',
            'gender' => 'female',
            'speed' => 1.0,
            'pitch' => 0.0,
        ]);

        $sad = $this->mapper->mapToProviderConfig('google_cloud', [
            'dialect' => 'ar-XA',
            'emotion' => 'sad',
            'gender' => 'female',
            'speed' => 1.0,
            'pitch' => 0.0,
        ]);

        $this->assertLessThan($neutral['speed'], $sad['speed']);
        $this->assertLessThan($neutral['pitch'], $sad['pitch']);
    }

    public function test_map_google_cloud_speed_clamps_within_bounds(): void
    {
        $config = $this->mapper->mapToProviderConfig('google_cloud', [
            'emotion' => 'excited',
            'speed' => 4.0, // high input * excited multiplier
        ]);

        // Google TTS max speed is 4.0
        $this->assertLessThanOrEqual(4.0, $config['speed']);
        $this->assertGreaterThanOrEqual(0.25, $config['speed']);
    }

    // ─── mapToProviderConfig – Gemini ─────────────────────────

    public function test_map_gemini_returns_correct_structure(): void
    {
        $config = $this->mapper->mapToProviderConfig('gemini', [
            'dialect' => 'ar-SA',
            'emotion' => 'warm',
            'gender' => 'female',
        ]);

        $this->assertArrayHasKey('voice_name', $config);
        $this->assertArrayHasKey('style', $config);
        $this->assertArrayHasKey('speed', $config);
        $this->assertArrayHasKey('pitch', $config);
        $this->assertArrayHasKey('dialect', $config);
        $this->assertArrayHasKey('gender', $config);
        // Gemini supports style
        $this->assertSame('warm', $config['style']);
    }

    public function test_map_gemini_female_defaults_to_kore(): void
    {
        $config = $this->mapper->mapToProviderConfig('gemini', [
            'dialect' => 'ar-SA',
            'emotion' => 'neutral',
            'gender' => 'female',
        ]);

        $this->assertSame('Kore', $config['voice_name']);
    }

    public function test_map_gemini_male_defaults_to_orus(): void
    {
        $config = $this->mapper->mapToProviderConfig('gemini', [
            'dialect' => 'ar-SA',
            'emotion' => 'neutral',
            'gender' => 'male',
        ]);

        $this->assertSame('Orus', $config['voice_name']);
    }

    public function test_map_gemini_explicit_voice_name_preserved(): void
    {
        $config = $this->mapper->mapToProviderConfig('gemini', [
            'dialect' => 'ar-SA',
            'emotion' => 'neutral',
            'gender' => 'male',
            'voice_name' => 'Zephyr',
        ]);

        $this->assertSame('Zephyr', $config['voice_name']);
    }

    // ─── Default Handling ─────────────────────────────────────

    public function test_map_defaults_when_no_intent_provided(): void
    {
        // No dialect, emotion, gender = should use defaults without error
        $config = $this->mapper->mapToProviderConfig('google_cloud', []);

        $this->assertArrayHasKey('speed', $config);
        $this->assertArrayHasKey('pitch', $config);
        $this->assertIsFloat($config['speed']);
        $this->assertIsFloat($config['pitch']);
    }

    public function test_unknown_emotion_returns_neutral_defaults(): void
    {
        $neutral = $this->mapper->mapToProviderConfig('google_cloud', [
            'emotion' => 'neutral',
            'speed' => 1.0,
            'pitch' => 0.0,
        ]);
        $unknown = $this->mapper->mapToProviderConfig('google_cloud', [
            'emotion' => 'nonexistent_emotion',
            'speed' => 1.0,
            'pitch' => 0.0,
        ]);

        $this->assertSame($neutral['speed'], $unknown['speed']);
        $this->assertSame($neutral['pitch'], $unknown['pitch']);
    }

    public function test_gender_is_uppercased(): void
    {
        $config = $this->mapper->mapToProviderConfig('gemini', [
            'gender' => 'male',
        ]);
        $this->assertSame('MALE', $config['gender']);

        $config2 = $this->mapper->mapToProviderConfig('google_cloud', [
            'gender' => 'Female',
        ]);
        $this->assertSame('FEMALE', $config2['gender']);
    }

    // ─── DIALECTS constant structure ──────────────────────────

    public function test_all_dialect_codes_start_with_ar(): void
    {
        foreach (ArabicVoiceMapper::getDialects() as $code => $dialect) {
            $this->assertStringStartsWith('ar-', $code, "Dialect code '$code' does not start with 'ar-'");
        }
    }
}
