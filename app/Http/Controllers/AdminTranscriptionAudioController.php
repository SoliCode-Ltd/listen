<?php

namespace App\Http\Controllers;

use App\Models\Transcription;
use App\Services\Transcription\TranscriptionFlow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * A transcript's recording (the clean copy the model heard) for the admin to play
 * (Speech to text → Transcripts → a transcript). Admins only; it stays private on
 * Listen's disk, including for a transcript the member deleted. Played from any point.
 */
class AdminTranscriptionAudioController extends Controller
{
    public function __invoke(Request $request, string $code): Response
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
        $t = Transcription::withTrashed()->where('code', $code)->firstOrFail();
        $disk = Storage::disk(TranscriptionFlow::DISK);
        abort_if($t->audio_deleted_at !== null || ! $t->audio_path || ! $disk->exists((string) $t->audio_path), 404);

        $response = response()->file($disk->path((string) $t->audio_path), ['Content-Type' => 'audio/mpeg', 'X-Content-Type-Options' => 'nosniff']);
        $response->setContentDisposition('inline', $t->code.'.mp3');
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, max-age=300');

        return $response;
    }
}
