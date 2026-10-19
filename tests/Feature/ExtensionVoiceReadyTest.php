<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * With "Play voice in side panel" off, the popup said "Voice generated in side
 * panel." although nothing opened, and gave no way to play the voice. It now
 * says the voice is ready, with a link that plays it in the extension's own
 * player in a new tab, in English and Arabic. With the side panel on it says,
 * as before, that the voice is in the side panel.
 */
class ExtensionVoiceReadyTest extends TestCase
{
    public function test_with_the_side_panel_off_the_popup_says_the_voice_is_ready_and_how_to_play_it(): void
    {
        $popup = $this->extensionFile('popup.js');

        $this->assertSame(2, substr_count($popup, 'showVoiceReady(response.result, overrides.autoPlay);'), 'the selection and the quick text');
        $this->assertSame(1, substr_count($popup, "t('statusGenerated'"), 'said only when the side panel was on');
        $this->assertStringContainsString("  if (panelOn) {\n    setStatus(t('statusGenerated', 'Voice generated in side panel.'), 'ok');\n    return;\n  }\n\n  setStatus(t('statusReady', 'Your voice is ready.'), 'ok');", $popup);
        $this->assertStringContainsString('setLink(elements.playerLink, chrome.runtime.getURL(`player.html?${new URLSearchParams({ audio: audioUrl })}`));', $popup);
        $this->assertStringContainsString("  // The link to a voice goes with the message that it is ready.\n  setLink(elements.playerLink, '');", $popup, 'any other message hides it');
        $this->assertStringContainsString("const audioUrl = params.get('audio') || '';", $this->extensionFile('player.js'), 'the address the player reads');

        $this->assertStringContainsString(
            '<a id="playerLink" class="btn primary player-link" href="#" target="_blank" rel="noopener" hidden data-i18n="openPlayer">',
            $this->extensionFile('popup.html'),
        );

        foreach (['en', 'ar'] as $lang) {
            $this->assertNotSame('', $this->message($lang, 'statusReady'), "statusReady in {$lang}");
            $this->assertNotSame('', $this->message($lang, 'openPlayer'), "openPlayer in {$lang}");
        }
        $this->assertStringNotContainsString('side panel', $this->message('en', 'statusReady'));
        $this->assertStringNotContainsString('اللوحة الجانبية', $this->message('ar', 'statusReady'));
    }

    private function extensionFile(string $name): string
    {
        return (string) file_get_contents(public_path('extensions/chrome-listen-reader/'.$name));
    }

    private function message(string $lang, string $key): string
    {
        $messages = json_decode($this->extensionFile("_locales/{$lang}/messages.json"), true);

        return (string) ($messages[$key]['message'] ?? '');
    }
}
