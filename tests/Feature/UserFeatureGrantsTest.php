<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\RelationManagers\FeatureGrantsRelationManager;
use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserFeatureGrant;
use App\Services\TranslationService;
use App\Services\Tts\TtsRoutingService;
use App\Services\TtsService;
use App\Support\PlanFeatures;
use App\Support\VoiceCatalog;
use App\Support\VoiceTier;
use Database\Seeders\VoiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * An admin can give one member an option for a few days; nobody else on the
 * same plan gets it, and it switches itself off when the time runs out.
 */
class UserFeatureGrantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_dialogue_trial_reaches_only_the_member_it_was_given_to(): void
    {
        $plan = $this->plan(VoiceTier::HD);
        $trial = $this->memberOf($plan);
        $other = $this->memberOf($plan);
        $this->grant($trial, PlanFeatures::DIALOGUE, now()->addDays(2));

        $this->assertTrue(PlanFeatures::userHas($trial, $plan, PlanFeatures::DIALOGUE));
        $this->assertFalse(PlanFeatures::userHas($other, $plan, PlanFeatures::DIALOGUE));
        $this->assertFalse(PlanFeatures::planOffers($plan, PlanFeatures::DIALOGUE), 'the plan itself is unchanged');
    }

    public function test_an_ended_trial_gives_nothing(): void
    {
        $plan = $this->plan(VoiceTier::HD);
        $user = $this->memberOf($plan);
        $this->grant($user, PlanFeatures::DIALOGUE, now()->subMinute());
        $this->grant($user, PlanFeatures::EFFECTS, now()->subDay());

        $this->assertFalse(PlanFeatures::userHas($user, $plan, PlanFeatures::DIALOGUE));
        $this->assertFalse(PlanFeatures::userHas($user, $plan, PlanFeatures::EFFECTS));
    }

    public function test_dialogue_still_needs_the_premium_voices_and_only_listed_options_can_be_given(): void
    {
        $plan = $this->plan(VoiceTier::STANDARD);
        $user = $this->memberOf($plan);
        $this->grant($user, PlanFeatures::DIALOGUE, now()->addDay());
        $this->grant($user, PlanFeatures::EFFECTS, now()->addDay());
        $this->grant($user, PlanFeatures::STUDIO, now()->addDay());

        $this->assertFalse(PlanFeatures::userHas($user, $plan, PlanFeatures::DIALOGUE));
        $this->assertTrue(PlanFeatures::userHas($user, $plan, PlanFeatures::EFFECTS));
        $this->assertFalse(PlanFeatures::userHas($user, $plan, PlanFeatures::STUDIO), 'not a grantable option');
        $this->assertFalse(PlanFeatures::userHas(null, $plan, PlanFeatures::EFFECTS));
    }

    public function test_the_dashboard_unlocks_dialogue_for_the_trial_member_only(): void
    {
        $plan = $this->plan(VoiceTier::HD);
        Plan::factory()->create(['slug' => 'pro', 'name' => 'Pro', 'voice_tier' => VoiceTier::HD, 'is_active' => true, 'price_cents' => 4900, 'dialogue_enabled' => true, 'sound_effects_enabled' => true]);
        $trial = $this->memberOf($plan);
        $other = $this->memberOf($plan);
        $this->grant($trial, PlanFeatures::DIALOGUE, now()->addDays(2));

        $locked = $this->actingAs($other)->getLocalizedRoute('dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('billing?plan=pro', $locked);

        $open = $this->actingAs($trial)->getLocalizedRoute('dashboard')->assertOk()->getContent();
        $this->assertLessThan(substr_count($locked, 'data-vs-locked'), substr_count($open, 'data-vs-locked'));
    }

    public function test_the_server_records_a_dialogue_only_for_the_trial_member(): void
    {
        $this->seed(VoiceSeeder::class);
        SiteSetting::set('tts_mode', TtsRoutingService::MODE_HYBRID, 'tts');
        $this->fakeSpeech();
        $plan = $this->plan(VoiceTier::HD);
        $trial = $this->memberOf($plan);
        $other = $this->memberOf($plan);
        $this->grant($trial, PlanFeatures::DIALOGUE, now()->addDays(2));

        $this->actingAs($other)->postJsonLocalizedRoute('dashboard.queue', $this->dialogueRequest())->assertOk();
        $this->assertFalse((bool) AudioGeneration::query()->latest('id')->firstOrFail()->is_dialogue);

        $this->actingAs($trial)->postJsonLocalizedRoute('dashboard.queue', $this->dialogueRequest())->assertOk();
        $this->assertTrue((bool) AudioGeneration::query()->latest('id')->firstOrFail()->is_dialogue);
    }

    public function test_the_admin_section_is_on_the_member_page(): void
    {
        $this->assertContains(FeatureGrantsRelationManager::class, UserResource::getRelations());
        $this->assertSame([PlanFeatures::DIALOGUE, PlanFeatures::EFFECTS], array_keys(PlanFeatures::GRANTABLE));
    }

    private function plan(string $tier): Plan
    {
        return Plan::factory()->create(['voice_tier' => $tier, 'is_active' => true, 'price_cents' => 2200, 'tts_provider_policy' => 'auto', 'monthly_chars' => 100000, 'max_chars_per_request' => 5000, 'monthly_requests' => 0, 'dialogue_enabled' => false, 'sound_effects_enabled' => false]);
    }

    private function memberOf(Plan $plan): User
    {
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }

    private function grant(User $user, string $feature, $expires): void
    {
        UserFeatureGrant::query()->create(['user_id' => $user->id, 'feature' => $feature, 'expires_at' => $expires]);
    }

    private function fakeSpeech(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('audio/voice.mp3', 'audio');
        $translation = $this->createMock(TranslationService::class);
        $translation->method('maybeTranslate')->willReturnArgument(0);
        $this->app->instance(TranslationService::class, $translation);

        $tts = $this->createMock(TtsService::class);
        $tts->method('synthesizeAdvanced')->willReturn([
            'audio_path' => 'audio/voice.mp3', 'duration_seconds' => 2, 'provider' => 'gemini', 'model' => 'premium',
            'latency_ms' => 10, 'fallback_used' => false, 'cost_estimate_usd' => 0.0, 'routing_mode' => 'hybrid', 'plan_slug' => 'test',
        ]);
        $this->app->instance(TtsService::class, $tts);
    }

    /**
     * @return array<string, mixed>
     */
    private function dialogueRequest(): array
    {
        $voices = array_values(array_filter(VoiceCatalog::publicCatalog()['ar'], fn ($v) => ($v['tier'] ?? '') === 'hd'));
        $a = collect($voices)->firstWhere('gender', 'male')['key'];
        $b = collect($voices)->firstWhere('gender', 'female')['key'];

        return [
            'content' => "سلطان: أهلاً فيكم في حلقة جديدة!\nنورة: أهلاً سلطان، جاهزة نبدأ.",
            'target_language' => 'ar',
            'translate' => false,
            'voice_tier' => 'hd',
            'voice_key' => $a,
            'performance_style' => 'neutral',
            'emotion' => 'neutral',
            'is_dialogue' => true,
            'dialogue_speakers' => [['name' => 'سلطان', 'voice' => $a], ['name' => 'نورة', 'voice' => $b]],
        ];
    }
}
