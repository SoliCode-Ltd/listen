<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    public function show(string $path): Response
    {
        $normalized = ltrim(str_replace('\\', '/', trim($path)), '/');
        if ($normalized === '' || str_contains($normalized, '..')) {
            abort(404);
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($normalized)) {
            abort(404);
        }

        $absolutePath = $disk->path($normalized);
        if (! is_file($absolutePath)) {
            abort(404);
        }

        return response()->file($absolutePath, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
