<?php

namespace App\Support;

use Dotenv\Exception\InvalidFileException;
use Dotenv\Parser\Parser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Changing the .env file safely. One line phpdotenv cannot read stops every
 * page from loading, the admin included, until someone fixes it over SSH.
 */
final class EnvFile
{
    // How many copies of the old file are kept (with the deploy's own, in storage/app/listen-deploy).
    private const KEEP_BACKUPS = 10;

    /**
     * A value written so phpdotenv reads back exactly this text. Quoted when
     * it has spaces or special characters, or starts with a quote; inside
     * double quotes a backslash starts an escape (a lone one made the whole
     * file unreadable), so backslashes and quotes are escaped there.
     */
    public static function value(string $value): string
    {
        // One line per variable: a pasted line break must not start a new one.
        $value = str_replace(["\r", "\n"], '', $value);

        if ($value === '' || preg_match('/[\s#=]|^["\']/', $value)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }

    /**
     * The value phpdotenv reads from one KEY=value line (references like
     * ${APP_NAME} left as written), or null when the line cannot be read alone.
     */
    public static function read(string $line): ?string
    {
        try {
            $entry = (new Parser)->parse($line)[0] ?? null;
        } catch (InvalidFileException) {
            return null;
        }

        $value = $entry?->getValue();

        return $value !== null && $value->isDefined() ? $value->get()->getChars() : null;
    }

    /**
     * Replaces the file with $content. Refused, with nothing written, unless
     * phpdotenv can read all of it. The old file is copied first (chmod 600),
     * and the new one goes to a temporary file that is renamed into place, so
     * a failed write never leaves half a file.
     *
     * @return string|null where the old file was kept, or null when no copy could be made
     */
    public static function write(string $path, string $content): ?string
    {
        try {
            (new Parser)->parse($content);
        } catch (InvalidFileException) {
            throw new RuntimeException('The .env file would not load with this change, so nothing was saved.');
        }

        $backup = self::backup($path);
        self::replace($path, $content);

        return $backup;
    }

    private static function backup(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $dir = storage_path('app/listen-deploy');
        $stamp = now()->utc()->format('YmdHis');

        try {
            File::ensureDirectoryExists($dir);
            $backup = $dir.'/env.backup-'.$stamp;
            for ($n = 2; file_exists($backup); $n++) {
                $backup = $dir.'/env.backup-'.$stamp.'-'.$n;
            }

            // Private before the secrets are copied in.
            touch($backup);
            chmod($backup, 0600);
            if (! copy($path, $backup)) {
                throw new RuntimeException('copy failed');
            }
        } catch (\Throwable $e) {
            Log::warning('Could not keep a copy of .env before changing it.', ['error' => $e->getMessage()]);

            return null;
        }

        $copies = glob($dir.'/env.backup-*') ?: [];
        sort($copies);
        foreach (array_slice($copies, 0, -self::KEEP_BACKUPS) as $old) {
            @unlink($old);
        }

        return $backup;
    }

    private static function replace(string $path, string $content): void
    {
        // Through a symlinked .env to the file itself, keeping its permissions.
        $target = realpath($path) ?: $path;
        $mode = is_file($target) ? fileperms($target) & 0777 : 0644;
        $temp = dirname($target).'/.env.saving-'.Str::random(8);

        try {
            if (file_put_contents($temp, $content) === strlen($content) && chmod($temp, $mode) && rename($temp, $target)) {
                return;
            }
        } catch (\Throwable) {
            // Some hosts let PHP change .env but not add a file next to it.
        } finally {
            if (file_exists($temp)) {
                @unlink($temp);
            }
        }

        if (file_put_contents($target, $content, LOCK_EX) !== strlen($content)) {
            throw new RuntimeException('The .env file could not be written.');
        }
    }
}
