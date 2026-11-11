<?php

namespace Tests\Feature;

use App\Providers\Filament\AdminPanelProvider;
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

    public function test_sections_and_popups_are_never_wider_than_the_screen(): void
    {
        // Filament sizes their one column by its content: on a phone the dashboard's cards and
        // the "who cancelled" popup were wider than the screen, cut off on the right.
        $css = (string) file_get_contents(public_path('css/filament/admin/theme.css'));

        $this->assertStringContainsString(
            '.fi-section.fi-section-not-contained:not(.fi-aside),.fi-section.fi-section-not-contained:not(.fi-aside):not(.fi-collapsed)>.fi-section-content-ctn,.fi-modal-window-ctn{grid-template-columns:minmax(0,1fr)}',
            $css,
        );
    }

    public function test_the_admin_uses_its_theme(): void
    {
        $this->assertStringEndsWith('css/filament/admin/theme.css?v='.substr(self::fingerprint(), 0, 12), (string) Filament::getPanel('admin')->getTheme()->getPath());
    }

    public function test_the_themes_address_changes_with_its_content(): void
    {
        // Cloudflare and browsers keep it for a year: a rebuilt theme must have a new address.
        $this->assertSame(substr(self::fingerprint(), 0, 12), AdminPanelProvider::themeVersion());
        $this->get('/admin/login')->assertOk()->assertSee('css/filament/admin/theme.css?v='.AdminPanelProvider::themeVersion(), false);
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
