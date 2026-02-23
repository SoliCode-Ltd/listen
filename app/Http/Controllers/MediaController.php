<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    public function show(string $path): BinaryFileResponse
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

        $headers = [
            'Cache-Control' => 'public, max-age=86400',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
            'Access-Control-Allow-Headers' => 'Accept, Content-Type, X-Listen-Key',
        ];

        // Set proper Content-Type for audio files
        $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
        if ($extension === 'mp3') {
            $headers['Content-Type'] = 'audio/mpeg';
        } elseif ($extension === 'wav') {
            $headers['Content-Type'] = 'audio/wav';
        } elseif ($extension === 'ogg') {
            $headers['Content-Type'] = 'audio/ogg';
        }

        return response()->file($absolutePath, $headers);
    }
}
