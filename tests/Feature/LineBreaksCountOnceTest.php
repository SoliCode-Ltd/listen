<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\DemoVoiceGeneration;
use App\Models\Plan;
use App\Models\User;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\InteractsWithDeveloperApi;
use Tests\TestCase;

/**
 * A line break is one character. The studio and the demo count it once in the
 * browser, but their forms send it as \r\n: the server used to count both, so
 * a text at the plan's limit with line breaks was refused and the demo charged
 * two of its characters for each line break.
 */
class LineBreaksCountOnceTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
    }

    public function test_a_studio_text_at_the_plans_limit_with_line_breaks_is_accepted(): void
    {
        $user = $this->apiMember($this->apiPlan(['max_chars_per_request' => 1000]));
        // 1,000 characters as the page counts them, the nine line breaks included.
        $lines = array_merge(['b'.str_repeat('a', 99)], array_fill(0, 9, str_repeat('a', 99)));

        $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => implode("\r\n", $lines),
            'target_language' => 'en',
            'voice_tier' => 'hd',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->premiumVoice()->id),
        ])->assertOk()->assertJsonPath('ok', true);

        $voice = AudioGeneration::query()->where('user_id', $user->id)->sole();
        $this->assertSame(implode("\n", $lines), $voice->input_text, 'kept and spoken with plain line breaks');
        $this->assertSame(1000, $voice->billable_chars);
    }

    public function test_one_character_more_is_still_refused(): void
    {
        $user = $this->apiMember($this->apiPlan(['max_chars_per_request' => 1000]));
        $lines = array_merge(['bb'.str_repeat('a', 99)], array_fill(0, 9, str_repeat('a', 99)));

        $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => implode("\r\n", $lines),
            'target_language' => 'en',
            'voice_tier' => 'hd',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->premiumVoice()->id),
        ])->assertStatus(422)->assertJsonPath('errors.content.0', __('ui.backend.dashboard_request_limit', ['max' => 1000]));
    }

    public function test_the_demo_charges_one_character_for_each_line_break(): void
    {
        Plan::factory()->create(['slug' => 'free', 'price_cents' => 0, 'voice_tier' => 'standard', 'is_active' => true, 'monthly_requests' => 0]);
        $member = User::factory()->create(['subscription_status_checked_at' => now()]);
        $voiceKey = VoiceCatalog::keyFromId((int) $this->premiumVoice()->id);
        $this->actingAs($member);

        // The check before generating counts the same.
        $this->postJsonLocalizedRoute('demo.run', [
            'text' => "Line one\r\nLine two\rLine three",
            'target_language' => 'en',
            'voice_tier' => 'hd',
            'voice_key' => $voiceKey,
        ])->assertOk()->assertJsonPath('estimated_chars', 28);

        $this->postJsonLocalizedRoute('demo.preview', [
            'text' => "Line one\r\nLine two\rLine three",
            'target_language' => 'en',
            'voice_tier' => 'hd',
            'voice_key' => $voiceKey,
        ])->assertOk()->assertJsonPath('quota.used_chars', 28);

        $demo = DemoVoiceGeneration::query()->sole();
        $this->assertSame(28, $demo->billable_chars);
        $this->assertSame("Line one\nLine two\nLine three", $demo->input_text);
    }

    public function test_a_demo_text_of_the_whole_allowance_with_line_breaks_is_accepted(): void
    {
        Plan::factory()->create(['slug' => 'free', 'price_cents' => 0, 'voice_tier' => 'standard', 'is_active' => true, 'monthly_requests' => 0]);
        $this->actingAs(User::factory()->create(['subscription_status_checked_at' => now()]));
        // 300 characters as the page counts them: three lines of 99 and two line breaks, plus one.
        $text = implode("\r\n", ['b'.str_repeat('a', 99), str_repeat('a', 99), str_repeat('a', 99)]);

        $this->postJsonLocalizedRoute('demo.preview', [
            'text' => $text,
            'target_language' => 'en',
            'voice_tier' => 'hd',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->premiumVoice()->id),
        ])->assertOk()->assertJsonPath('quota.used_chars', 300)->assertJsonPath('quota.remaining_chars', 0);
    }
}
