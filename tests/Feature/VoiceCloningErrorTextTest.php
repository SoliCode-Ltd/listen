<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\VoiceCloningAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * When creating, retrying or deleting a cloned voice fails, the studio shows
 * the reason the server gave or, when it gave none (a server error page, a
 * lost connection), a message in the page's language, never English
 * "Error", "Failed" or "Network error".
 */
class VoiceCloningErrorTextTest extends TestCase
{
    use RefreshDatabase;

    private function studioPage(string $locale): \Illuminate\Testing\TestResponse
    {
        Plan::factory()->free()->create(['slug' => 'free']);
        $plan = Plan::factory()->paid()->create([
            'slug' => 'creator',
            'voice_tier' => 'hd',
            'voice_cloning_enabled' => true,
            'voice_cloning_max_voices' => 5,
        ]);
        $member = User::factory()->create(['email' => VoiceCloningAccess::PREVIEW_EMAIL]);
        Subscription::create(['user_id' => $member->id, 'plan_id' => $plan->id, 'status' => 'active']);

        app()->setLocale($locale);

        return $this->actingAs($member)->getLocalizedRoute('dashboard')->assertOk();
    }

    /** A string as @json writes it into the page's script. */
    private static function js(string $text): string
    {
        return json_encode($text, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    }

    public function test_the_fallbacks_are_in_the_page_language(): void
    {
        $page = $this->studioPage('ar');

        foreach (['request_failed', 'network_error', 'delete_failed'] as $key) {
            $text = __('ui.voice_cloning.'.$key, [], 'ar');
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $text);
            $page->assertSee(self::js($text), false);
        }

        $page->assertDontSee("|| 'Error'", false)
            ->assertDontSee("|| 'Failed'", false)
            ->assertDontSee("'Network error'", false)
            ->assertDontSee("error?.message || 'Network error'", false);
    }

    public function test_every_failure_uses_the_servers_reason_before_the_fallback(): void
    {
        $page = $this->studioPage('en');

        // The reason: the studio's own error, a message it wrote, or the form errors.
        $page->assertSee("const ownMessage = data?.ok === false || data?.success === false || data?.errors ? data?.message : '';", false)
            ->assertSee("return String(data?.error || ownMessage || fieldErrors || '').trim() || fallback;", false);

        // Creating (and replacing the consent recording), retrying, and deleting.
        $this->assertSame(3, substr_count($page->getContent(), "showAlert('error', failureText(data, requestFailedText));"));
        $page->assertSee("showAlert('error', failureText(data, deleteFailedText));", false);
        // A request that never got an answer.
        $this->assertSame(3, substr_count($page->getContent(), "showAlert('error', networkErrorText);"));
    }
}
