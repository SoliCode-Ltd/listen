<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A delete in the history that fails says why, beside the history and read
 * out: it used to be written into the generate form's message, which is
 * hidden while the history is shown, so nothing seemed to happen.
 */
class StudioDeleteMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_voice_still_being_made_cannot_be_deleted_yet_and_the_reply_says_why(): void
    {
        $user = $this->member();
        $generation = AudioGeneration::query()->create([
            'user_id' => $user->id, 'user_sequence_number' => 1, 'input_text' => 'Still being read.', 'source_language' => 'en',
            'target_language' => 'en', 'voice_tier' => 'standard', 'voice_name' => 'en-US-Wavenet-C', 'status' => 'processing',
        ]);

        $this->actingAs($user)->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ])->deleteJson($this->localizedRoute('dashboard.jobs.destroy', ['job' => $generation->id]))
            ->assertStatus(409)
            ->assertJsonPath('message', __('ui.backend.dashboard_voice_delete_in_progress'));
    }

    public function test_the_history_shows_a_failed_delete_where_the_member_is_and_reads_it_out(): void
    {
        $html = $this->actingAs($this->member())->getLocalizedRoute('dashboard')->assertOk()->getContent();

        $history = strpos($html, 'id="recentVoicesSection"');
        $message = strpos($html, '<div id="jobsMessage" aria-live="polite"></div>');
        $table = strpos($html, 'id="jobsTableBody"');
        $this->assertNotFalse($message, 'a message area that is read out');
        $this->assertTrue($history < $message && $message < $table, 'in the history, above the table');

        $this->assertStringContainsString("showJobsMessage('error', replyStatus ? String(error?.message || i18n.unable_delete_voice) : extractErrorMessage(null, i18n.unable_delete_voice));", $html);
        $this->assertStringNotContainsString("showFormMessage('error', replyStatus ? String(error?.message || i18n.unable_delete_voice)", $html, 'not in the hidden form message');
        $this->assertStringContainsString('scrollFeedbackIntoView(jobsMessage);', $html);
    }

    private function member(): User
    {
        $plan = Plan::factory()->create(['voice_tier' => 'standard', 'is_active' => true, 'monthly_chars' => 100000, 'max_chars_per_request' => 5000, 'monthly_requests' => 0]);
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }
}
