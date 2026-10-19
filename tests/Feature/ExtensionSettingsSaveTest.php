<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Saving the extension's settings keeps the service address: the popup never
 * sends it, and the save used to start from the packaged defaults, so every
 * save undid a move the server had made (refreshRemoteConfig).
 */
class ExtensionSettingsSaveTest extends TestCase
{
    public function test_a_save_is_merged_with_the_stored_settings(): void
    {
        $js = (string) file_get_contents(public_path('extensions/chrome-listen-reader/background.js'));

        $this->assertStringContainsString("async function saveSettings(settings) {\n  // Merged with what is stored", $js);
        $this->assertStringContainsString("  const payload = {\n    ...(await getSettings()),\n    ...settings,\n  };", $js);
        $this->assertStringNotContainsString("  const payload = {\n    ...DEFAULT_SETTINGS,\n    ...settings,\n  };", $js, 'not from the packaged defaults');
        // Both callers wait for it.
        $this->assertStringContainsString('const saved = await saveSettings(message.settings || {});', $js);
        $this->assertStringContainsString('await saveSettings({ ...settings, apiBase: remoteApiBase });', $js);
        // The popup still sends no address of its own.
        $popup = (string) file_get_contents(public_path('extensions/chrome-listen-reader/popup.js'));
        $this->assertStringNotContainsString('apiBase', $popup);
    }
}
