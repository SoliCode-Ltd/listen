<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The extension's API key and settings stay on this computer, as the site, the
 * README and the privacy page say: they are kept in chrome.storage.local, never
 * chrome.storage.sync, which Chrome keeps in the member's account and copies to
 * every browser they sign in to. What an older version kept there is moved here
 * on the first run and taken out of the account.
 */
class ExtensionKeyStaysLocalTest extends TestCase
{
    public function test_the_key_and_the_settings_are_kept_in_this_browser_only(): void
    {
        $js = $this->extensionFile('background.js');

        $this->assertStringContainsString('chrome.storage.local.get(DEFAULT_SETTINGS, (stored) => {', $js);
        $this->assertStringContainsString('chrome.storage.local.set(payload, () => resolve(payload));', $js);
        $this->assertStringNotContainsString('chrome.storage.sync.set', $js, 'nothing is written to the account, the key least of all');
        foreach (['popup.js', 'content.js', 'player.js'] as $file) {
            $this->assertStringNotContainsString('storage.sync', $this->extensionFile($file), $file);
        }
    }

    public function test_what_an_older_version_synced_is_moved_here_once_and_taken_out_of_the_account(): void
    {
        $js = $this->extensionFile('background.js');

        $this->assertStringContainsString("const SETTINGS_MOVED_KEY = 'listen_settings_moved_from_sync';", $js);
        $this->assertStringContainsString("const synced = await chrome.storage.sync.get(keys);", $js);
        $copied = strpos($js, 'await chrome.storage.local.set({ ...synced, ...kept });');
        $removed = strpos($js, 'await chrome.storage.sync.remove(found);');
        $this->assertNotFalse($copied, 'copied here, without overwriting what this browser keeps');
        $this->assertNotFalse($removed, 'and taken out of the account');
        $this->assertLessThan($removed, $copied, 'only once it is copied');
        $this->assertStringContainsString('await chrome.storage.local.set({ [SETTINGS_MOVED_KEY]: true });', $js);
        // Before any setting is read, so the first read after the update already has the key.
        $this->assertStringContainsString("async function getSettings() {\n  await moveSettingsFromSync();", $js);
    }

    private function extensionFile(string $name): string
    {
        return (string) file_get_contents(public_path('extensions/chrome-listen-reader/'.$name));
    }
}
