<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\DemoVoiceGeneration;
use App\Models\User;
use App\Support\TtsStudioConfig;
use App\Support\VoiceCatalog;
use App\Support\VoiceTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\InteractsWithDeveloperApi;
use Tests\TestCase;

/**
 * A style or emotion left on "Default" was saved as the site's default it stood
 * for («أسلوب إعلاني», «واثق»), so reusing the voice brought those back as if the
 * member had chosen them. Which choices were left on "Default" is now kept, and
 * reuse brings back "Default". Speech is faked: no real audio is made.
 */
class ReuseKeepsDefaultTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
    }

    public function test_choices_left_on_default_come_back_as_default(): void
    {
        $member = $this->apiMember();
        $this->generate($member, ['performance_style' => '', 'emotion' => '']);

        $voice = AudioGeneration::query()->where('user_id', $member->id)->sole();
        $this->assertSame(TtsStudioConfig::defaultPerformanceStyle('en'), $voice->performance_style, 'the voice is still made with the default');
        $this->assertSame(['performance_style', 'emotion'], $voice->settings_snapshot['on_default']);
        $reused = $voice->reusableSettings();
        $this->assertArrayNotHasKey('performance_style', $reused);
        $this->assertArrayNotHasKey('emotion', $reused);

        // The studio opens with both on "Default".
        $this->actingAs($member)->get($this->localizedRoute('dashboard').'?reuse='.$voice->user_sequence_number)
            ->assertOk()
            ->assertSee('performanceStyle: String(null || \'\').trim()', false)
            ->assertSee('emotion: String(null || \'\').trim()', false);
    }

    public function test_choices_made_come_back_as_chosen(): void
    {
        $member = $this->apiMember();
        $this->generate($member, ['performance_style' => 'podcast', 'emotion' => '']);

        $voice = AudioGeneration::query()->where('user_id', $member->id)->sole();
        $this->assertSame(['emotion'], $voice->settings_snapshot['on_default']);
        $reused = $voice->reusableSettings();
        $this->assertSame('podcast', $reused['performance_style']);
        $this->assertArrayNotHasKey('emotion', $reused);
    }

    public function test_older_voices_with_the_default_come_back_as_default(): void
    {
        $member = $this->apiMember();
        $old = fn (string $style): array => AudioGeneration::create([
            'user_id' => $member->id, 'input_text' => 'مرحبا', 'target_language' => 'ar', 'voice_tier' => VoiceTier::HD,
            'voice_name' => 'ar-Gemini-HD-Schedar', 'performance_style' => $style, 'emotion' => 'warm', 'status' => 'done',
            'settings_snapshot' => ['voice_name' => 'ar-Gemini-HD-Schedar', 'voice_tier' => VoiceTier::HD, 'target_language' => 'ar', 'performance_style' => $style, 'emotion' => 'warm'],
        ])->reusableSettings();

        // Made before the choice was kept: equal to the default today reads the same as "Default".
        $this->assertArrayNotHasKey('performance_style', $old(TtsStudioConfig::defaultPerformanceStyle('ar')));
        $this->assertSame('podcast', $old('podcast')['performance_style']);
        $this->assertSame('warm', $old('podcast')['emotion']);
    }

    public function test_the_demo_keeps_it_too(): void
    {
        // The demo is for members on the free plan.
        $this->apiPlan(['slug' => 'free', 'name' => 'Free', 'price_cents' => 0]);
        $member = User::factory()->create(['registration_ip' => '203.0.113.91', 'subscription_status_checked_at' => now()]);
        $voice = $this->premiumVoice();
        $this->fakeSpeech();

        $this->actingAs($member)->postJsonLocalizedRoute('demo.preview', [
            'text' => 'Hello there.',
            'target_language' => 'en',
            'voice_tier' => 'hd',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
            'performance_style' => '',
            'emotion' => 'warm',
        ])->assertOk();

        $demo = DemoVoiceGeneration::query()->where('user_id', $member->id)->sole();
        $this->assertSame(['performance_style'], data_get($demo->metadata, 'on_default'));
        $this->actingAs($member)->get($this->localizedRoute('dashboard').'?reuse_demo='.$demo->id)
            ->assertOk()
            ->assertSee('performanceStyle: String(null || \'\').trim()', false)
            ->assertSee('emotion: String("warm" || \'\').trim()', false);
    }

    /** @param  array<string, string>  $choices */
    private function generate(User $member, array $choices): void
    {
        $this->fakeSpeech();
        $this->actingAs($member)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Hello there, this is a test.',
            'target_language' => 'en',
            'voice_tier' => 'hd',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->premiumVoice()->id),
        ] + $choices)->assertOk()->assertJsonPath('ok', true);
    }
}
