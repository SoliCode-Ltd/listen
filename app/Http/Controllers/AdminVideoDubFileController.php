<?php

namespace App\Http\Controllers;

use App\Models\VideoDub;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A dubbed video for the admin to watch (Video Dubbing → Dubs): the clean copy or the marked one.
 */
class AdminVideoDubFileController extends Controller
{
    public const FILES = ['clean' => 'dub.mp4', 'preview' => 'dub-preview.mp4'];

    public function __invoke(Request $request, int $dub, string $kind): BinaryFileResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
        $record = VideoDub::query()->findOrFail($dub);
        $path = $record->path(self::FILES[$kind] ?? abort(404));
        abort_unless($record->files_deleted_at === null && Storage::disk('local')->exists($path), 404);

        $response = response()->file(Storage::disk('local')->path($path), ['Content-Type' => 'video/mp4', 'X-Content-Type-Options' => 'nosniff']);
        $response->setContentDisposition('inline', "dub-{$record->id}-{$kind}.mp4");
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, max-age=300');

        return $response;
    }
}
