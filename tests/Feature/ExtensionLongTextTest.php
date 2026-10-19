<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The browser extension asks to wait for the audio only for a short text
 * (under 800 characters): the server makes a longer one after the reply, and
 * the extension checks on it for as long as its length needs, instead of
 * giving up after about 30 seconds and saying "try again", which charged twice.
 */
class ExtensionLongTextTest extends TestCase
{
    public function test_only_a_short_text_asks_to_be_waited_for(): void
    {
        $js = $this->background();

        // Below about 800 characters, well under the server's own threshold: Chrome may
        // stop the extension while one request takes over 30 seconds.
        $this->assertStringContainsString('const WAIT_IN_REQUEST_CHARS = 800;', $js);
        $this->assertLessThan((int) config('listen.audio.force_async_chars'), 800);
        $this->assertStringContainsString('prefer_sync: Array.from(selectedText).length < WAIT_IN_REQUEST_CHARS,', $js);
        $this->assertStringNotContainsString('prefer_sync: true', $js);
        $this->assertStringNotContainsString('LONG_TEXT_CHARS', $js);
    }

    public function test_a_long_text_is_checked_on_for_as_long_as_its_length_needs(): void
    {
        $js = $this->background();

        // A minute, and a second for every 40 characters, at most 15 minutes.
        $this->assertStringContainsString('return Math.min(15 * 60 * 1000, 60 * 1000 + Math.ceil(chars / 40) * 1000);', $js);
        $this->assertStringContainsString('const readyJob = await pollJobUntilReady(apiBase, apiKey, data.job_id, pollTimeoutMsFor(selectedText));', $js);
        $this->assertStringContainsString('for (let i = 0; Date.now() - startedAt < timeoutMs; i += 1) {', $js);
        // A busy server is waited out, and the service worker kept awake meanwhile.
        $this->assertStringContainsString('if (response.status === 429 || response.status >= 500) {', $js);
        $this->assertStringContainsString('chrome.runtime.getPlatformInfo(() => void chrome.runtime.lastError);', $js);
        // When it stops waiting it never says "try again": the voice may still come.
        $this->assertStringNotContainsString("bgText('errTooLong'", $js);
        $this->assertStringContainsString("throw new Error(bgText('errQueueTimeout', 'Request timed out. The voice may still be generating - check your history.'));", $js);
        foreach (['en', 'ar'] as $lang) {
            $messages = json_decode((string) file_get_contents(public_path("extensions/chrome-listen-reader/_locales/{$lang}/messages.json")), true);
            $this->assertNotSame('', trim((string) ($messages['errQueueTimeout']['message'] ?? '')), "errQueueTimeout in {$lang}");
        }
    }

    private function background(): string
    {
        return (string) file_get_contents(public_path('extensions/chrome-listen-reader/background.js'));
    }
}
