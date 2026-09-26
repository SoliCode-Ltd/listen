<?php

namespace App\Http\Controllers;

use App\Models\ClonedVoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminClonedVoiceAudioController extends Controller
{
    public function __invoke(Request $request, int $voice, string $kind)
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
        abort_unless(in_array($kind, ['sample', 'consent'], true), 404);

        $clonedVoice = ClonedVoice::withTrashed()->findOrFail($voice);
        $path = trim((string) ($kind === 'sample' ? $clonedVoice->source_audio_path : $clonedVoice->consent_audio_path));
        abort_if($path === '' || ! Storage::disk('local')->exists($path), 404);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'wav' => 'audio/wav',
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'webm' => 'audio/webm',
            'flac' => 'audio/flac',
            'm4a', 'mp4' => 'audio/mp4',
            default => 'application/octet-stream',
        };

        return Storage::disk('local')->response($path, "voice-{$clonedVoice->id}-{$kind}.{$extension}", [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
