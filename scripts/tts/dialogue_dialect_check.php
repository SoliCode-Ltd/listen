<?php

/*
 * One real two-dialect dialogue through the app's own dialogue code: a Saudi
 * (Najdi) speaker and an Egyptian (Cairene) speaker. Saves the recording to
 * dialogue-check/ and asks a text model which dialect each line is in.
 * Run by .github/workflows/dialogue-dialect-check.yml (one speech request).
 */

use App\Services\Tts\GeminiTtsProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$script = implode("\n", [
    'سلطان: هلا والله! وش رايك نبدأ الحلقة اليوم بسالفة حلوة؟',
    'سمية: أهلاً يا سلطان! أنا جاهزة خالص، النهارده عندنا كلام حلو أوي.',
    'سلطان: زين، علمينا وش اللي صاير عندكم في مصر هالأيام؟',
    'سمية: والله الدنيا حلوة، والناس كلها بتتكلم عن إسمع!',
]);

$result = app(GeminiTtsProvider::class)->synthesize($script, 'ar', [
    'tier' => 'hd',
    'provider' => 'gemini',
    'performance_style' => 'conversational',
    'emotion' => 'warm',
    'speed' => 1.0,
    'pitch' => 0,
    'speakers' => [
        ['name' => 'سلطان', 'voice_name' => 'Algenib', 'gender' => 'male', 'dialect' => 'ar-SA'],
        ['name' => 'سمية', 'voice_name' => 'Callirrhoe', 'gender' => 'female', 'dialect' => 'ar-EG'],
    ],
]);

$bytes = Storage::disk('public')->get($result['audio_path']);
@mkdir($root.'/dialogue-check', 0777, true);
$ext = pathinfo($result['audio_path'], PATHINFO_EXTENSION);
file_put_contents($root.'/dialogue-check/saudi-egyptian-dialogue.'.$ext, $bytes);
echo 'Recording: '.strlen($bytes)." bytes ({$ext})\n";

$mime = $ext === 'mp3' ? 'audio/mpeg' : 'audio/wav';
$question = 'This is a two-person Arabic dialogue. For each spoken line, in order, write: the line as heard, '
    .'whether the speaker is a man or a woman, and which Arabic dialect that line is spoken in (be specific, e.g. Saudi Najdi, Egyptian Cairene). '
    .'Then say in one sentence whether the two speakers clearly use two different dialects.';
$response = Http::timeout(180)
    ->withHeaders(['x-goog-api-key' => (string) config('services.gemini_tts.api_key')])
    ->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent', [
        'contents' => [['role' => 'user', 'parts' => [
            ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
            ['text' => $question],
        ]]],
    ]);
echo "Transcription check:\n".collect($response->json('candidates.0.content.parts') ?? [])->pluck('text')->implode('')."\n";
