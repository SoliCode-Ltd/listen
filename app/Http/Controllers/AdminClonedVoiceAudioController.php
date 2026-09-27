<?php

namespace App\Http\Controllers;

use App\Models\ClonedVoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminClonedVoiceAudioController extends Controller
{
    public function __invoke(Request $request, int $voice, string $kind): BinaryFileResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
        abort_unless(in_array($kind, ['sample', 'consent'], true), 404);

        $clonedVoice = ClonedVoice::withTrashed()->findOrFail($voice);
        $path = trim((string) ($kind === 'sample' ? $clonedVoice->source_audio_path : $clonedVoice->consent_audio_path));
        $absolute = $this->absolutePath($path);
        abort_if($absolute === null, 404);

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

        $response = response()->file($absolute, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setContentDisposition('inline', "voice-{$clonedVoice->id}-{$kind}.{$extension}");
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, max-age=300');

        return $response;
    }

    /**
     * Recordings are stored on the local disk. Older files may still sit in storage/app.
     */
    private function absolutePath(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            return null;
        }

        if (Storage::disk('local')->exists($path)) {
            $absolute = Storage::disk('local')->path($path);

            return is_file($absolute) ? $absolute : null;
        }

        $legacy = storage_path('app/'.$path);
        $root = realpath(storage_path('app'));
        $resolved = realpath($legacy);
        if ($root === false || $resolved === false || ! is_file($resolved)) {
            return null;
        }

        if ($resolved !== $root && ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $resolved;
    }
}
