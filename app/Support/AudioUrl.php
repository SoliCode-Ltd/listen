<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class AudioUrl
{
    public static function fromPath(?string $audioPath): ?string
    {
        $audioPath = ltrim(trim((string) $audioPath), '/');
        if ($audioPath === '') {
            return null;
        }

        $publicMirrorPath = public_path('storage/'.$audioPath);
        if (is_file($publicMirrorPath)) {
            return Storage::disk('public')->url($audioPath);
        }

        return route('media.file', ['path' => $audioPath]);
    }
}
