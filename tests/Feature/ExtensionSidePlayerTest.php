<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The side player opens in the page, and no extension can run in Chrome's own
 * pages or the Web Store. The extension made (and charged) the voice first and
 * only then said it could not open the player. With "Play voice in side panel"
 * on, it now checks the page before asking for the voice, and stops with a
 * clear message; a voice made that still cannot play in the page opens in the
 * extension's own player in a new tab, and the last resort says where the
 * voice is: the dashboard history.
 */
class ExtensionSidePlayerTest extends TestCase
{
    public function test_the_page_is_checked_before_the_voice_is_made_and_charged(): void
    {
        $js = $this->extensionFile('background.js');

        $check = strpos($js, "if (settings.autoPlay && !(await ensureContentScriptReady(playbackTabId))) {\n    throw new Error(bgText('errSidePlayerPage',");
        $post = strpos($js, 'response = await fetch(`${apiBase}/api/reader/queue`, {');
        $this->assertNotFalse($check);
        $this->assertNotFalse($post);
        $this->assertLessThan($post, $check, 'before the voice is asked for');
        $this->assertStringContainsString('nothing was made or charged', $this->message('en', 'errSidePlayerPage'));
        $this->assertStringContainsString('لم يُخصم أي رصيد', $this->message('ar', 'errSidePlayerPage'));
        // It names the switch as the popup does.
        $this->assertStringContainsString($this->message('en', 'autoPlayLabel'), $this->message('en', 'errSidePlayerPage'));
        $this->assertStringContainsString($this->message('ar', 'autoPlayLabel'), $this->message('ar', 'errSidePlayerPage'));
    }

    public function test_a_voice_the_page_will_not_play_opens_in_the_extensions_own_player(): void
    {
        $js = $this->extensionFile('background.js');

        $this->assertSame(2, substr_count($js, 'await playVoice('), 'the voice made in the request, and the one checked on');
        $this->assertSame(1, substr_count($js, 'await openSidePlayer('), 'the side player only through playVoice');
        $this->assertStringContainsString('const opened = await chrome.tabs.create({ url: playerPageUrl(audioUrl) }).catch(() => null);', $js);
        $this->assertStringContainsString('return chrome.runtime.getURL(`player.html?${new URLSearchParams({ audio: audioUrl })}`);', $js);
        $this->assertStringContainsString("const audioUrl = params.get('audio') || '';", $this->extensionFile('player.js'), 'the address the player reads');

        // When even that cannot open, the member is told where the voice is.
        $this->assertStringContainsString('dashboard history', $this->message('en', 'errSidePlayer'));
        $this->assertStringContainsString('سجل الأصوات', $this->message('ar', 'errSidePlayer'));
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
