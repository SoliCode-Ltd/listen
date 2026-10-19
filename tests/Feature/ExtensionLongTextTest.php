<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The browser extension asks to wait for the audio only for a shorter text:
 * the server makes a long one after the reply (2,500 characters or more), and
 * the extension checks on it for as long as its length needs, instead of
 * giving up after about 30 seconds and saying "try again", which charged twice.
 */
class ExtensionLongTextTest extends TestCase
{
    public function test_only_a_shorter_text_asks_to_be_waited_for(): void
    {
        $js = $this->background();

        $this->assertStringContainsString('const LONG_TEXT_CHARS = '.(int) config('listen.audio.force_async_chars').';', $js, 'the server\'s own threshold');
        $this->assertStringContainsString('prefer_sync: Array.from(selectedText).length < LONG_TEXT_CHARS,', $js);
        $this->assertStringNotContainsString('prefer_sync: true', $js);
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
