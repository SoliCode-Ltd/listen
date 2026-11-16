<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Support\PlanPricingLines;
use App\Support\Transcription\TranscriptionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pricing card's speech to text line comes from the plan's own minutes
 * (Plans → Speech to text), in English and Arabic, with nothing written by
 * hand: on automatic cards, on hand-written ones (added unless the text
 * already names it) and in the comparison table. It shows once speech to
 * text is open to everyone, and never on a plan without it.
 */
class TranscriptionPricingLineTest extends TestCase
{
    use RefreshDatabase;

    private function plan(int $minutes, array $attributes = []): Plan
    {
        return Plan::factory()->create($attributes + ['slug' => 'stt-creator', 'name' => 'Creator', 'price_cents' => 2200, 'is_active' => true,
            'transcription_enabled' => $minutes > 0, 'transcription_minutes_month' => $minutes, 'transcription_max_file_minutes' => 60]);
    }

    private function open(): void
    {
        SiteSetting::set('transcription_audience', TranscriptionSettings::AUDIENCE_EVERYONE, TranscriptionSettings::GROUP);
    }

    private static function line(array $lines, string $label): ?string
    {
        return collect($lines)->firstWhere('label', $label)['value'] ?? null;
    }

    public function test_the_card_shows_the_plans_own_minutes_in_both_languages(): void
    {
        $this->open();
        $plan = $this->plan(250);

        $this->assertSame('250 minutes', self::line(PlanPricingLines::forPlan($plan, 'en'), 'Speech to text'));
        $this->assertSame('250 دقيقة', self::line(PlanPricingLines::forPlan($plan, 'ar'), 'تحويل الكلام إلى نص'));
        $this->assertSame('250 minutes', self::line(PlanPricingLines::forComparison($plan, 'en'), 'Speech to text'));

        // A change on the plan shows at once.
        $plan->forceFill(['transcription_minutes_month' => 500])->save();
        $this->assertSame('500 minutes', self::line(PlanPricingLines::forPlan($plan->fresh(), 'en'), 'Speech to text'));
        $this->assertSame('5 دقائق', trans_choice('ui.pricing.lines.stt_minutes', 5, ['count' => 5], 'ar'));
    }

    public function test_not_on_a_plan_without_it_and_not_before_it_is_open_to_everyone(): void
    {
        $plan = $this->plan(120);
        $this->assertNull(self::line(PlanPricingLines::forPlan($plan, 'en'), 'Speech to text'), 'still for the admin only');
        $this->assertStringContainsString('not open to everyone', PlanPricingLines::whyHidden($plan, 'speech_to_text'));

        $this->open();
        $basic = $this->plan(0, ['slug' => 'stt-basic', 'name' => 'Basic', 'price_cents' => 900]);
        $this->assertNull(self::line(PlanPricingLines::forPlan($basic, 'en'), 'Speech to text'));
        $this->assertSame('Hidden: this plan does not include speech to text.', PlanPricingLines::whyHidden($basic, 'speech_to_text'));
    }

    public function test_a_hand_written_card_gets_the_line_without_editing_it(): void
    {
        $this->open();
        $plan = $this->plan(120, [
            'pricing_use_custom_text' => true,
            'features_en' => [['label' => 'Voice quality', 'value' => 'Premium', 'icon' => 'mic'], ['label' => 'Developer API', 'value' => 'Included', 'icon' => 'globe']],
            'features_ar' => [['label' => 'جودة الصوت', 'value' => 'مميزة', 'icon' => 'mic']],
        ]);

        $en = PlanPricingLines::forPlan($plan, 'en');
        $this->assertSame(['Voice quality', 'Speech to text', 'Developer API'], array_column($en, 'label'), 'before the API line');
        $this->assertSame(['جودة الصوت', 'تحويل الكلام إلى نص'], array_column(PlanPricingLines::forPlan($plan, 'ar'), 'label'), 'at the end');

        // Text that already names it is left as written.
        $plan->forceFill(['features_en' => [['label' => 'Speech to text', 'value' => '2 hours', 'icon' => 'type']]])->save();
        $this->assertSame([['label' => 'Speech to text', 'value' => '2 hours', 'icon' => 'type']], PlanPricingLines::forPlan($plan->fresh(), 'en'));
    }
}
