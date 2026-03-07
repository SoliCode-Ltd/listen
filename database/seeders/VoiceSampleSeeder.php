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
