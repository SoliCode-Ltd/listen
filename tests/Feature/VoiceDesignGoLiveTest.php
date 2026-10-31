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
 * Voice Design going live (database/migrations/2026_11_09_130000_open_voice_design_on_creator_pro_studio_max.php):
 * Creator 10 designed voices in all, Pro 50, Studio Max no limit (deleted voices still count),
 * open to everyone, and each plan's own pricing-card lines saying so.
 */
class VoiceDesignGoLiveTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_11_09_130000_open_voice_design_on_creator_pro_studio_max.php';

    public function test_going_live_opens_it_on_the_three_plans_with_their_numbers_and_their_own_card_lines(): void
    {
        // Starter had it on to try (as on staging): going live switches it off.
        $starter = $this->plan('starter', 900);
        $starter->forceFill(['voice_design_enabled' => true, 'voice_design_max_voices' => 2, 'voice_design_monthly_voices' => 2])->save();
        $creator = $this->plan('creator', 2200, withOwnLines: true);
        $pro = $this->plan('pro', 4900, withOwnLines: true);
        $max = $this->plan('studio_max', 9900, withOwnLines: true);
        // Pro already had a Voice Design line in an earlier wording: it is replaced, not doubled.
        $pro->forceFill(['features_en' => array_merge($pro->features_en, [['label' => 'Voice Design', 'value' => '50 voices a month', 'icon' => 'sliders']])])->save();

        $this->migrate();
        $this->migrate(); // A second run changes nothing.

        $this->assertSame([true, 10, 0, false], $this->numbers($creator->fresh()));
        $this->assertSame([true, 50, 0, false], $this->numbers($pro->fresh()));
        $this->assertTrue($max->fresh()->voice_design_enabled);
        $this->assertTrue(VoiceDesign::isUnlimited($max->fresh()));
        $this->assertFalse($starter->fresh()->voice_design_enabled);
        $this->assertSame(['creator', 'pro', 'studio_max'], Plan::query()->where('voice_design_enabled', true)->orderBy('price_cents')->pluck('slug')->all());
        $this->assertSame(VoiceDesign::AUDIENCE_EVERYONE, VoiceDesign::audience());

        // Each plan's own lines: Voice Design right under voice cloning, once, in both languages.
        $this->assertSame(
            ['Generations per day', 'Voice cloning', 'Voice Design', 'Commercial use'],
            array_column($creator->fresh()->features_en, 'label'),
        );
        $this->assertSame(['label' => 'Voice Design', 'value' => '10 voices', 'icon' => 'sliders'], $creator->fresh()->features_en[2]);
        $this->assertSame(['label' => 'تصميم الأصوات', 'value' => '10 أصوات', 'icon' => 'sliders'], $creator->fresh()->features_ar[2]);
        $this->assertSame(
            ['Generations per day', 'Voice cloning', 'Commercial use', 'Voice Design'],
            array_column($pro->fresh()->features_en, 'label'),
        );
        $this->assertSame('50 voices', $pro->fresh()->features_en[3]['value']);
        $this->assertSame('50 صوتًا', $pro->fresh()->features_ar[2]['value']);
        $this->assertSame('Unlimited', $max->fresh()->features_en[2]['value']);
        $this->assertSame('غير محدود', $max->fresh()->features_ar[2]['value']);

        // The pricing card shows the plan's own lines, the designed voices among them.
        $this->assertContains('Voice Design', array_column(PlanPricingLines::forPlan($creator->fresh(), 'en'), 'label'));
        $this->assertContains('تصميم الأصوات', array_column(PlanPricingLines::forPlan($max->fresh(), 'ar'), 'label'));
    }

    public function test_each_plan_keeps_only_its_voices_in_all_and_deleting_one_gives_none_back(): void
    {
        $creator = $this->plan('creator', 2200);
        $pro = $this->plan('pro', 4900);
        $max = $this->plan('studio_max', 9900);
        $this->migrate();

        foreach ([[$creator, 10], [$pro, 50], [$max, null]] as [$plan, $voices]) {
            $member = $this->member($plan->fresh());
            // The plan's voices less one, two of them deleted again, all months ago: one more may still be kept.
            $this->keptVoices($member, ($voices ?? 120) - 1, deleted: 2, keptAt: now()->subMonths(3));
            $this->assertNull(VoiceDesign::keepBlocker((int) $member->id, $plan->fresh()), $plan->slug);
            $this->keptVoices($member, 1, deleted: 0, from: 1000);
            if ($voices === null) {
                $this->assertNull(VoiceDesign::keepBlocker((int) $member->id, $plan->fresh()), $plan->slug.' has no limit');
            } else {
                $this->assertSame(__('voice_design.errors.total_used', ['max' => $voices]), VoiceDesign::keepBlocker((int) $member->id, $plan->fresh()), $plan->slug);
            }
        }

        // The server refuses the keep itself, not just the page.
        $member = $this->member($creator->fresh());
        $this->keptVoices($member, 10, deleted: 3);
        $take = $this->keptVoices($member, 1, deleted: 0, from: 2000, draft: true);
        $this->actingAs($member)->postJsonLocalizedRoute('voice-design.keep', ['name' => 'One more'], ['id' => $take->id])
            ->assertStatus(422)->assertJson(['message' => __('voice_design.errors.total_used', ['max' => 10])]);
        $this->assertSame(DesignedVoice::DRAFT, $take->fresh()->status);
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
        $this->assertNotContains('تصميم الأصوات', array_column(PlanPricingLines::forPlan($creator->fresh(), 'ar'), 'label'));
        $this->assertContains('Voice cloning', array_column(PlanPricingLines::forPlan($creator->fresh(), 'en'), 'label'));
    }

    public function test_a_plan_with_no_limit_never_fills_up_and_says_unlimited(): void
    {
        $max = $this->plan('studio_max', 9900);
        $this->migrate();
        $member = $this->member($max->fresh());
        $this->keptVoices($member, 12, deleted: 0);

        $this->assertNull(VoiceDesign::keepBlocker((int) $member->id, $max->fresh()));
        $this->assertSame(0, VoiceDesign::monthlyLimit($max->fresh()));
        $this->assertSame('Unlimited', PlanPricingLines::value($max->fresh(), 'voice_design', 'en'));

        app()->setLocale('en');
        $page = $this->actingAs($member)->getLocalizedRoute('dashboard')->assertOk();
        $page->assertSee(__('voice_design.month_unlimited_caption'), false);
        $this->assertDoesNotMatchRegularExpression('/data-vd-limit role="status"(?!\s+hidden)/', $page->getContent());
    }

    private function member(Plan $plan): User
    {
        $member = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $member->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $member;
    }

    /** Voices the member kept ($deleted of them deleted again), or one take still to keep. */
    private function keptVoices(User $member, int $count, int $deleted, int $from = 0, bool $draft = false, ?\DateTimeInterface $keptAt = null): DesignedVoice
    {
        $last = null;
        foreach (range(1, $count) as $n) {
            $id = $from + $n;
            $last = DesignedVoice::query()->create([
                'user_id' => $member->id, 'status' => $draft ? DesignedVoice::DRAFT : DesignedVoice::SAVED, 'batch' => 'b'.$member->id.'-'.$id,
                'name' => $draft ? null : 'Voice '.$id, 'description' => 'A voice.', 'language' => 'en', 'provider_voice_key' => 'v'.$member->id.'-'.$id,
                'key_name' => 'GEMINI_TTS_API_KEY', 'take' => 1, 'kept_at' => $draft ? null : ($keptAt ?? now()),
                'metadata' => ['site' => VoiceDesignService::siteTag()],
            ]);
            if ($n <= $deleted) {
                $last->delete();
            }
        }

        return $last;
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
