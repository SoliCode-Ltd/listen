<?php

namespace App\Http\Controllers;

use App\Services\TranslationService;
use App\Services\TtsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class HomeController extends Controller
{
    public function index()
    {
        return view('landing');
    }

    public function demo(Request $request)
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:8000'],
            'target_language' => ['required', 'in:ar,en'],
            'translate' => ['nullable', 'boolean'],
        ]);

        $text = trim($validated['text']);
        $sourceLanguage = preg_match('/[\x{0600}-\x{06FF}]/u', $text) ? 'ar' : 'en';

        $estimatedWords = max(
            str_word_count(strip_tags($text)),
            (int) ceil(mb_strlen($text) / 5)
        );
        $estimatedSeconds = max(3, (int) ceil($estimatedWords / 2.6));

        return response()->json([
            'ok' => true,
            'message' => 'Preview is ready. Click "Generate Voice" to synthesize real audio.',
            'source_language' => $sourceLanguage,
            'target_language' => $validated['target_language'],
            'translate' => (bool) ($validated['translate'] ?? false),
            'estimated_seconds' => $estimatedSeconds,
            'estimated_minutes' => max(1, (int) ceil($estimatedSeconds / 60)),
        ]);
    }

    public function preview(
        Request $request,
        TranslationService $translationService,
        TtsService $ttsService,
    ) {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:1200'],
            'target_language' => ['required', 'in:ar,en'],
            'translate' => ['nullable', 'boolean'],
        ]);

        $text = trim($validated['text']);
        $sourceLanguage = preg_match('/[\x{0600}-\x{06FF}]/u', $text) ? 'ar' : 'en';

        $textForSpeech = $translationService->maybeTranslate(
            $text,
            $sourceLanguage,
            $validated['target_language'],
            (bool) ($validated['translate'] ?? false),
        );

        try {
            $audio = $ttsService->synthesize($textForSpeech, $validated['target_language']);

            return response()->json([
                'ok' => true,
                'provider' => $audio['provider'] ?? 'google-cloud-tts',
                'source_language' => $sourceLanguage,
                'target_language' => $validated['target_language'],
                'audio_url' => Storage::disk('public')->url($audio['audio_path']),
                'duration_seconds' => $audio['duration_seconds'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Voice generation failed. Please verify Google TTS credentials and try again.',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 422);
        }
    }
}
