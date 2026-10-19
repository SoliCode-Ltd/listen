<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\DemoVoiceGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * Deleting a demo voice takes it off the member's history and stops its
 * audio, but its characters stay counted: deleting never refills the demo.
 */
class DemoVoiceDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Plan::query()->create([
            'slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_minutes' => 10, 'monthly_chars' => 10000,
            'monthly_requests' => 60, 'max_chars_per_request' => 1200, 'voice_tier' => 'standard', 'is_active' => true,
        ]);
    }

    public function test_deleting_a_demo_voice_does_not_give_its_characters_back(): void
    {
        $member = User::factory()->create(['subscription_status_checked_at' => now()]);
        $demo = $this->demoVoice($member, 200, 'audio/demo/20261019_120000_first.mp3');

        $response = $this->deleteDemo($member, $demo);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('deleted_id', $demo->id);
        $response->assertJsonPath('message', __('ui.backend.dashboard_voice_deleted'));
        $response->assertJsonStructure(['stats' => ['processing', 'done', 'failed', 'total']]);

        $kept = DemoVoiceGeneration::query()->find($demo->id);
        $this->assertNotNull($kept, 'the row stays for the usage sums');
        $this->assertSame(200, $kept->billable_chars);
        $this->assertNull($kept->audio_path);
        $this->assertNotEmpty(data_get($kept->metadata, 'deleted_by_member_at'));

        $this->actingAs($member)->postJsonLocalizedRoute('demo.quota', [])
            ->assertJsonPath('quota.used_chars', 200)
            ->assertJsonPath('quota.remaining_chars', 100);
    }

    public function test_its_audio_is_no_longer_served(): void
    {
        $member = User::factory()->create(['subscription_status_checked_at' => now()]);
        $demo = $this->demoVoice($member, 50, 'audio/demo/20261019_120000_gone.mp3');
        $this->get('/media/audio/demo/20261019_120000_gone.mp3')->assertOk();

        $this->deleteDemo($member, $demo)->assertOk();

        Storage::disk('public')->assertMissing('audio/demo/20261019_120000_gone.mp3');
        $this->get('/media/audio/demo/20261019_120000_gone.mp3')->assertNotFound();
    }

    public function test_audio_another_voice_still_uses_is_kept(): void
    {
        $member = User::factory()->create(['subscription_status_checked_at' => now()]);
        $demo = $this->demoVoice($member, 50, 'audio/shared-copy.mp3');
        // An older copy of a demo voice in the member's history uses the same file.
        AudioGeneration::query()->create([
            'user_id' => $member->id, 'status' => 'done', 'input_text' => 'Hello',
            'target_language' => 'en', 'audio_path' => 'audio/shared-copy.mp3',
        ]);

        $this->deleteDemo($member, $demo)->assertOk();

        Storage::disk('public')->assertExists('audio/shared-copy.mp3');
    }

    public function test_a_deleted_demo_voice_leaves_the_dashboard_history(): void
    {
        $starter = Plan::query()->create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_minutes' => 250, 'monthly_chars' => 225000,
            'monthly_requests' => 3000, 'max_chars_per_request' => 3000, 'voice_tier' => 'standard', 'is_active' => true,
        ]);
        $member = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->where('user_id', $member->id)->delete();
        Subscription::create([
            'user_id' => $member->id, 'plan_id' => $starter->id, 'status' => 'active',
            'current_period_end' => now()->addDays(10),
        ]);
        $kept = $this->demoVoice($member, 40, 'audio/demo/20261019_120000_kept.mp3');
        $deleted = $this->demoVoice($member, 60, 'audio/demo/20261019_120000_deleted.mp3');

        $this->deleteDemo($member, $deleted)->assertOk();

        $this->actingAs($member)->getLocalizedRoute('dashboard')
            ->assertOk()
            ->assertViewHas('demoHistoryJobs', fn (array $jobs): bool => array_column($jobs, 'id') === [$kept->id]);
    }

    public function test_another_members_demo_voice_cannot_be_deleted(): void
    {
        $owner = User::factory()->create(['subscription_status_checked_at' => now()]);
        $demo = $this->demoVoice($owner, 50, 'audio/demo/20261019_120000_owner.mp3');
        $stranger = User::factory()->create(['subscription_status_checked_at' => now()]);

        $this->deleteDemo($stranger, $demo)->assertNotFound();

        $this->assertSame('audio/demo/20261019_120000_owner.mp3', $demo->fresh()->audio_path);
        Storage::disk('public')->assertExists('audio/demo/20261019_120000_owner.mp3');
    }

    private function demoVoice(User $member, int $chars, string $audioPath): DemoVoiceGeneration
    {
        Storage::disk('public')->put($audioPath, 'demo-audio');

        return DemoVoiceGeneration::query()->create([
            'user_id' => $member->id,
            'primary_identity_hash' => 'u:'.$member->id,
            'input_text' => str_repeat('x', $chars),
            'speech_text' => str_repeat('x', $chars),
            'source_language' => 'en',
            'target_language' => 'en',
            'translate' => false,
            'voice_name' => 'en-US-Wavenet-C',
            'voice_provider' => 'google_cloud',
            'voice_tier' => 'standard',
            'audio_path' => $audioPath,
            'billable_chars' => $chars,
        ]);
    }

    private function deleteDemo(User $member, DemoVoiceGeneration $demo): TestResponse
    {
        return $this->actingAs($member)
            ->withoutMiddleware([
                LocaleSessionRedirect::class,
                LaravelLocalizationRedirectFilter::class,
                LaravelLocalizationViewPath::class,
                ValidateCsrfToken::class,
            ])
            ->deleteJson($this->localizedRoute('dashboard.demo-jobs.destroy', ['generation' => $demo->id]));
    }
}
