<?php

namespace App\Http\Controllers;

use App\Models\AudioGeneration;
use App\Models\DemoVoiceGeneration;
use App\Services\PlanAccessService;
use App\Support\AudioFormats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Downloads a member's own audio in a file type the browser cannot make
 * (M4A, OGG, FLAC), when their plan includes that type.
 */
class AudioDownloadController extends Controller
{
    public function __invoke(Request $request, PlanAccessService $plans)
    {
        $format = strtolower(trim((string) $request->query('format', '')));
        $path = ltrim(str_replace('\\', '/', trim((string) $request->query('path', ''))), '/');
        // The player passes the /media/... address it plays.
        $path = preg_replace('#^media/#', '', $path) ?? $path;
        $userId = (int) $request->user()->id;

        if (! AudioFormats::isServerFormat($format) || $path === '' || str_contains($path, '..')) {
            abort(404);
        }
        if (! in_array($format, AudioFormats::availableFor($plans->resolveActivePlanForUserId($userId)), true)) {
            abort(403);
        }

        $owned = AudioGeneration::query()->where('user_id', $userId)->where('audio_path', $path)->exists()
            || DemoVoiceGeneration::query()->where('user_id', $userId)->where('audio_path', $path)->exists();
        $disk = Storage::disk('public');
        if (! $owned || ! $disk->exists($path)) {
            abort(404);
        }

        $bytes = AudioFormats::convert($disk->path($path), $format);
        if ($bytes === null) {
            abort(503);
        }

        $name = (preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $request->query('name', '')) ?: 'listen-audio').'.'.$format;

        return response($bytes, 200, [
            'Content-Type' => AudioFormats::ALL[$format]['mime'],
            'Content-Disposition' => 'attachment; filename="'.trim($name, '-').'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
