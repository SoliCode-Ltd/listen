<?php

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\VoiceSample;

$count = VoiceSample::count();
VoiceSample::query()->delete();
echo "Deleted {$count} VoiceSample records from DB." . PHP_EOL;

// Also remove old audio files
$disk = \Illuminate\Support\Facades\Storage::disk('public');
$files = $disk->files('audio');
$deleted = 0;
foreach ($files as $file) {
    if (str_contains($file, '_gemini_') || str_contains($file, '_listen.studio_')) {
        $disk->delete($file);
        $deleted++;
    }
}
echo "Deleted {$deleted} audio files from disk." . PHP_EOL;
