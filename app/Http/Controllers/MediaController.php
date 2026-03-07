<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    public function show(Request $request, string $path)
    {
        $normalized = ltrim(str_replace('\\', '/', trim($path)), '/');
        if ($normalized === '' || str_contains($normalized, '..')) {
            abort(404);
        }

        $absolutePath = $this->resolveAbsolutePath($normalized);
        if (! is_file($absolutePath)) {
            abort(404);
        }

        $fileSize = filesize($absolutePath);
        if (! is_int($fileSize) || $fileSize <= 0) {
            abort(404);
        }

        $headers = [
            'Cache-Control' => 'public, max-age=86400',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
            'Access-Control-Allow-Headers' => 'Accept, Content-Type, X-Listen-Key',
            'Accept-Ranges' => 'bytes',
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

        $rangeHeader = trim((string) $request->headers->get('Range', ''));
        if ($rangeHeader !== '' && preg_match('/bytes=(\d+)-(\d*)/i', $rangeHeader, $matches) === 1) {
            $start = (int) $matches[1];
            $end = $matches[2] === '' ? ($fileSize - 1) : (int) $matches[2];

            if ($start >= $fileSize || $start > $end) {
                return response('', Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE, [
                    'Content-Range' => 'bytes */'.$fileSize,
                ] + $headers);
            }

            $end = min($end, $fileSize - 1);
            $length = ($end - $start) + 1;

            return response()->stream(function () use ($absolutePath, $start, $end): void {
                $handle = fopen($absolutePath, 'rb');
                if ($handle === false) {
                    return;
                }

                try {
                    fseek($handle, $start);
                    $remaining = ($end - $start) + 1;
                    $chunkSize = 1024 * 64;

                    while ($remaining > 0 && ! feof($handle)) {
                        $read = fread($handle, (int) min($chunkSize, $remaining));
                        if ($read === false || $read === '') {
                            break;
                        }

                        echo $read;
                        flush();
                        $remaining -= strlen($read);
                    }
                } finally {
                    fclose($handle);
                }
            }, Response::HTTP_PARTIAL_CONTENT, $headers + [
                'Content-Length' => (string) $length,
                'Content-Range' => 'bytes '.$start.'-'.$end.'/'.$fileSize,
            ]);
        }

        $headers['Content-Length'] = (string) $fileSize;

        return response()->file($absolutePath, $headers);
    }

    private function resolveAbsolutePath(string $normalized): ?string
    {
        $disk = Storage::disk('public');
        if ($disk->exists($normalized)) {
            return $disk->path($normalized);
        }

        $publicPath = public_path($normalized);
        if (is_file($publicPath)) {
            return $publicPath;
        }

        return null;
    }
}
