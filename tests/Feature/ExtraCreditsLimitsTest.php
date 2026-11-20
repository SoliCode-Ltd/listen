<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Billing\ExtraCredits;
use App\Support\CreditPeriod;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\InteractsWithDeveloperApi;
use Tests\TestCase;

/**
 * When the month's credits run out, the studio is told it may offer extra
 * credits, the developer API says where to get more and when the plan's credits
 * start again, and extra credits bought are spent like the plan's own.
 */
class ExtraCreditsLimitsTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
        SiteSetting::set(ExtraCredits::ENABLED_SETTING, '1', 'billing');
    }

    public function test_the_studio_is_told_the_credits_ran_out_with_the_same_words_as_before(): void
    {
        $user = $this->apiMember();
        CreditPeriod::counter($user->id)->update(['total_chars' => 90000]);

        $this->studio($user)->assertStatus(422)
            ->assertJsonPath('out_of_credits', true)
            ->assertJsonPath('credits.remaining', 0)
            ->assertJsonPath('credits.needed', 60)
            ->assertJsonPath('message', __('ui.backend.monthly_chars_exceeded'))
            ->assertJsonPath('errors.content.0', __('ui.backend.monthly_chars_exceeded'));

        CreditPeriod::counter($user->id)->update(['total_chars' => 90000 - 10]);
        $this->studio($user)->assertStatus(422)->assertJsonPath('credits.remaining', 10);
    }

    public function test_extra_credits_are_spent_once_the_plans_credits_are_used(): void
    {
        $this->audioSeconds = 4;
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();
        CreditPeriod::counter($user->id)->update(['total_chars' => 90000]);
        $this->assertApiError($this->speak($plain, $voice), 402, 'quota_exceeded');

        $extra = app(ExtraCredits::class);
        $extra->markPaid($extra->startPurchase($user, 10, 'card', 200, 'USD'));

        $this->speak($plain, $voice)->assertOk()->assertHeader('X-Credits-Remaining', (string) (9000 - 60));
        $this->assertSame(90060, $this->creditsSpent($user));
    }

    public function test_the_developer_api_says_where_to_get_more_credits_and_when_they_start_again(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();
        CreditPeriod::counter($user->id)->update(['total_chars' => 90000 - 10]);
        $resetsAt = CreditPeriod::current($user->id)['ends_at']->toIso8601String();

        $this->assertApiError($this->speak($plain, $voice), 402, 'quota_exceeded', 'quota_error')
            ->assertJsonPath('error.credits_needed', 60)
            ->assertJsonPath('error.credits_remaining', 10)
            ->assertJsonPath('error.resets_at', $resetsAt)
            ->assertJsonPath('error.add_credits_url', url('/dashboard?extra_credits=1'))
            ->assertJsonPath('error.upgrade_url', url('/pricing'));

        // Not offered while extra credits are switched off.
        SiteSetting::set(ExtraCredits::ENABLED_SETTING, '0', 'billing');
        $this->assertApiError($this->speak($plain, $voice), 402, 'quota_exceeded')
            ->assertJsonMissingPath('error.add_credits_url')
            ->assertJsonPath('error.upgrade_url', url('/pricing'));
    }

    private function studio(User $user, string $text = 'Hello there, this is a test.'): TestResponse
    {
        $voice = \App\Models\Voice::query()->where('internal_voice_id', 'en-Gemini-HD-Kore')->first() ?? $this->premiumVoice();

        return $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => $text,
            'target_language' => 'en',
            'voice_tier' => 'hd',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
        ]);
    }
}
