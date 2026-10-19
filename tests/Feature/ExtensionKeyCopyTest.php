<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The browser extension key is kept as a hash and its last four characters,
 * so the full key can be copied only on the extension page, right after it is
 * made. The dashboard shows the masked key and says how to get a full one,
 * instead of a Copy button that copied "lk_live_****1234".
 */
class ExtensionKeyCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_shows_the_masked_key_and_no_copy_button(): void
    {
        $member = User::factory()->create(['subscription_status_checked_at' => now()]);
        $plain = $member->issueApiKey();

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $hint = __('ui.dashboard.extension_key_copy_hint', ['button' => __('ui.dashboard.manage_extension_key', [], $locale)], $locale);

            $this->actingAs($member)->getLocalizedRoute('dashboard')
                ->assertOk()
                ->assertSee('<span dir="ltr" id="extensionKeyValue">lk_live_****'.substr($plain, -4).'</span>', false)
                ->assertSee($hint)
                ->assertDontSee('copyExtensionKeyBtn', false)
                ->assertDontSee('data-copy-value=', false)
                ->assertDontSee($plain, false);
        }

        $this->assertStringContainsString('Manage Extension Key', __('ui.dashboard.extension_key_copy_hint', ['button' => __('ui.dashboard.manage_extension_key', [], 'en')], 'en'));
    }

    public function test_the_dashboard_says_nothing_about_copying_without_a_key(): void
    {
        $this->actingAs(User::factory()->create(['subscription_status_checked_at' => now()]))
            ->getLocalizedRoute('dashboard')
            ->assertOk()
            ->assertSee(__('ui.dashboard.extension_key_not_created'))
            ->assertDontSee('<p class="mini-message key-copy-hint">', false);
    }

    public function test_the_extension_page_copies_only_a_key_just_made(): void
    {
        $member = User::factory()->create(['subscription_status_checked_at' => now()]);
        $member->issueApiKey();

        // Copy sits in the box that shows a new key, and copies what that box holds.
        $this->actingAs($member)->getLocalizedRoute('browsers-extension.page')
            ->assertOk()
            ->assertSee('var initialNewKey = null;', false)
            ->assertSeeInOrder(['id="newKeyBox"', 'id="newKeyValue"', 'id="copyKeyBtn"'], false)
            ->assertSee("var raw = String(newKeyValue.textContent || '').trim();", false);

        $plain = $member->issueApiKey();
        $this->actingAs($member)->withSession(['new_api_key' => $plain])
            ->getLocalizedRoute('browsers-extension.page')
            ->assertOk()
            ->assertSee('var initialNewKey = '.json_encode($plain).';', false);
    }
}
