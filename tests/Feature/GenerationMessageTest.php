<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voice;
use App\Services\TranslationService;
use App\Services\TtsService;
use App\Support\GenerationMessage;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A voice that failed keeps its message in English (as the developer API
 * shows it); the studio and the extension show it in the member's language,
 * old rows kept in either language included. Speech is faked.
 */
class GenerationMessageTest extends TestCase
{
    use RefreshDatabase;

    private const FAILED = 'Voice generation failed. Please try again.';

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_a_kept_message_is_shown_in_the_page_language_whichever_language_it_was_kept_in(): void
    {
        foreach (['ui.backend.dashboard_voice_generation_failed_try_again', 'ui.backend.cloned_voice_daily_limit', 'ui.backend.translation_failed'] as $key) {
            foreach (['en', 'ar'] as $keptIn) {
                $kept = trans($key, [], $keptIn);

                app()->setLocale('ar');
                $this->assertSame(trans($key, [], 'ar'), GenerationMessage::forDisplay($kept), "{$key} kept in {$keptIn}");
                app()->setLocale('en');
                $this->assertSame(trans($key, [], 'en'), GenerationMessage::forDisplay($kept), "{$key} kept in {$keptIn}");
            }
        }

        app()->setLocale('ar');
        $this->assertSame(trans('ui.backend.dashboard_voice_generation_failed_try_again', [], 'ar'), GenerationMessage::forDisplay('Voice generation could not be started. Please try again.'), 'a developer API message too');
        $this->assertSame('Something an admin wrote.', GenerationMessage::forDisplay('Something an admin wrote.'));
        $this->assertNull(GenerationMessage::forDisplay(null));
        $this->assertNull(GenerationMessage::forDisplay('  '));
        $this->assertSame(self::FAILED, GenerationMessage::stored('ui.backend.dashboard_voice_generation_failed_try_again'), 'kept in English whatever the page language');
    }

    public function test_the_studio_shows_a_failed_voice_in_arabic(): void
    {
        $user = $this->member();
        AudioGeneration::query()->create([
            'user_id' => $user->id, 'user_sequence_number' => 1, 'input_text' => 'Hello there', 'target_language' => 'en',
            'voice_tier' => 'standard', 'status' => 'failed', 'error_message' => self::FAILED,
        ]);
        app()->setLocale('ar');

        $html = $this->actingAs($user)->getLocalizedRoute('dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('<small style="color: var(--danger);">'.e(trans('ui.backend.dashboard_voice_generation_failed_try_again', [], 'ar')).'</small>', $html);
        $this->assertStringNotContainsString(self::FAILED, $html);
    }

    public function test_a_failed_studio_generation_answers_in_the_members_language_and_keeps_english(): void
    {
        $user = $this->member();
        $this->fakeFailingSpeech();
        app()->setLocale('ar');

        $response = $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Hello there',
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->voice()->id),
        ])->assertStatus(422);

        $arabic = trans('ui.backend.dashboard_voice_generation_failed_try_again', [], 'ar');
        $response->assertJsonPath('message', $arabic)->assertJsonPath('job.error_message', $arabic);
        $this->assertSame(self::FAILED, AudioGeneration::query()->latest('id')->value('error_message'));
    }

    public function test_the_extension_shows_failed_voices_in_its_language(): void
    {
        $user = $this->member();
        $key = 'lk_live_'.str_repeat('m', 32);
        $user->forceFill(['api_key_hash' => User::hashApiKey($key)])->save();
        AudioGeneration::query()->create([
            'user_id' => $user->id, 'user_sequence_number' => 1, 'input_text' => 'Hello there', 'target_language' => 'en',
            'voice_tier' => 'standard', 'status' => 'failed', 'error_message' => self::FAILED,
        ]);

        $this->withHeaders(['X-Listen-Key' => $key, 'X-Listen-Locale' => 'ar'])->getJson('/api/reader/jobs')
            ->assertOk()
            ->assertJsonPath('jobs.0.error_message', trans('ui.backend.dashboard_voice_generation_failed_try_again', [], 'ar'));

        $this->withHeaders(['X-Listen-Key' => $key, 'X-Listen-Locale' => 'en'])->getJson('/api/reader/jobs')
            ->assertOk()
            ->assertJsonPath('jobs.0.error_message', self::FAILED);
    }

    private function member(): User
    {
        $plan = Plan::factory()->create(['voice_tier' => 'standard', 'is_active' => true, 'monthly_chars' => 100000, 'max_chars_per_request' => 5000, 'monthly_requests' => 0]);
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }

    private function voice(): Voice
    {
        return Voice::query()->firstOrCreate(['internal_voice_id' => 'en-US-Wavenet-C'], [
            'language' => 'en', 'gender' => 'female', 'tier' => 'standard', 'display_name' => 'Emma',
            'provider' => 'google_cloud', 'provider_voice_id' => 'en-US-Wavenet-C', 'is_active' => true,
        ]);
    }

    private function fakeFailingSpeech(): void
    {
        $translation = $this->createMock(TranslationService::class);
        $translation->method('maybeTranslate')->willReturnArgument(0);
        $this->app->instance(TranslationService::class, $translation);

        $tts = $this->createMock(TtsService::class);
        $tts->method('synthesizeAdvanced')->willThrowException(new \RuntimeException('Speech service unavailable.'));
        $this->app->instance(TtsService::class, $tts);
    }
}
