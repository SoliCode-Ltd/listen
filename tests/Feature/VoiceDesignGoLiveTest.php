<?php

namespace Tests\Feature;

use App\Models\DesignedVoice;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\VoiceDesignService;
use App\Support\PlanPricingLines;
use App\Support\VoiceDesign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Voice Design going live (database/migrations/2026_11_09_120000_open_voice_design_on_creator_pro_studio_max.php):
 * Creator 10 new voices a month, Pro 50, Studio Max no limit, open to everyone, and each plan's
 * own pricing-card lines saying so.
 */
class VoiceDesignGoLiveTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_11_09_120000_open_voice_design_on_creator_pro_studio_max.php';

    public function test_going_live_opens_it_on_the_three_plans_with_their_numbers_and_their_own_card_lines(): void
    {
        $starter = $this->plan('starter', 900);
        $creator = $this->plan('creator', 2200, withOwnLines: true);
        $pro = $this->plan('pro', 4900, withOwnLines: true);
        $max = $this->plan('studio_max', 9900, withOwnLines: true);

        $this->migrate();
        $this->migrate(); // A second run changes nothing.

        $this->assertSame([true, 10, 10, false], $this->numbers($creator->fresh()));
        $this->assertSame([true, 50, 50, false], $this->numbers($pro->fresh()));
        $this->assertTrue($max->fresh()->voice_design_enabled);
        $this->assertTrue(VoiceDesign::isUnlimited($max->fresh()));
        $this->assertSame([false, 0, 0, false], $this->numbers($starter->fresh()));
        $this->assertSame(VoiceDesign::AUDIENCE_EVERYONE, VoiceDesign::audience());

        // Each plan's own lines: Voice Design right under voice cloning, once, in both languages.
        $this->assertSame(
            ['Generations per day', 'Voice cloning', 'Voice Design', 'Commercial use'],
            array_column($creator->fresh()->features_en, 'label'),
        );
        $this->assertSame(['label' => 'Voice Design', 'value' => '10 voices a month', 'icon' => 'sliders'], $creator->fresh()->features_en[2]);
        $this->assertSame(['label' => 'تصميم الصوت', 'value' => '10 أصوات شهريًا', 'icon' => 'sliders'], $creator->fresh()->features_ar[2]);
        $this->assertSame('50 voices a month', $pro->fresh()->features_en[2]['value']);
        $this->assertSame('50 صوتًا شهريًا', $pro->fresh()->features_ar[2]['value']);
        $this->assertSame('Unlimited', $max->fresh()->features_en[2]['value']);
        $this->assertSame('غير محدود', $max->fresh()->features_ar[2]['value']);

        // The pricing card shows the plan's own lines, Voice Design among them.
        $this->assertContains('Voice Design', array_column(PlanPricingLines::forPlan($creator->fresh(), 'en'), 'label'));
        $this->assertContains('تصميم الصوت', array_column(PlanPricingLines::forPlan($max->fresh(), 'ar'), 'label'));
    }

    public function test_a_new_install_without_these_plans_stays_as_it_was(): void
    {
        $this->plan('starter', 900);

        $this->migrate();

        $this->assertSame(VoiceDesign::AUDIENCE_PREVIEW, VoiceDesign::audience());
        $this->assertFalse(Plan::query()->where('voice_design_enabled', true)->exists());
    }

    public function test_hand_written_voice_design_lines_wait_until_it_is_open_to_everyone(): void
    {
        $creator = $this->plan('creator', 2200, withOwnLines: true);
        $this->migrate();
        SiteSetting::set('voice_design_audience', VoiceDesign::AUDIENCE_PREVIEW, VoiceDesign::GROUP);

        $this->assertNotContains('Voice Design', array_column(PlanPricingLines::forPlan($creator->fresh(), 'en'), 'label'));
        $this->assertNotContains('تصميم الصوت', array_column(PlanPricingLines::forPlan($creator->fresh(), 'ar'), 'label'));
        $this->assertContains('Voice cloning', array_column(PlanPricingLines::forPlan($creator->fresh(), 'en'), 'label'));
    }

    public function test_a_plan_with_no_limit_never_fills_up_and_says_unlimited(): void
    {
        $max = $this->plan('studio_max', 9900);
        $this->migrate();
        $member = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $member->id, 'plan_id' => $max->id, 'status' => 'active']);
        foreach (range(1, 12) as $n) {
            DesignedVoice::query()->create([
                'user_id' => $member->id, 'status' => DesignedVoice::SAVED, 'batch' => 'b'.$n, 'name' => 'Voice '.$n,
                'description' => 'A voice.', 'language' => 'en', 'provider_voice_key' => 'v'.$n, 'key_name' => 'GEMINI_TTS_API_KEY',
                'take' => 1, 'kept_at' => now(), 'metadata' => ['site' => VoiceDesignService::siteTag()],
            ]);
        }

        $this->assertNull(VoiceDesign::keepBlocker((int) $member->id, $max->fresh()));
        $this->assertSame(0, VoiceDesign::monthlyLimit($max->fresh()));
        $this->assertSame('Unlimited', PlanPricingLines::value($max->fresh(), 'voice_design', 'en'));

        app()->setLocale('en');
        $page = $this->actingAs($member)->getLocalizedRoute('dashboard')->assertOk();
        $page->assertSee(__('voice_design.month_unlimited_caption'), false);
        $this->assertDoesNotMatchRegularExpression('/data-vd-limit role="status"(?!\s+hidden)/', $page->getContent());
    }

    private function migrate(): void
    {
        (require database_path(self::MIGRATION))->up();
    }

    private function plan(string $slug, int $price, bool $withOwnLines = false): Plan
    {
        $lines = fn (array $rows): array => array_map(fn (array $row): array => ['label' => $row[0], 'value' => $row[1], 'icon' => $row[2]], $rows);

        return Plan::factory()->create([
            'slug' => $slug, 'name' => ucfirst($slug), 'price_cents' => $price, 'voice_tier' => 'hd', 'is_active' => true,
            'monthly_chars' => 500000, 'monthly_requests' => 0, 'max_chars_per_request' => 5000,
            'voice_cloning_enabled' => true, 'voice_cloning_max_voices' => 4,
            'pricing_use_custom_text' => $withOwnLines,
            'features_en' => $withOwnLines ? $lines([['Generations per day', '200', 'activity'], ['Voice cloning', '4 voices', 'star'], ['Commercial use', 'Allowed', 'lock']]) : null,
            'features_ar' => $withOwnLines ? $lines([['التوليدات يوميًا', '200', 'activity'], ['استنساخ الأصوات', '4 أصوات', 'star'], ['الاستخدام التجاري', 'مسموح', 'lock']]) : null,
        ]);
    }

    /** @return array{0:bool, 1:int, 2:int, 3:bool} */
    private function numbers(Plan $plan): array
    {
        return [(bool) $plan->voice_design_enabled, (int) $plan->voice_design_max_voices, (int) $plan->voice_design_monthly_voices, (bool) $plan->voice_design_unlimited];
    }
}
