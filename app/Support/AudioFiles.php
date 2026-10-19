<?php

namespace App\Support;

use App\Models\AudioGeneration;
use App\Models\DemoVoiceGeneration;
use Illuminate\Support\Facades\Storage;

/**
 * The files of voices members delete. Anything on the public disk is served
 * to whoever has its address (MediaController), so a deleted voice's files go
 * with it, each unless another voice still plays it: the speech cache's copy
 * of a text is shared by everyone who made the same text the same way, and
 * TtsService makes it again when it is gone.
 */
final class AudioFiles
{
    /**
     * @param  array<int, string|null>  $paths
     */
    public static function deleteUnused(array $paths): void
    {
        $paths = array_unique(array_filter(array_map(fn (?string $path): string => trim((string) $path), $paths)));

        foreach ($paths as $path) {
            if (! AudioGeneration::query()->where('audio_path', $path)->exists()
                && ! DemoVoiceGeneration::query()->where('audio_path', $path)->exists()) {
                Storage::disk('public')->delete($path);
            }
        }
    }
}
