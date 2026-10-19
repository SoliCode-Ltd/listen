<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * Each extension download is built in a folder of its own: two at once used
 * to share one build folder and one zip, so one download could delete or
 * overwrite the files another was still zipping or sending. The folder goes
 * once the zip is made, and the zip once it has been sent.
 */
class ExtensionDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_download_is_built_on_its_own_and_cleaned_up(): void
    {
        $user = User::factory()->create();
        $foldersBefore = glob(storage_path('app/extensions/build/*'), GLOB_ONLYDIR) ?: [];

        $first = $this->actingAs($user)->getLocalizedRoute('browsers-extension.download')->assertOk();
        $second = $this->actingAs($user)->getLocalizedRoute('browsers-extension.download')->assertOk();
        $firstZip = $first->baseResponse->getFile()->getPathname();
        $secondZip = $second->baseResponse->getFile()->getPathname();

        $this->assertNotSame($firstZip, $secondZip, 'two downloads never share a zip');
        $this->assertSame('__MSG_extName__', $this->manifestIn($firstZip)['name'] ?? null, 'the first is still whole after the second was built');
        $this->assertSame('__MSG_extName__', $this->manifestIn($secondZip)['name'] ?? null);
        $this->assertSame($foldersBefore, glob(storage_path('app/extensions/build/*'), GLOB_ONLYDIR) ?: [], 'no build folder is left behind');

        foreach ([[$first, $firstZip], [$second, $secondZip]] as [$response, $zip]) {
            ob_start();
            $response->baseResponse->sendContent();
            $sent = (string) ob_get_clean();

            $this->assertStringStartsWith("PK\x03\x04", $sent, 'the zip was sent');
            $this->assertFileDoesNotExist($zip, 'and removed once sent');
        }
    }

    /** @return array<string, mixed> */
    private function manifestIn(string $zipPath): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $manifest = json_decode((string) $zip->getFromName('listen-chrome-extension/manifest.json'), true);
        $zip->close();

        return is_array($manifest) ? $manifest : [];
    }
}
