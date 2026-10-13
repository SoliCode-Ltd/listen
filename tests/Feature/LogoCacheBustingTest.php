<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Logo links carry the file's change time, so an updated logo is fetched
 * again instead of an old copy staying in browsers and caches.
 */
class LogoCacheBustingTest extends TestCase
{
    use RefreshDatabase;

    public function test_logos_are_linked_with_their_version(): void
    {
        $page = $this->getLocalizedRoute('pricing')->assertOk();
        foreach (['logo-dark.svg', 'logo-light.svg'] as $file) {
            $page->assertSee('images/'.$file.'?v='.filemtime(public_path('images/'.$file)), false);
        }

        $this->assertStringEndsWith('images/logo-ar-dark.svg?v='.filemtime(public_path('images/logo-ar-dark.svg')), \App\Support\VersionedAsset::url('images/logo-ar-dark.svg'));
    }

    public function test_no_template_links_a_logo_without_its_version(): void
    {
        $unversioned = [];
        foreach (\Illuminate\Support\Facades\File::allFiles(resource_path('views')) as $file) {
            if (preg_match("/asset\\('images\\/logo/", $file->getContents())) {
                $unversioned[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $unversioned);
    }

    public function test_the_arabic_logos_have_no_blank_strip(): void
    {
        foreach (['logo-ar-dark.svg', 'logo-ar-light.svg'] as $file) {
            $this->assertStringContainsString('viewBox="0 0 307 128"', file_get_contents(public_path('images/'.$file)), $file);
        }
    }
}
