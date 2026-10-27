<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TextItem;
use App\Models\User;
use App\Support\VoiceTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

class AudioHistorySequenceAndReuseTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_displays_user_specific_sequence_numbers(): void
    {
        Plan::factory()->free()->create([
            'monthly_chars' => 500000,
            'monthly_requests' => 100,
            'max_chars_per_request' => 50000,
        ]);

        $user = User::factory()->create([
            'subscription_status_checked_at' => now(),
        ]);
        $otherUser = User::factory()->create([
            'subscription_status_checked_at' => now(),
        ]);

        AudioGeneration::create([
            'user_id' => $otherUser->id,
            'user_sequence_number' => 1,
            'input_text' => 'Other user first audio',
            'target_language' => 'en',
            'status' => 'done',
        ]);

        AudioGeneration::create([
            'user_id' => $otherUser->id,
            'user_sequence_number' => 2,
            'input_text' => 'Other user second audio',
            'target_language' => 'en',
            'status' => 'done',
        ]);

        AudioGeneration::create([
            'user_id' => $user->id,
            'user_sequence_number' => 1,
            'input_text' => 'My first audio',
            'target_language' => 'en',
            'status' => 'done',
        ]);

        AudioGeneration::create([
            'user_id' => $user->id,
            'user_sequence_number' => 2,
            'input_text' => 'My second audio',
            'target_language' => 'en',
            'status' => 'done',
        ]);

        $response = $this->actingAs($user)->getLocalizedRoute('dashboard');

        $response->assertOk();
        $response->assertSee('#1</td>', false);
        $response->assertSee('#2</td>', false);
        $response->assertDontSee('#35</td>', false);
    }

    public function test_dashboard_shows_reuse_link_for_premium_and_standard_records(): void
    {
        Plan::factory()->free()->create([
            'monthly_chars' => 500000,
            'monthly_requests' => 100,
            'max_chars_per_request' => 50000,
        ]);

        $hdPlan = Plan::factory()->create([
            'slug' => 'creator',
            'voice_tier' => VoiceTier::HD,
            'custom_prompt_enabled' => true,
            'dialogue_enabled' => true,
            'monthly_chars' => 500000,
            'monthly_requests' => 100,
            'max_chars_per_request' => 50000,
        ]);

        $user = User::factory()->create([
            'subscription_status_checked_at' => now(),
        ]);

        Subscription::query()->where('user_id', $user->id)->delete();
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $hdPlan->id,
            'status' => 'active',
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);

        $premiumVoice = 'en-Gemini-HD-Kore';
        $standardVoice = 'en-US-Journey-F';

        $premiumJob = AudioGeneration::create([
            'user_id' => $user->id,
            'user_sequence_number' => 1,
            'input_text' => 'Premium reusable audio',
            'target_language' => 'en',
            'voice_tier' => VoiceTier::HD,
            'voice_name' => $premiumVoice,
            'translate' => true,
            'performance_style' => 'podcast',
            'emotion' => 'warm',
            'dialect' => 'en-US',
            'status' => 'done',
            'settings_snapshot' => [
                'voice_name' => $premiumVoice,
                'voice_tier' => VoiceTier::HD,
                'target_language' => 'en',
                'translate' => true,
                'performance_style' => 'podcast',
                'emotion' => 'warm',
                'dialect' => 'en-US',
            ],
        ]);

        $standardJob = AudioGeneration::create([
            'user_id' => $user->id,
            'user_sequence_number' => 2,
            'input_text' => 'Standard audio',
            'target_language' => 'en',
            'voice_tier' => VoiceTier::STANDARD,
            'voice_name' => $standardVoice,
            'status' => 'done',
        ]);

        $response = $this->actingAs($user)->getLocalizedRoute('dashboard');

        $response->assertOk();
        $response->assertSee('?reuse='.$premiumJob->user_sequence_number.'#generateVoiceSection', false);
        $response->assertSee(__('ui.dashboard.reuse_settings'));
        // A standard voice is reused too: its speaker, language and text (ReuseLoadsEverythingTest).
        $response->assertSee('?reuse='.$standardJob->user_sequence_number.'#generateVoiceSection', false);
    }

    public function test_dashboard_reuse_prefills_title_and_content_from_selected_record(): void
    {
        Plan::factory()->free()->create([
            'monthly_chars' => 500000,
            'monthly_requests' => 100,
            'max_chars_per_request' => 50000,
        ]);

        $hdPlan = Plan::factory()->create([
            'slug' => 'creator',
            'voice_tier' => VoiceTier::HD,
            'custom_prompt_enabled' => true,
            'dialogue_enabled' => true,
            'monthly_chars' => 500000,
            'monthly_requests' => 100,
            'max_chars_per_request' => 50000,
        ]);

        $user = User::factory()->create([
            'subscription_status_checked_at' => now(),
        ]);

        Subscription::query()->where('user_id', $user->id)->delete();
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $hdPlan->id,
            'status' => 'active',
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);

        $job = AudioGeneration::create([
            'user_id' => $user->id,
            'user_sequence_number' => 12,
            'input_text' => 'Reusable content body for regenerate flow.',
            'target_language' => 'en',
            'voice_tier' => VoiceTier::HD,
            'voice_name' => 'en-Gemini-HD-Kore',
            'translate' => true,
            'performance_style' => 'podcast',
            'emotion' => 'warm',
            'status' => 'done',
            'settings_snapshot' => [
                'voice_name' => 'en-Gemini-HD-Kore',
                'voice_tier' => VoiceTier::HD,
                'target_language' => 'en',
                'translate' => true,
                'performance_style' => 'podcast',
                'emotion' => 'warm',
            ],
        ]);

        $textItem = TextItem::create([
            'user_id' => $user->id,
            'source_type' => 'message',
            'title' => 'Reusable title',
            'content' => $job->input_text,
            'language' => 'en',
            'status' => 'done',
            'received_at' => now(),
        ]);
        $job->update(['text_item_id' => $textItem->id]);

        $response = $this->actingAs($user)
            ->withoutMiddleware([
                LocaleSessionRedirect::class,
                LaravelLocalizationRedirectFilter::class,
                LaravelLocalizationViewPath::class,
            ])
            ->get($this->localizedRoute('dashboard').'?reuse=12');

        $response->assertOk();
        $response->assertSee('value="Reusable title"', false);
        $response->assertSee('Reusable content body for regenerate flow.', false);
        $response->assertSee('?reuse=12#generateVoiceSection', false);
        $response->assertDontSee('?reuse='.$job->id.'#generateVoiceSection', false);
    }
}
