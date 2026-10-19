<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\VoiceCloningAccess;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The owner's rule: the site never names another company or product. These
 * are the places that did: a sign-in message, two script names anyone can
 * read in the page source, and the plans' seeded feature lines.
 */
class NoOtherCompanyNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sign_in_message_names_only_the_google_button(): void
    {
        $this->assertSame('Google sign-in is currently disabled.', __('ui.backend.auth_firebase_google_disabled', [], 'en'));

        foreach (['en', 'ar'] as $locale) {
            $this->assertStringNotContainsStringIgnoringCase('firebase', __('ui.backend.auth_firebase_google_disabled', [], $locale));
            $this->assertStringNotContainsStringIgnoringCase('firebase', __('ui.backend.auth_firebase_google_failed', [], $locale));
        }
    }

    public function test_the_page_scripts_do_not_name_the_speech_engine(): void
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

        // The voice cloning studio turns recordings into WAV before they are sent.
        $this->actingAs($member)->getLocalizedRoute('dashboard')
            ->assertOk()
            ->assertSee('async function toCloningWav(blob)', false)
            ->assertSee('await toCloningWav(sampleSource)', false)
            ->assertDontSee('toGeminiWav', false);

        $this->actingAs(User::factory()->create())->getLocalizedRoute('demo.page')
            ->assertOk()
            ->assertSee('function findPreferredArabicHdVoiceKey(voices, language, tier)', false)
            ->assertSee('const preferredArabicHdVoiceKey = findPreferredArabicHdVoiceKey(voices, lang, tier);', false)
            ->assertDontSee('GeminiVoiceKey', false);
    }

    public function test_the_seeded_plan_lines_name_no_speech_engine(): void
    {
        $this->seed(PlanSeeder::class);

        $lines = Plan::query()->pluck('features')->flatten()->all();

        $this->assertContains('Standard voices', $lines);
        $this->assertContains('HD voices', $lines);
        foreach ($lines as $line) {
            $this->assertDoesNotMatchRegularExpression('/wavenet|gemini|google/i', (string) $line);
        }
    }
}
