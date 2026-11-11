<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * The admin's stylesheet (public/css/filament/admin/theme.css) is Filament's own plus
 * the classes the admin's pages use, compiled from resources/css/filament/admin/theme.css
 * by scripts/build-admin-theme.mjs. It is committed, as the servers have no build step,
 * so it must be rebuilt whenever an admin file or Filament changes: these tests say when.
 */
class AdminThemeTest extends TestCase
{
    public function test_the_admin_theme_is_built_from_the_admin_files_as_they_are(): void
    {
        $built = public_path('css/filament/admin/theme.css');
        $this->assertFileExists($built);

        $this->assertSame(
            '/* listen-admin-theme sources:'.self::fingerprint().' */',
            strtok((string) file_get_contents($built), "\n"),
            'An admin file (app/Filament, resources/views/filament) or Filament changed since the admin theme was built: run npm install && npm run build:admin-theme',
        );
    }

    public function test_the_admin_uses_its_theme(): void
    {
        $this->assertStringEndsWith('css/filament/admin/theme.css', (string) Filament::getPanel('admin')->getTheme()->getPath());
    }

    /** As scripts/build-admin-theme.mjs works it out. */
    private static function fingerprint(): string
    {
        $paths = ['resources/css/filament/admin/theme.css'];
        foreach (['app/Filament', 'resources/views/filament'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $paths[] = $dir.'/'.str_replace('\\', '/', substr($file->getPathname(), strlen(base_path($dir)) + 1));
            }
        }
        sort($paths, SORT_STRING);

        $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true);
        $filament = collect(array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []))->firstWhere('name', 'filament/filament')['version'] ?? '';

        $text = "filament:{$filament}\n";
        foreach ($paths as $path) {
            $text .= $path."\n".hash_file('sha256', base_path($path))."\n";
        }

        return hash('sha256', $text);
    }
}
