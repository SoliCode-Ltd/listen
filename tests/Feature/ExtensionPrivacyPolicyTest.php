<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The extension's privacy page says only what is true: the text a member
 * chooses to hear goes to Listen and its speech and translation providers
 * (named by no one), so it no longer says nothing is shared; the audio is sent
 * back, not streamed; and the API key and settings are stored locally, as
 * they now are.
 */
class ExtensionPrivacyPolicyTest extends TestCase
{
    public function test_the_privacy_page_says_what_happens_to_the_text_and_the_key(): void
    {
        $page = preg_replace('/\s+/', ' ', (string) file_get_contents(public_path('extensions/chrome-listen-reader/privacy_policy.html')));

        $this->assertStringContainsString('<li>We do not sell your data. The text you choose to hear is sent to Listen, whose speech and translation providers process it only to make your audio.</li>', $page);
        $this->assertStringNotContainsString('share your data with third parties', $page);
        $this->assertStringContainsString('The generated audio is sent back to the extension.', $page);
        $this->assertStringNotContainsString('streamed', $page);
        $this->assertDoesNotMatchRegularExpression('/gemini|wavenet|google|openai|elevenlabs/i', $page, 'no provider is named');

        $this->assertStringContainsString('<strong>API key:</strong> Stored locally in Chrome storage', $page);
        $this->assertStringNotContainsString('chrome.storage.sync.set', (string) file_get_contents(public_path('extensions/chrome-listen-reader/background.js')), 'and it is');
    }
}
