<?php

namespace App\Support;

use App\Models\AudioGeneration;
use App\Models\DemoVoiceGeneration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * A voice an admin hid from the member who made it (Audio Generations → Hide from member).
 *
 * The member no longer finds it anywhere: not in their history, the developer API, the
 * extension or an AI app, and its address stops playing, because its file leaves the
 * public disk (anything there is served to whoever has the address). Nothing is lost:
 * the row, the text, what it cost and the file itself stay for the admin, the file on
 * the private disk, in case the voice is ever disputed. Credits are not given back.
 *
 * Showing it again puts the file back where it was.
 */
final class HiddenAudio
{
    /** On the private disk: hidden-audio/{generation id}/{file name}. */
    public const DIRECTORY = 'hidden-audio';

    private static ?bool $ready = null;

    /**
     * The columns are there (the migration is run by hand after a deploy). Until then
     * nothing is hidden and every member's list reads as before.
     */
    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasColumn('audio_generations', 'hidden_at');
    }

    /** For the tests, which add and remove the columns. */
    public static function forgetReady(): void
    {
        self::$ready = null;
    }

    /**
     * Only the voices a member may see: every query that lists, plays, downloads or
     * hands out a member's voices passes through this.
     *
     * @template TBuilder of Builder|\Illuminate\Database\Query\Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function visible($query, string $table = 'audio_generations')
    {
        return self::ready() ? $query->whereNull($table.'.hidden_at') : $query;
    }

    public static function isHidden(?AudioGeneration $generation): bool
    {
        return $generation !== null && self::ready() && $generation->hidden_at !== null;
    }

    public static function hide(AudioGeneration $generation, ?int $adminId = null, ?string $note = null): bool
    {
        if (! self::ready() || $generation->hidden_at !== null) {
            return false;
        }

        // Kept first, then taken off the public disk: a failure in between leaves the file
        // where it was rather than losing it.
        $kept = self::keepPrivately($generation);

        $generation->forceFill([
            'hidden_at' => now(),
            'hidden_by' => $adminId,
            'hidden_note' => filled($note) ? mb_substr(trim((string) $note), 0, 255) : null,
        ])->saveQuietly();

        $path = self::path($generation);
        $cacheCopy = trim((string) (($generation->settings_snapshot ?? [])['cache_copy'] ?? ''));
        foreach (array_unique(array_filter([$kept ? $path : null, $cacheCopy])) as $public) {
            self::removeFromPublic($public);
        }

        return true;
    }

    public static function show(AudioGeneration $generation): bool
    {
        if (! self::ready() || $generation->hidden_at === null) {
            return false;
        }

        $path = self::path($generation);
        $private = self::privatePath($generation);
        if ($path !== null && $private !== null && Storage::disk('local')->exists($private)) {
            if (! Storage::disk('public')->exists($path)) {
                Storage::disk('public')->put($path, Storage::disk('local')->readStream($private));
            }
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('local')->deleteDirectory(self::DIRECTORY.'/'.$generation->id);
            }
        }

        $generation->forceFill(['hidden_at' => null, 'hidden_by' => null, 'hidden_note' => null])->saveQuietly();

        return true;
    }

    /** The kept file of a voice whose row was deleted for good. */
    public static function forgetFile(AudioGeneration $generation): void
    {
        if ($generation->getKey() && self::ready() && $generation->hidden_at !== null) {
            Storage::disk('local')->deleteDirectory(self::DIRECTORY.'/'.$generation->getKey());
        }
    }

    /** Where a hidden voice's file is kept on the private disk, or null when it has no file. */
    public static function privatePath(AudioGeneration $generation): ?string
    {
        $path = self::path($generation);

        return $path === null ? null : self::DIRECTORY.'/'.$generation->id.'/'.basename($path);
    }

    private static function path(AudioGeneration $generation): ?string
    {
        $path = ltrim(str_replace('\\', '/', trim((string) $generation->audio_path)), '/');

        return $path === '' || str_contains($path, '..') ? null : $path;
    }

    private static function keepPrivately(AudioGeneration $generation): bool
    {
        $path = self::path($generation);
        $private = self::privatePath($generation);
        if ($path === null || $private === null || ! Storage::disk('public')->exists($path)) {
            return false;
        }
        if (Storage::disk('local')->exists($private)) {
            return true;
        }

        $stream = Storage::disk('public')->readStream($path);
        if (! is_resource($stream)) {
            return false;
        }
        try {
            return (bool) Storage::disk('local')->put($private, $stream) && Storage::disk('local')->exists($private);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * A file leaves the public disk unless a voice the member (or another member, or the
     * demo) can still see plays the same file: the speech cache's copy of a text is shared.
     */
    private static function removeFromPublic(string $path): void
    {
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..')) {
            return;
        }
        if (self::visible(AudioGeneration::query())->where('audio_path', $path)->exists()
            || DemoVoiceGeneration::query()->where('audio_path', $path)->exists()) {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}
