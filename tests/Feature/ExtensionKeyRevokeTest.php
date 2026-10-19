<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Revoking the extension key stops the extension at once, so the page asks
 * first, in the page's language; a revoke that is confirmed works as before.
 */
class ExtensionKeyRevokeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_revoking_the_key_is_asked_first_in_the_pages_language(): void
    {
        $user = User::factory()->create();
        $user->issueApiKey();

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $html = $this->actingAs($user)->getLocalizedRoute('browsers-extension.page')->assertOk()->getContent();

            $this->assertStringContainsString("async function revokeKey() {\n      // The extension stops working at once, so never on a stray click.\n      if (!window.confirm(i18n.revoke_confirm)) return;\n      revokeBtn.disabled = true;", $html);
            $this->assertStringContainsString(
                substr(json_encode(trans('ui.extension.revoke_confirm', [], $locale), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), 1, -1),
                $html,
            );
        }
        $this->assertNotSame(trans('ui.extension.revoke_confirm', [], 'en'), trans('ui.extension.revoke_confirm', [], 'ar'));
    }

    public function test_a_confirmed_revoke_still_revokes_the_key(): void
    {
        $user = User::factory()->create();
        $user->issueApiKey();

        $this->actingAs($user)->postJsonLocalizedRoute('browsers-extension.key.revoke')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('api_state.has_api_key', false);
        $this->assertNull($user->fresh()->api_key_hash);
    }
}
