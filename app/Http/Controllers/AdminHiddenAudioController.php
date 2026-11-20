<?php

namespace App\Http\Controllers;

use App\Models\AudioGeneration;
use App\Support\HiddenAudio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A voice hidden from its member (App\Support\HiddenAudio), played by an admin: its
 * file is on the private disk, so only this address serves it, to admins alone.
 */
class AdminHiddenAudioController extends Controller
{
    public function __invoke(Request $request, int $generation): BinaryFileResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        $record = AudioGeneration::query()->findOrFail($generation);
        $path = HiddenAudio::isHidden($record) ? HiddenAudio::privatePath($record) : null;
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $response = response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => match ($extension) {
                'wav' => 'audio/wav',
                'mp3' => 'audio/mpeg',
                'ogg' => 'audio/ogg',
                default => 'application/octet-stream',
            },
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setContentDisposition('inline', "hidden-voice-{$record->id}.{$extension}");
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
