<?php

namespace App\Http\Controllers;

use App\Models\VideoDub;
use App\Services\Dubbing\DriveArchive;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * A dubbed video for the admin to watch (Video Dubbing → Dubs): the clean copy or the marked one,
 * from the server or the site's Google Drive.
 */
class AdminVideoDubFileController extends Controller
{
    public const FILES = ['clean' => 'dub.mp4', 'preview' => 'dub-preview.mp4'];

    public function __invoke(Request $request, int $dub, string $kind): Response
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
        $record = VideoDub::query()->findOrFail($dub);
        $path = $record->path(self::FILES[$kind] ?? abort(404));
        // A paid dub kept in the site's Google Drive (its clean copy only).
        if ($record->drive_file_id && $kind === 'clean' && $record->status !== VideoDub::REMOVED) {
            return app(DriveArchive::class)->stream($record->drive_file_id, $request->header('Range'), "dub-{$record->id}-clean.mp4", false);
        }
        abort_unless($record->files_deleted_at === null && Storage::disk('local')->exists($path), 404);

        $response = response()->file(Storage::disk('local')->path($path), ['Content-Type' => 'video/mp4', 'X-Content-Type-Options' => 'nosniff']);
        $response->setContentDisposition('inline', "dub-{$record->id}-{$kind}.mp4");
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, max-age=300');

        return $response;
    }
}
