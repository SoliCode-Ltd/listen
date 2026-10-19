<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use App\Support\ExtensionVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class ExtensionPackageTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function downloadedManifest(): array
    {
        $response = $this->actingAs(User::factory()->create())->getLocalizedRoute('browsers-extension.download');
        $response->assertOk();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($response->getFile()->getPathname()) === true);
        $manifest = json_decode((string) $zip->getFromName('listen-chrome-extension/manifest.json'), true);
        $zip->close();

        return is_array($manifest) ? $manifest : [];
    }

    public function test_the_package_keeps_the_translated_name_and_never_ships_an_older_version(): void
    {
        // An admin value from before the update is older than the source.
        SiteSetting::set('extension_chrome_version', '1.0.0', 'extension');
        SiteSetting::set('extension_chrome_name', 'Listen Reader', 'extension');

        $manifest = $this->downloadedManifest();

        $this->assertSame(ExtensionVersion::source(), $manifest['version'] ?? null);
        $this->assertTrue(version_compare(ExtensionVersion::source(), '1.1.0', '>='));
        // Above the 1.2.1 going to the store before it: the store refuses a version it has had.
        $this->assertTrue(version_compare(ExtensionVersion::source(), '1.2.1', '>'));
        $this->assertSame('__MSG_extName__', $manifest['name'] ?? null);
        $this->assertSame('__MSG_extDescription__', $manifest['description'] ?? null);
    }

    public function test_an_admin_can_still_set_a_newer_version_and_their_own_name(): void
    {
        SiteSetting::set('extension_chrome_version', '9.2.0', 'extension');
        SiteSetting::set('extension_chrome_name', 'My Reader', 'extension');

        $manifest = $this->downloadedManifest();

        $this->assertSame('9.2.0', $manifest['version'] ?? null);
        $this->assertSame('My Reader', $manifest['name'] ?? null);
        $this->getJson('/api/extension/config')->assertJsonPath('config.version', '9.2.0');
    }

    public function test_the_extension_speaks_credits_not_characters(): void
    {
        foreach (['en', 'ar'] as $lang) {
            $messages = json_decode((string) file_get_contents(public_path("extensions/chrome-listen-reader/_locales/{$lang}/messages.json")), true);
            $this->assertIsArray($messages);
            // The allowance is credits; "characters" only describes the length of a text.
            $this->assertStringNotContainsStringIgnoringCase('character', $messages['charactersLabel']['message']);
            $this->assertStringNotContainsString('حرف', $messages['charactersLabel']['message']);
            $this->assertStringNotContainsString('HD', $messages['studioLabel']['message']);
        }

        $css = (string) file_get_contents(public_path('extensions/chrome-listen-reader/popup.css'));
        $this->assertStringNotContainsString('fonts.googleapis.com', $css, 'no third-party requests from the popup');
    }
}
