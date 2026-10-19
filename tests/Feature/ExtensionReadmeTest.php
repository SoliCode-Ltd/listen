<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The README in the extension's package names the right-click menu item as
 * the extension shows it, promises only controls the side player has (there
 * is no skip), and says the API key is stored locally, which is true now that
 * the key stays in this browser.
 */
class ExtensionReadmeTest extends TestCase
{
    public function test_the_readme_names_the_menu_and_the_controls_as_they_are(): void
    {
        $readme = $this->extensionFile('README.txt');
        $menu = json_decode($this->extensionFile('_locales/en/messages.json'), true)['contextMenuTitle']['message'];

        $this->assertSame('Listen to the selected text', $menu);
        $this->assertSame(3, substr_count($readme, '"'.$menu.'"'), 'right-click, the permission, and setup');
        foreach (['README.txt', 'background.js', 'privacy_policy.html'] as $file) {
            $this->assertStringNotContainsString('Listen Selected Text', $this->extensionFile($file), $file);
        }

        $this->assertStringContainsString('playback controls (play, pause, speed)', $readme);
        $this->assertStringNotContainsStringIgnoringCase('skip', $readme);
        $player = $this->extensionFile('content.js');
        foreach (['data-role="play"', "pt('pause', 'Pause')", 'data-role="speed"'] as $control) {
            $this->assertStringContainsString($control, $player);
        }

        $this->assertStringContainsString('your API key is stored locally', $readme);
        $this->assertStringNotContainsString('chrome.storage.sync.set', $this->extensionFile('background.js'), 'and it is');
    }

    private function extensionFile(string $name): string
    {
        return (string) file_get_contents(public_path('extensions/chrome-listen-reader/'.$name));
    }
}
