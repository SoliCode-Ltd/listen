<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\User;
use App\Support\CreditPeriod;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\InteractsWithDeveloperApi;
use Tests\TestCase;

/**
 * A text that needs more credits than are left is refused, and the member is
 * told what is true: how many credits are left and how many this text needs
 * (as a pack says), and "used up" only once nothing is left.
 */
class CreditsLeftMessageTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_with_credits_left_the_studio_says_how_many_are_left_and_how_many_the_text_needs(): void
    {
        // "Hello there, this is a test." is about 3 seconds: 45 credits.
        $user = $this->apiMember($this->apiPlan(['monthly_chars' => 3000]));
        CreditPeriod::counter($user->id)->update(['total_chars' => 3000 - 10]);

        $this->studio($user)->assertStatus(422)->assertJsonPath('errors.content.0',
            'You have 10 credits left this month and this text needs about 45. Shorten it, or upgrade your plan for more credits.');

        app()->setLocale('ar');
        $this->studio($user)->assertStatus(422)->assertJsonPath('errors.content.0',
            'تبقّى لك هذا الشهر 10 رصيد، وهذا النص يحتاج حوالي 45 رصيد. اختصر النص أو رقِّ خطتك لتحصل على رصيد أكثر.');

        $this->assertSame(0, AudioGeneration::query()->count(), 'nothing is made or reserved');
        $this->assertSame(3000 - 10, $this->creditsSpent($user));
    }

    public function test_large_numbers_are_written_with_separators(): void
    {
        $user = $this->apiMember($this->apiPlan(['monthly_chars' => 90000]));
        CreditPeriod::counter($user->id)->update(['total_chars' => 90000 - 1234]);

        // 1,500 characters are about 100 seconds: 1,500 credits.
        $this->studio($user, str_repeat('a', 1500))->assertStatus(422)
            ->assertJsonPath('errors.content.0', 'You have 1,234 credits left this month and this text needs about 1,500. Shorten it, or upgrade your plan for more credits.');
    }

    public function test_with_no_credits_left_they_are_used_up(): void
    {
        $user = $this->apiMember($this->apiPlan(['monthly_chars' => 3000]));
        CreditPeriod::counter($user->id)->update(['total_chars' => 3000]);

        $this->studio($user)->assertStatus(422)->assertJsonPath('errors.content.0', __('ui.backend.monthly_chars_exceeded'));

        app()->setLocale('ar');
        $this->studio($user)->assertStatus(422)->assertJsonPath('errors.content.0', 'لقد استهلكت رصيد هذا الشهر. رقِّ خطتك للمتابعة.');
    }

    public function test_the_developer_api_says_the_same(): void
    {
        $user = $this->apiMember($this->apiPlan(['monthly_chars' => 3000]));
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();
        CreditPeriod::counter($user->id)->update(['total_chars' => 3000 - 10]);

        $this->assertApiError($this->speak($plain, $voice), 402, 'quota_exceeded', 'quota_error')
            ->assertJsonPath('error.credits_needed', 45)
            ->assertJsonPath('error.message', 'You have 10 credits left this month and this text needs about 45. Shorten it, or upgrade your plan for more credits.');

        CreditPeriod::counter($user->id)->update(['total_chars' => 3000]);
        $this->assertApiError($this->speak($plain, $voice), 402, 'quota_exceeded')
            ->assertJsonPath('error.message', __('ui.backend.monthly_chars_exceeded'));
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
