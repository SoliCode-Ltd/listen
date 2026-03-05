<?php

namespace Database\Seeders;

use App\Models\SiteSetting;
use App\Models\Voice;
use Illuminate\Database\Seeder;

/**
 * Seeds default Gemini TTS voices and hybrid-mode site settings.
 *
 * Run: php artisan db:seed --class=HybridTtsSeeder
 *
 * Voice display names are unique branded names — they NEVER expose provider
 * model names (Kore, Puck, etc.) to the end user.
 */
class HybridTtsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedTtsSettings();
        $this->seedGeminiVoices();

        $this->command->info('Hybrid TTS defaults seeded successfully.');
    }

    private function seedTtsSettings(): void
    {
        $defaults = [
            ['key' => 'tts_mode', 'value' => 'hybrid', 'group' => 'tts'],
            ['key' => 'tts_fallback_enabled', 'value' => '1', 'group' => 'tts'],
            ['key' => 'gemini_default_voice', 'value' => 'Kore', 'group' => 'tts'],
            ['key' => 'gemini_timeout_seconds', 'value' => '30', 'group' => 'tts'],
            ['key' => 'daily_gemini_char_limit', 'value' => '5000000', 'group' => 'tts'],
            ['key' => 'daily_total_char_limit', 'value' => '10000000', 'group' => 'tts'],
            ['key' => 'daily_gemini_request_limit', 'value' => '10000', 'group' => 'tts'],
        ];

        foreach ($defaults as $item) {
            SiteSetting::updateOrCreate(
                ['key' => $item['key']],
                ['value' => $item['value'], 'group' => $item['group']]
            );
        }
    }

    private function seedGeminiVoices(): void
    {
        /*
        |----------------------------------------------------------------------
        | Gemini Voice Mapping
        |----------------------------------------------------------------------
        | Each voice gets a unique branded display name in English + Arabic.
        | The internal_voice_id is our own slug — never shown to users.
        | The provider_voice_id is the actual Gemini model voice name.
        |
        | Naming convention:
        |   display_name  → user-facing (unique, branded)
        |   internal_voice_id → our slug (gemini-ar-xxx / gemini-en-xxx)
        |   provider_voice_id → Gemini API voice (Kore, Puck, etc.)
        |----------------------------------------------------------------------
        */

        $geminiVoices = [
            // ── Arabic Gemini Voices ─────────────────────────────────────
            [
                'display_name' => 'Rawaa',      // رواء
                'internal_voice_id' => 'gemini-ar-rawaa',
                'provider_voice_id' => 'Kore',
                'language' => 'ar',
                'gender' => 'female',
                'tier' => 'hd',
                'provider' => 'gemini',
                'dialect' => 'ar-XA',
                'sort_order' => 100,
                'is_featured' => true,
                'supported_emotions' => ['neutral', 'warm', 'formal', 'news', 'storytelling', 'calm'],
                'metadata' => ['personality' => 'Confident & clear', 'name_ar' => 'رواء'],
            ],
            [
                'display_name' => 'Basem',       // باسم
                'internal_voice_id' => 'gemini-ar-basem',
                'provider_voice_id' => 'Puck',
                'language' => 'ar',
                'gender' => 'male',
                'tier' => 'hd',
                'provider' => 'gemini',
                'dialect' => 'ar-XA',
                'sort_order' => 101,
                'is_featured' => true,
                'supported_emotions' => ['neutral', 'excited', 'ad_style', 'storytelling', 'warm'],
                'metadata' => ['personality' => 'Energetic & engaging', 'name_ar' => 'باسم'],
            ],
            [
                'display_name' => 'Ghada',       // غادة
                'internal_voice_id' => 'gemini-ar-ghada',
                'provider_voice_id' => 'Aoede',
                'language' => 'ar',
                'gender' => 'female',
                'tier' => 'hd',
                'provider' => 'gemini',
                'dialect' => 'ar-XA',
                'sort_order' => 102,
                'is_featured' => false,
                'supported_emotions' => ['neutral', 'warm', 'calm', 'storytelling', 'sad'],
                'metadata' => ['personality' => 'Gentle & soothing', 'name_ar' => 'غادة'],
            ],
            [
                'display_name' => 'Faris',       // فارس
                'internal_voice_id' => 'gemini-ar-faris',
                'provider_voice_id' => 'Charon',
                'language' => 'ar',
                'gender' => 'male',
                'tier' => 'hd',
                'provider' => 'gemini',
                'dialect' => 'ar-XA',
                'sort_order' => 103,
                'is_featured' => false,
                'supported_emotions' => ['neutral', 'formal', 'news', 'angry'],
                'metadata' => ['personality' => 'Authoritative & steady', 'name_ar' => 'فارس'],
            ],
            [
                'display_name' => 'Souad',       // سعاد
                'internal_voice_id' => 'gemini-ar-souad',
                'provider_voice_id' => 'Leda',
                'language' => 'ar',
                'gender' => 'female',
                'tier' => 'hd',
                'provider' => 'gemini',
                'dialect' => 'ar-XA',
                'sort_order' => 104,
                'is_featured' => false,
                'supported_emotions' => ['neutral', 'warm', 'excited', 'storytelling'],
                'metadata' => ['personality' => 'Youthful & warm', 'name_ar' => 'سعاد'],
            ],
            [
                'display_name' => 'Tariq',       // طارق
                'internal_voice_id' => 'gemini-ar-tariq',
                'provider_voice_id' => 'Orus',
                'language' => 'ar',
                'gender' => 'male',
                'tier' => 'hd',
                'provider' => 'gemini',
                'dialect' => 'ar-XA',
                'sort_order' => 105,
                'is_featured' => false,
                'supported_emotions' => ['neutral', 'formal', 'news', 'calm'],
                'metadata' => ['personality' => 'Professional & composed', 'name_ar' => 'طارق'],
            ],
            [
                'display_name' => 'Lamis',       // لميس
                'internal_voice_id' => 'gemini-ar-lamis',
                'provider_voice_id' => 'Zephyr',
                'language' => 'ar',
                'gender' => 'female',
                'tier' => 'hd',
                'provider' => 'gemini',
                'dialect' => 'ar-XA',
                'sort_order' => 106,
                'is_featured' => false,
                'supported_emotions' => ['neutral', 'warm', 'calm', 'storytelling'],
                'metadata' => ['personality' => 'Bright & cheerful', 'name_ar' => 'لميس'],
            ],
            [
                'display_name' => 'Walid',       // وليد
                'internal_voice_id' => 'gemini-ar-walid',
                'provider_voice_id' => 'Fenrir',
                'language' => 'ar',
                'gender' => 'male',
                'tier' => 'hd',
                'provider' => 'gemini',
                'dialect' => 'ar-XA',
                'sort_order' => 107,
                'is_featured' => false,
                'supported_emotions' => ['neutral', 'excited', 'ad_style', 'angry'],
                'metadata' => ['personality' => 'Dynamic & bold', 'name_ar' => 'وليد'],
            ],

            // ── English Gemini Voices ─────────────────────────────────────
            [
                'display_name' => 'Serene',
                'internal_voice_id' => 'gemini-en-serene',
                'provider_voice_id' => 'Kore',
                'language' => 'en',
                'gender' => 'female',
                'tier' => 'hd',
                'provider' => 'gemini',
                'sort_order' => 200,
                'is_featured' => true,
                'supported_emotions' => ['neutral', 'warm', 'formal', 'news', 'storytelling', 'calm'],
                'metadata' => ['personality' => 'Clear & professional'],
            ],
            [
                'display_name' => 'Felix',
                'internal_voice_id' => 'gemini-en-felix',
                'provider_voice_id' => 'Puck',
                'language' => 'en',
                'gender' => 'male',
                'tier' => 'hd',
                'provider' => 'gemini',
                'sort_order' => 201,
                'is_featured' => true,
                'supported_emotions' => ['neutral', 'excited', 'ad_style', 'storytelling', 'warm'],
                'metadata' => ['personality' => 'Upbeat & friendly'],
            ],
            [
                'display_name' => 'Aurora',
                'internal_voice_id' => 'gemini-en-aurora',
                'provider_voice_id' => 'Aoede',
                'language' => 'en',
                'gender' => 'female',
                'tier' => 'hd',
                'provider' => 'gemini',
                'sort_order' => 202,
                'is_featured' => false,
                'supported_emotions' => ['neutral', 'warm', 'calm', 'storytelling', 'sad'],
                'metadata' => ['personality' => 'Mellow & inviting'],
            ],
            [
                'display_name' => 'Marcus',
                'internal_voice_id' => 'gemini-en-marcus',
                'provider_voice_id' => 'Charon',
                'language' => 'en',
                'gender' => 'male',
                'tier' => 'hd',
                'provider' => 'gemini',
                'sort_order' => 203,
                'is_featured' => false,
                'supported_emotions' => ['neutral', 'formal', 'news', 'angry'],
                'metadata' => ['personality' => 'Authoritative & deep'],
            ],
            [
                'display_name' => 'Ivy',
                'internal_voice_id' => 'gemini-en-ivy',
                'provider_voice_id' => 'Leda',
                'language' => 'en',
                'gender' => 'female',
                'tier' => 'hd',
                'provider' => 'gemini',
                'sort_order' => 204,
                'is_featured' => false,
                'supported_emotions' => ['neutral', 'warm', 'excited', 'storytelling'],
                'metadata' => ['personality' => 'Youthful & light'],
            ],
            [
                'display_name' => 'Sterling',
                'internal_voice_id' => 'gemini-en-sterling',
                'provider_voice_id' => 'Orus',
                'language' => 'en',
                'gender' => 'male',
                'tier' => 'hd',
                'provider' => 'gemini',
                'sort_order' => 205,
                'is_featured' => false,
                'supported_emotions' => ['neutral', 'formal', 'news', 'calm'],
                'metadata' => ['personality' => 'Polished & commanding'],
            ],
            [
                'display_name' => 'Willow',
                'internal_voice_id' => 'gemini-en-willow',
                'provider_voice_id' => 'Zephyr',
                'language' => 'en',
                'gender' => 'female',
                'tier' => 'hd',
                'provider' => 'gemini',
                'sort_order' => 206,
                'is_featured' => false,
                'supported_emotions' => ['neutral', 'warm', 'calm', 'storytelling'],
                'metadata' => ['personality' => 'Bright & breezy'],
            ],
            [
                'display_name' => 'Blaze',
                'internal_voice_id' => 'gemini-en-blaze',
                'provider_voice_id' => 'Fenrir',
                'language' => 'en',
                'gender' => 'male',
                'tier' => 'hd',
                'provider' => 'gemini',
                'sort_order' => 207,
                'is_featured' => false,
                'supported_emotions' => ['neutral', 'excited', 'ad_style', 'angry'],
                'metadata' => ['personality' => 'Intense & dynamic'],
            ],
        ];

        foreach ($geminiVoices as $voiceData) {
            Voice::updateOrCreate(
                ['internal_voice_id' => $voiceData['internal_voice_id']],
                array_merge($voiceData, ['is_active' => true])
            );
        }

        // Deactivate old Gemini voices that used raw provider names
        $oldSlugs = [
            'Zephyr', 'Puck', 'Charon', 'Kore', 'Fenrir', 'Leda', 'Orus', 'Aoede',
            'Callirrhoe', 'Autonoe',
            'Kore-ar', 'Puck-ar', 'Aoede-ar', 'Charon-ar',
        ];
        Voice::query()
            ->whereIn('internal_voice_id', $oldSlugs)
            ->update(['is_active' => false]);
    }
}
