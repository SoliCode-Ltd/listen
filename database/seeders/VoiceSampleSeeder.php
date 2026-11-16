<?php

namespace Database\Seeders;

use App\Models\VoiceSample;
use Illuminate\Database\Seeder;

class VoiceSampleSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<int, array<string, mixed>> $records */
        $records = require __DIR__ . '/data/voice_samples.php';

        foreach ($records as $record) {
            $record['show_in_hero'] = $record['show_in_hero'] ?? (bool) ($record['is_featured'] ?? false);
            $record['show_in_showcase'] = $record['show_in_showcase'] ?? true;

            VoiceSample::updateOrCreate(
                [
                    'title' => $record['title'],
                    'category' => $record['category'],
                    'language' => $record['language'],
                ],
                $record
            );
        }
    }
}
