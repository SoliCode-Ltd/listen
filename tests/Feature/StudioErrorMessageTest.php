<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voice;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A refused Generate is shown in the server's own words, decided by the
 * reply's status and never by its wording: "Text exceeds 5000 character
 * limit" (Starter, packs), 250000 (Studio Max) and the demo's 1,500 or 500
 * left used to be swapped for "Our servers are experiencing high demand"
 * because they hold the digits 500. The busy text is kept for a server error
 * without a message of ours, a reply that is not JSON, or no reply at all.
 */
class StudioErrorMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_the_text_limit_comes_back_as_a_refusal_with_its_number(): void
    {
        $user = $this->member(5000);

        $response = $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => trim(str_repeat('Hello there, my friend. ', 260)),
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => $this->voiceKey(),
        ]);

        $response->assertStatus(422)->assertJsonPath('message', __('ui.backend.dashboard_request_limit', ['max' => 5000]));
        $this->assertStringContainsString('5000', (string) $response->json('message'));
    }

    public function test_the_studio_decides_by_the_reply_not_by_the_wording(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $html = $this->actingAs($this->member(5000))->getLocalizedRoute('dashboard')->assertOk()->getContent();

            $this->assertStringNotContainsString('isTechnicalError', $html, 'no message is hidden for its wording');
            $this->assertStringNotContainsString('/500/,', $html);
            $this->assertStringContainsString('const serverTrouble = !isJson || (status >= 500 && data.ok !== false);', $html);
            // Generate and delete both pass the status, and read a reply that is not JSON as none.
            $this->assertStringContainsString('throw new Error(extractErrorMessage(data, i18n.voice_generation_failed, replyStatus));', $html);
            $this->assertStringContainsString('throw new Error(extractErrorMessage(data, i18n.unable_delete_voice, replyStatus));', $html);
            $this->assertStringContainsString('data = await response.json().catch(() => null);', $html);
            // The existing texts, in the page's language.
            $this->assertStringContainsString($this->jsonText(__('ui.dashboard.server_overload')), $html);
            $this->assertStringContainsString($this->jsonText(__('ui.dashboard.voice_generation_failed')), $html);
        }
    }

    public function test_the_demo_decides_by_the_reply_not_by_the_wording(): void
    {
        Plan::factory()->create(['slug' => 'free', 'price_cents' => 0, 'is_active' => true, 'voice_tier' => 'standard']);
        $html = $this->actingAs(User::factory()->create())->getLocalizedRoute('demo.page')->assertOk()->getContent();

        $this->assertStringNotContainsString('technicalPatterns', $html, 'no message is hidden for its wording');
        $this->assertStringContainsString('const serverTrouble = !data || (res.status >= 500 && data.ok !== false);', $html);
        $this->assertStringContainsString("} catch (_networkError) {\n        // No reply at all", $html);
        $this->assertStringContainsString($this->jsonText(__('ui.dashboard.server_overload')), $html);
    }

    /** How the page's @json writes a text. */
    private function jsonText(string $text): string
    {
        return substr(json_encode($text, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), 1, -1);
    }

    private function member(int $maxChars): User
    {
        $plan = Plan::factory()->create(['voice_tier' => 'standard', 'price_cents' => 999, 'is_active' => true, 'monthly_chars' => 100000, 'max_chars_per_request' => $maxChars, 'monthly_requests' => 0]);
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }

    private function voiceKey(): string
    {
        $voice = Voice::query()->firstOrCreate(['internal_voice_id' => 'en-US-Wavenet-C'], [
            'language' => 'en', 'gender' => 'female', 'tier' => 'standard', 'display_name' => 'Emma',
            'provider' => 'google_cloud', 'provider_voice_id' => 'en-US-Wavenet-C', 'is_active' => true,
        ]);

        return VoiceCatalog::keyFromId((int) $voice->id);
    }
}
