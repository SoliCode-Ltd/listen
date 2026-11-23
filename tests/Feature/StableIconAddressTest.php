<?php

namespace Tests\Feature;

use App\Support\SeoHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The site icon's address changes only when the icon itself does. Every deploy uploads every file
 * again; when the address carried the file's date it changed each time, and Google, which wants a
 * site icon's address to stay the same, kept showing its old copy of the icon.
 */
class StableIconAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_upload_of_the_same_icon_keeps_its_address_and_a_new_icon_gets_a_new_one(): void
    {
        $file = public_path('favicon.png');
        $original = file_get_contents($file);
        $mtime = filemtime($file);

        try {
            $before = SeoHelper::versionedPrimaryFaviconUrl();
            $this->assertMatchesRegularExpression('#/favicon\.png\?v=\d+$#', $before);

            // Uploaded again by a deploy: the same picture with a new date.
            touch($file, $mtime + 86400);
            clearstatcache();
            $this->assertSame($before, SeoHelper::versionedPrimaryFaviconUrl());

            // A different icon.
            file_put_contents($file, $original.'changed');
            clearstatcache();
            $this->assertNotSame($before, SeoHelper::versionedPrimaryFaviconUrl());
        } finally {
            file_put_contents($file, $original);
            touch($file, $mtime);
            clearstatcache();
        }
    }
}
