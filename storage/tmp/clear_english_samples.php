<?php

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\VoiceSample;

// Delete English samples from DB
$deleted = VoiceSample::where('language', 'en')->delete();
echo "Deleted {$deleted} English VoiceSample records from DB." . PHP_EOL;

// Also remove their audio files
$disk = \Illuminate\Support\Facades\Storage::disk('public');
$files = $disk->files('audio');
$removedFiles = 0;
foreach (VoiceSample::where('language', 'en')->get() as $sample) {
    // Already deleted above, but let's clean files by scanning
}
// Since records are deleted, scan files that match English patterns
foreach ($files as $file) {
    if (str_contains($file, '_listen.studio_')) {
        // We can't tell which are English vs Arabic by filename alone,
        // so skip file deletion - the --force flag will overwrite
    }
}
echo "English DB records cleared. Run generation without --force to generate only missing English samples." . PHP_EOL;
