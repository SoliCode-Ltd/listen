<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\User;
use App\Models\Voice;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\Feature\Api\InteractsWithDeveloperApi;
use Tests\TestCase;

/**
 * "Reuse" in the history opens the studio with everything the voice was made with,
 * as far as the member's plan has it today: a speaker or option the plan lacks stays
 * on its default, and the page says so. No audio is made here.
 */
class ReuseLoadsEverythingTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
    }

    public function test_a_premium_voice_comes_back_as_it_was_made(): void
    {
        $member = $this->apiMember();
        $speaker = $this->premiumVoice();

        $this->reuse($member, $this->made($member, $this->premiumChoices($speaker)))
            ->assertSee('const oldVoice = "'.VoiceCatalog::keyFromId((int) $speaker->id).'"', false)
            ->assertSee('performanceStyle: String("podcast" || \'\').trim()', false)
            ->assertSee('emotion: String("calm" || \'\').trim()', false)
            ->assertSee('dialect: String("en-GB" || \'\').trim()', false)
            ->assertSee('name="speed" id="speed" min="0.5" max="2.0" step="0.1" value="1.2"', false)
            ->assertSee('name="pitch" id="pitch" min="-10" max="10" step="1" value="2"', false)
            ->assertSee('dialectAuto: false', false)
            ->assertDontSee(__('ui.dashboard.reusing_settings_plan_note'));
    }

    public function test_a_dialect_left_on_auto_comes_back_as_auto(): void
    {
        $member = $this->apiMember();
        $made = $this->made($member, ['dialect' => null] + $this->premiumChoices($this->premiumVoice()));

        $this->assertTrue($made->reusableSettings()['dialect_auto']);
        $this->reuse($member, $made)->assertSee('dialectAuto: true', false);
    }

    public function test_a_dialogue_comes_back_with_its_speakers(): void
    {
        $member = $this->apiMember($this->apiPlan(['dialogue_enabled' => true]));
        $speakers = [['name' => 'أحمد', 'voice' => 'voice_1', 'dialect' => 'ar-EG'], ['name' => 'سارة', 'voice' => 'voice_2', 'dialect' => 'ar-SA']];
        $made = $this->made($member, ['is_dialogue' => true, 'dialogue_speakers' => $speakers, 'target_language' => 'ar', 'dialect' => 'ar-EG'] + $this->premiumChoices($this->premiumVoice()));

        // The names stay as written (js/dialogue-mode.js keeps a name the page came with).
        $this->reuse($member, $made)
            ->assertSee('id="dialogueSelect" value="1"', false)
            ->assertSee('value="أحمد"', false)
            ->assertSee('value="سارة"', false)
            ->assertSee('data-initial="ar-SA"', false);
    }

    public function test_a_standard_voice_can_be_reused_too(): void
    {
        $member = $this->apiMember($this->freePlan());
        $speaker = Voice::query()->create([
            'language' => 'en', 'gender' => 'male', 'tier' => 'standard', 'display_name' => 'Sam',
            'internal_voice_id' => 'en-US-Wavenet-D', 'provider' => 'google_cloud', 'provider_voice_id' => 'en-US-Wavenet-D', 'is_active' => true,
        ]);
        $made = $this->made($member, [
            'voice_name' => 'en-US-Wavenet-D', 'voice_tier' => 'standard', 'target_language' => 'en',
            'performance_style' => 'neutral', 'emotion' => 'neutral', 'speed' => 1.0, 'pitch' => 0.0,
        ]);

        $this->assertTrue($made->hasReusableSettings());
        $settings = $made->reusableSettings();
        $this->assertSame(VoiceCatalog::keyFromId((int) $speaker->id), $settings['voice_key']);
        // A standard voice chooses no style or emotion: none comes back.
        $this->assertArrayNotHasKey('performance_style', $settings);
        $this->assertArrayNotHasKey('emotion', $settings);

        $this->reuse($member, $made)->assertSee('const oldVoice = "'.$settings['voice_key'].'"', false);
    }

    public function test_a_cloned_voice_is_picked_again(): void
    {
        $member = $this->apiMember();
        $made = $this->made($member, ['voice_name' => 'cloned_7'] + $this->premiumChoices($this->premiumVoice()));

        $this->assertSame('cloned_7', $made->reusableSettings()['voice_key']);
    }

    public function test_what_the_plan_no_longer_has_stays_on_its_default(): void
    {
        $member = $this->apiMember($this->freePlan());
        $made = $this->made($member, $this->premiumChoices($this->premiumVoice()));

        $page = $this->reuse($member, $made)
            ->assertSee('performanceStyle: String(null || \'\').trim()', false)
            ->assertSee('emotion: String(null || \'\').trim()', false)
            ->assertSee('dialect: String(null || \'\').trim()', false)
            ->assertSee('const oldVoice = null', false)
            ->assertSee('Hello again, everyone.')
            ->assertSee(__('ui.dashboard.reusing_settings_plan_note'));
        // Only the text, language and the rest the plan has come back.
        $reused = $page->viewData('reusePreset');
        foreach (['voice_key', 'dialect', 'performance_style', 'emotion', 'speed', 'pitch'] as $left) {
            $this->assertArrayNotHasKey($left, $reused, $left);
        }
        $this->assertSame('en', $reused['target_language']);
    }

    /** @return array<string, mixed> */
    private function premiumChoices(Voice $speaker): array
    {
        return [
            'voice_name' => $speaker->internal_voice_id, 'voice_tier' => 'hd', 'target_language' => 'en',
            'dialect' => 'en-GB', 'performance_style' => 'podcast', 'emotion' => 'calm', 'speed' => 1.2, 'pitch' => 2.0,
        ];
    }

    private function freePlan(): Plan
    {
        return $this->apiPlan(['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'voice_tier' => 'standard', 'api_access_enabled' => false]);
    }

    /** @param  array<string, mixed>  $choices */
    private function made(User $member, array $choices): AudioGeneration
    {
        $snapshot = $choices + ['translate' => false, 'is_dialogue' => false, 'on_default' => []];

        return AudioGeneration::create([
            'user_id' => $member->id, 'input_text' => 'Hello again, everyone.', 'source_language' => 'en', 'status' => 'done',
            'translate' => false, 'settings_snapshot' => $snapshot,
            'user_sequence_number' => AudioGeneration::query()->where('user_id', $member->id)->count() + 1,
        ] + array_diff_key($choices, ['dialogue_speakers' => true]));
    }

    private function reuse(User $member, AudioGeneration $made): TestResponse
    {
        return $this->actingAs($member)->withoutMiddleware([
            LocaleSessionRedirect::class,
            LaravelLocalizationRedirectFilter::class,
            LaravelLocalizationViewPath::class,
        ])->get($this->localizedRoute('dashboard').'?reuse='.$made->user_sequence_number)->assertOk();
    }
}
