<?php

namespace Database\Seeders;

use App\Console\Commands\GenerateVoiceSamples;
use App\Models\VoiceSample;
use Illuminate\Database\Seeder;

/**
 * Seeds voice-sample metadata (no audio generation).
 *
 * The single source of truth for sample data lives in
 * {@see GenerateVoiceSamples::SAMPLES}.
 */
class VoiceSampleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (GenerateVoiceSamples::SAMPLES as $index => $sample) {
            $text = $sample['language'] === 'ar' ? $sample['text_ar'] : $sample['text'];

            VoiceSample::updateOrCreate(
                [
                    'title'    => $sample['title'],
                    'category' => $sample['category'],
                    'language' => $sample['language'],
                ],
                [
                    'title_ar'         => $sample['title_ar'],
                    'description'      => $sample['description'] ?? null,
                    'description_ar'   => $sample['description_ar'] ?? null,
                    'icon'             => $sample['icon'] ?? null,
                    'sample_text'      => $text,
                    'sample_text_ar'   => $sample['text_ar'],
                    'audio_path'       => 'audio/' . $sample['language'] . '.mp3',
                    'language'         => $sample['language'],
                    'dialect'          => $sample['dialect'],
                    'country'          => $sample['country'],
                    'emotion'          => $sample['emotion'],
                    'gender'           => $sample['gender'],
                    'voice_name'       => $sample['google_voice'],
                    'provider'         => 'gemini',
                    'category'         => $sample['category'],
                    'sort_order'       => $index + 1,
                    'is_featured'      => (bool) $sample['is_featured'],
                    'is_active'        => true,
                    'duration_seconds' => 0,
                    'metadata'         => null,
                ],
            );
        }
    }
}
