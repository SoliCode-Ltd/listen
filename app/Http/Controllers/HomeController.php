<?php

namespace App\Http\Controllers;

use App\Services\TranslationService;
use App\Services\TtsService;
use App\Support\VoiceCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class HomeController extends Controller
{
    public function index()
    {
        return view('landing');
    }

    public function pricingPage()
    {
        return view('pricing');
    }

    public function demoPage()
    {
        return view('demo', [
            'voiceCatalog' => VoiceCatalog::publicCatalog(),
            'defaultVoiceAr' => VoiceCatalog::voiceMeta(VoiceCatalog::defaultForLanguage('ar')),
            'defaultVoiceEn' => VoiceCatalog::voiceMeta(VoiceCatalog::defaultForLanguage('en')),
        ]);
    }

    public function demo(Request $request)
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:8000'],
            'target_language' => ['required', 'in:ar,en'],
            'translate' => ['nullable', 'boolean'],
            'voice_key' => ['nullable', 'string', 'max:60'],
        ]);

        $voiceName = $this->normalizeVoiceSelection(
            $validated['target_language'],
            $validated['voice_key'] ?? null,
        );

        $text = trim($validated['text']);
        $sourceLanguage = preg_match('/[\x{0600}-\x{06FF}]/u', $text) ? 'ar' : 'en';

        $estimatedWords = max(
            str_word_count(strip_tags($text)),
            (int) ceil(mb_strlen($text) / 5)
        );
        $estimatedSeconds = max(3, (int) ceil($estimatedWords / 2.6));

        return response()->json([
            'ok' => true,
            'message' => 'Setup validated. You can generate voice now.',
            'source_language' => $sourceLanguage,
            'target_language' => $validated['target_language'],
            'translate' => (bool) ($validated['translate'] ?? false),
            'translate_note' => (bool) ($validated['translate'] ?? false)
                ? 'Text will be translated to the target language before audio generation.'
                : 'Text will be spoken as-is without translation.',
            'voice' => VoiceCatalog::voiceMeta($voiceName),
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
            'voice_key' => ['nullable', 'string', 'max:60'],
        ]);

        $voiceName = $this->normalizeVoiceSelection(
            $validated['target_language'],
            $validated['voice_key'] ?? null,
        );

        $text = trim($validated['text']);
        $sourceLanguage = preg_match('/[\x{0600}-\x{06FF}]/u', $text) ? 'ar' : 'en';

        $textForSpeech = $translationService->maybeTranslate(
            $text,
            $sourceLanguage,
            $validated['target_language'],
            (bool) ($validated['translate'] ?? false),
        );

        try {
            $audio = $ttsService->synthesize($textForSpeech, $validated['target_language'], $voiceName);

            return response()->json([
                'ok' => true,
                'source_language' => $sourceLanguage,
                'target_language' => $validated['target_language'],
                'translate' => (bool) ($validated['translate'] ?? false),
                'translate_note' => (bool) ($validated['translate'] ?? false)
                    ? 'Text was translated to the target language before audio generation.'
                    : 'Text was spoken as-is without translation.',
                'voice' => VoiceCatalog::voiceMeta($voiceName),
                'audio_url' => Storage::disk('public')->url($audio['audio_path']),
                'duration_seconds' => $audio['duration_seconds'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Voice generation failed. Please try again in a moment.',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 422);
        }
    }

    private function normalizeVoiceSelection(string $language, ?string $voiceKey): string
    {
        $voiceKey = trim((string) $voiceKey);

        if ($voiceKey === '') {
            return VoiceCatalog::defaultForLanguage($language);
        }

        $voiceName = VoiceCatalog::nameFromKeyForLanguage($language, $voiceKey);
        if (! $voiceName) {
            throw ValidationException::withMessages([
                'voice_key' => 'Selected voice is not available for the chosen language.',
            ]);
        }

        return $voiceName;
    }
}
