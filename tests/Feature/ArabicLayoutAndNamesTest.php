<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\VoiceCloningAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Arabic pages read right to left and use the digits the server writes (0-9):
 * history cells start on the right, a name in Arabic gets its own first
 * letter as the avatar (not "U"), and numbers and dates the scripts write
 * later use 0-9 like the rest of the page.
 */
class ArabicLayoutAndNamesTest extends TestCase
{
    use RefreshDatabase;

    private function avatarOf(string $name): string
    {
        $html = $this->actingAs(User::factory()->create(['name' => $name, 'google_avatar' => null]))
            ->getLocalizedRoute('dashboard')->assertOk()->getContent();

        preg_match('/<div class="user-avatar">\s*(.*?)\s*<\/div>/su', $html, $avatar);
        preg_match('/<div class="dropdown-avatar">\s*(.*?)\s*<\/div>/su', $html, $dropdown);
        $this->assertSame($avatar[1] ?? null, $dropdown[1] ?? null, 'the menu shows the same letter');

        return $avatar[1] ?? '';
    }

    public function test_the_avatar_shows_the_first_letter_of_the_name_in_any_script(): void
    {
        app()->setLocale('ar');

        $this->assertSame('أ', $this->avatarOf('أحمد علي'));
        $this->assertSame('M', $this->avatarOf('maria lopez'));
        $this->assertSame('L', $this->avatarOf('  1. Lina'));
        $this->assertSame('U', $this->avatarOf('123'));
    }

    public function test_history_cells_start_on_the_reading_side(): void
    {
        app()->setLocale('ar');

        $html = $this->actingAs(User::factory()->create())->getLocalizedRoute('dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="ar" dir="rtl"', $html);
        $this->assertMatchesRegularExpression('/th, td \{[^}]*text-align: start;[^}]*\}/', $html);
        $this->assertDoesNotMatchRegularExpression('/th, td \{[^}]*text-align: left;[^}]*\}/', $html);
    }

    public function test_numbers_and_dates_written_by_scripts_use_the_pages_digits(): void
    {
        Plan::factory()->free()->create(['slug' => 'free']);
        $plan = Plan::factory()->paid()->create(['slug' => 'creator', 'voice_tier' => 'hd', 'voice_cloning_enabled' => true, 'voice_cloning_max_voices' => 5]);
        $member = User::factory()->create(['email' => VoiceCloningAccess::PREVIEW_EMAIL]);
        Subscription::create(['user_id' => $member->id, 'plan_id' => $plan->id, 'status' => 'active']);
        app()->setLocale('ar');

        // The dashboard's counters, credits and cloned voice dates.
        $this->actingAs($member)->getLocalizedRoute('dashboard')->assertOk()
            ->assertSee("return new Intl.NumberFormat('en-US').format(Number(value || 0));", false)
            ->assertSee('charCountEl.textContent = formatNumber(length);', false)
            ->assertSee("toLocaleDateString(pageLocale.startsWith('ar') ? 'ar-EG-u-nu-latn' : 'en-US', {", false)
            ->assertDontSee("new Intl.NumberFormat(pageLocale === 'ar' ? 'ar' : 'en-US')", false)
            ->assertDontSee('length.toLocaleString()', false);

        // The billing page's trial note.
        $this->actingAs($member)->getLocalizedRoute('billing.page')->assertOk()
            ->assertSee(".replace(':chars', Number(quota.chars || 0).toLocaleString('en-US'))", false)
            ->assertSee("end.toLocaleDateString(isArabicLocale ? 'ar-u-nu-latn' : (document.documentElement.lang || undefined),", false);

        // The referral popup on the demo page.
        $this->actingAs(User::factory()->create())->getLocalizedRoute('demo.page')->assertOk()
            ->assertSee("try { return new Intl.NumberFormat('en-US').format(num); }", false)
            ->assertDontSee("locale === 'ar' ? 'ar-EG' : 'en-US'", false);
    }
}
