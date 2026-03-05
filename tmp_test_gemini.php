<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Test a single Gemini TTS call to see if quota has reset
$provider = app(App\Services\Tts\GeminiTtsProvider::class);

echo 'Gemini available: ' . ($provider->isAvailable() ? 'YES' : 'NO') . PHP_EOL;

try {
    $result = $provider->synthesize(
        'This is a test of the Gemini TTS system.',
        'en',
        [
            'voice_name' => 'Achird',
            'style' => 'conversational',
            'style_prompt' => 'Speak naturally.',
            'tier' => 'hd',
            'speed' => 1.0,
            'pitch' => 0.0,
        ]
    );
    echo 'SUCCESS! Provider: ' . $result['provider'] . ', Model: ' . $result['model'] . PHP_EOL;
    echo 'Audio: ' . $result['audio_path'] . PHP_EOL;
    // Clean up test file
    $path = storage_path('app/public/' . $result['audio_path']);
    if (file_exists($path)) {
        unlink($path);
        echo 'Cleaned up test audio.' . PHP_EOL;
    }
} catch (\Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
}
