<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Support\GeminiVocalTags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A script of moods and sounds alone ("[بذعر] <نفس ثقيل> <صرخة>") gives no voice
 * anything to say: the premium voice refused it and the backup voice read nothing,
 * and the member was charged. It is now stopped before anything is made or charged.
 */
class TagOnlyScriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_tags_alone_have_no_words(): void
    {
        // The scripts that fell back on live.
        $this->assertFalse(GeminiVocalTags::hasWords('[بذعر] <نفس ثقيل> <صرخة>'));
        $this->assertFalse(GeminiVocalTags::hasWords('[خائف] <نفس ثقيل> <نفس>'));
        $this->assertFalse(GeminiVocalTags::hasWords('[بارتياح] <تنفس ارتياح>'));
        $this->assertFalse(GeminiVocalTags::hasWords('[scared] <scream>'));
        $this->assertFalse(GeminiVocalTags::hasWords('<laugh> ... !'));

        $this->assertTrue(GeminiVocalTags::hasWords('[بذعر] <صرخة> لا! ابتعد عني'));
        $this->assertTrue(GeminiVocalTags::hasWords('<laugh> You came!'));
        $this->assertTrue(GeminiVocalTags::hasWords('[whispering] come closer [/]'));
        $this->assertTrue(GeminiVocalTags::hasWords('الرقم 42'));
        $this->assertTrue(GeminiVocalTags::hasWords('(WHO) said so'), 'text in brackets is text');
    }

    public function test_dashboard_rejects_a_script_of_tags_alone_before_charging(): void
    {
        $plan = Plan::factory()->create(['voice_tier' => \App\Support\VoiceTier::STANDARD, 'monthly_chars' => 500000, 'max_chars_per_request' => 50000]);
        $user = User::factory()->create();
        \App\Models\Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        $this->actingAs($user)->postJsonLocalizedRoute('dashboard.generate', [
            'content' => '[scared] <heavy breath> <scream>',
            'target_language' => 'en',
            'translate' => false,
            'voice_tier' => 'standard',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.content.0', __('ui.backend.dashboard_text_needs_words'));

        $this->assertDatabaseCount('audio_generations', 0);
        $this->assertDatabaseCount('text_items', 0);
    }

    public function test_the_message_exists_in_arabic_and_english(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $message = trans('ui.backend.dashboard_text_needs_words', [], $locale);
            $this->assertNotSame('ui.backend.dashboard_text_needs_words', $message);
        }
        $this->assertStringContainsString('<صرخة>', trans('ui.backend.dashboard_text_needs_words', [], 'ar'));
    }
}
