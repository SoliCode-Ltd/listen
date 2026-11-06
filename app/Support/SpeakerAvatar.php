<?php

namespace App\Support;

/**
 * The Listen speaker avatars: one illustrated portrait per speaker, in one
 * brand style, at public/images/speakers/{language}-{name}.webp.
 * Made by `php artisan speakers:avatars`.
 */
final class SpeakerAvatar
{
    public const DIR = 'images/speakers';

    /** @var array<string, int>|null */
    private static ?array $files = null;

    public static function slug(string $name, string $language): string
    {
        $language = str_starts_with(strtolower(trim($language)), 'ar') ? 'ar' : 'en';
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', trim($name)));

        return $language.'-'.trim($slug, '-');
    }

    public static function path(string $name, string $language): string
    {
        return self::DIR.'/'.self::slug($name, $language).'.webp';
    }

    /**
     * Public URL of the speaker's avatar, or null when there is none.
     */
    public static function url(?string $name, ?string $language): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $slug = self::slug($name, (string) $language);
        if (! isset(self::files()[$slug])) {
            return null;
        }

        // Its version is the file's time, so an avatar made again shows at once, never the old one cached.
        return asset(self::DIR.'/'.$slug.'.webp').'?v='.self::files()[$slug];
    }

    /**
     * The avatar's address by its file name ("ar-maha"), with the file's time as its version.
     */
    public static function bySlug(string $slug): string
    {
        return asset(self::DIR.'/'.$slug.'.webp').'?v='.(self::files()[$slug] ?? 1);
    }

    /**
     * @return array<string, int>
     */
    private static function files(): array
    {
        if (self::$files === null) {
            self::$files = [];
            foreach (glob(public_path(self::DIR.'/*.webp')) ?: [] as $file) {
                self::$files[basename($file, '.webp')] = (int) @filemtime($file);
            }
        }

        return self::$files;
    }

    public static function forget(): void
    {
        self::$files = null;
    }
}
