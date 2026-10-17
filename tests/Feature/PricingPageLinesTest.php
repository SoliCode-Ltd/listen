<?php

namespace Tests\Feature;

use App\Filament\Resources\PlanResource\Pages\CreatePlan;
use App\Filament\Resources\PlanResource\Pages\EditPlan;
use App\Filament\Resources\PlanResource\Pages\ViewPlan;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\PlanPricingLines;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The lines on each pricing card are written from the plan's settings in
 * English and Arabic. The admin picks which ones show, adds extra lines, or
 * switches the plan back to hand-written lists.
 */
class PricingPageLinesTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────
    //  Lines written from the settings
    // ──────────────────────────────────────────────────────────────────

    public function test_a_premium_plan_gets_every_line_in_english_and_arabic(): void
    {
        $plan = $this->premiumPlan();

        $this->assertSame([
            'Voice quality' => 'Premium',
            // 320,000 credits at 900 a minute.
            'Voice usage minutes' => '355 min',
            'Max text per generation' => '5,000 characters',
            'Generations per month' => 'Unlimited',
            'Emotion, style & accent' => 'Full control',
            'Processing priority' => 'High',
            'Commercial use' => 'Allowed',
            'Audio history' => '30 days',
            'Multi-speaker dialogue' => '✓',
            'Custom voice direction' => '✓',
            'Download formats' => 'MP3, WAV',
        ], $this->lines($plan, 'en'));

        $this->assertSame([
            'جودة الصوت' => 'متميزة',
            'دقائق الصوت' => '355 دقيقة',
            'أقصى نص في المرة الواحدة' => '5,000 حرف',
            'التوليدات شهريًا' => 'غير محدود',
            'المشاعر والأسلوب واللهجة' => 'تحكم كامل',
            'أولوية المعالجة' => 'عالية',
            'الاستخدام التجاري' => 'مسموح',
            'سجل الأصوات' => '30 يومًا',
            'حوار بأكثر من متحدث' => '✓',
            'توجيه مخصص للأداء' => '✓',
            'صيغ التحميل' => 'MP3, WAV',
        ], $this->lines($plan, 'ar'));
    }

    public function test_a_standard_plan_leaves_out_what_it_cannot_do(): void
    {
        $plan = Plan::factory()->create([
            'voice_tier' => 'standard',
            'monthly_chars' => 125000,
            'max_chars_per_request' => 1200,
            'monthly_requests' => 50,
            'processing_priority' => 'standard',
            'commercial_use_allowed' => false,
            'recent_voices_days' => 7,
            // Custom direction only works with the premium voice.
            'custom_prompt_enabled' => true,
        ]);

        $this->assertSame([
            'Voice quality' => 'Standard',
            'Voice usage minutes' => '138 min',
            'Max text per generation' => '1,200 characters',
            'Generations per month' => '50',
            'Processing priority' => 'Standard',
            'Commercial use' => 'Personal only',
            'Audio history' => '7 days',
            'Download formats' => 'MP3',
        ], $this->lines($plan, 'en'));
        $this->assertSame('7 أيام', $this->lines($plan, 'ar')['سجل الأصوات']);
        $this->assertSame('شخصي فقط', $this->lines($plan, 'ar')['الاستخدام التجاري']);
    }

    public function test_changing_a_setting_changes_the_card(): void
    {
        $plan = $this->premiumPlan();

        $plan->update(['max_chars_per_request' => 8000, 'recent_voices_days' => 0]);

        $this->assertSame('8,000 characters', $this->lines($plan, 'en')['Max text per generation']);
        $this->assertSame('No time limit', $this->lines($plan, 'en')['Audio history']);
        $this->assertSame('بدون حد زمني', $this->lines($plan, 'ar')['سجل الأصوات']);
    }

    public function test_voice_cloning_shows_only_once_members_can_use_it(): void
    {
        SiteSetting::set('voice_cloning_enabled', '1');
        $plan = $this->premiumPlan(['voice_cloning_enabled' => true, 'voice_cloning_max_voices' => 3]);

        SiteSetting::set('voice_cloning_audience', 'preview');
        $this->assertArrayNotHasKey('Voice cloning', $this->lines($plan, 'en'), 'a private preview is not sold on the card');
        $this->assertStringContainsString('private preview', (string) PlanPricingLines::whyHidden($plan, 'voice_cloning'));

        SiteSetting::set('voice_cloning_audience', 'everyone');
        $this->assertSame('3 voices', $this->lines($plan, 'en')['Voice cloning']);
        $this->assertSame('3 أصوات', $this->lines($plan, 'ar')['استنساخ الأصوات']);
    }

    // ──────────────────────────────────────────────────────────────────
    //  The admin's choices
    // ──────────────────────────────────────────────────────────────────

    public function test_a_line_switched_off_leaves_the_card(): void
    {
        $plan = $this->premiumPlan(['pricing_lines' => ['commercial_use' => false, 'download_formats' => false]]);

        $lines = $this->lines($plan, 'en');
        $this->assertArrayNotHasKey('Commercial use', $lines);
        $this->assertArrayNotHasKey('Download formats', $lines);
        $this->assertArrayHasKey('Voice quality', $lines, 'lines not mentioned keep their default');
    }

    public function test_the_top_up_price_is_off_until_ticked(): void
    {
        $plan = $this->premiumPlan(['topup_char_price_cents' => 2400]);
        $this->assertArrayNotHasKey('Credit top-up', $this->lines($plan, 'en'));

        $plan->update(['pricing_lines' => ['topup' => true]]);
        $this->assertSame('$24 per 1M credits', $this->lines($plan->fresh(), 'en')['Credit top-up']);
        $this->assertSame('$24 لكل مليون رصيد', $this->lines($plan->fresh(), 'ar')['تعبئة رصيد']);
    }

    public function test_extra_lines_follow_the_automatic_ones(): void
    {
        $plan = $this->premiumPlan([
            'pricing_lines' => array_fill_keys(array_keys(PlanPricingLines::LINES), false),
            'pricing_extra_lines_en' => [
                ['label' => 'Arabic and English voices', 'value' => '', 'icon' => 'globe'],
                ['label' => '  ', 'value' => 'skipped', 'icon' => 'check'],
                ['label' => 'Support', 'value' => 'WhatsApp', 'icon' => 'not-an-icon'],
            ],
            'pricing_extra_lines_ar' => [['label' => 'أصوات عربية وإنجليزية', 'value' => null, 'icon' => 'globe']],
        ]);

        $this->assertSame([
            ['label' => 'Arabic and English voices', 'value' => '✓', 'icon' => 'globe'],
            ['label' => 'Support', 'value' => 'WhatsApp', 'icon' => 'check'],
        ], PlanPricingLines::forPlan($plan, 'en'));
        $this->assertSame([['label' => 'أصوات عربية وإنجليزية', 'value' => '✓', 'icon' => 'globe']], PlanPricingLines::forPlan($plan, 'ar'));
    }

    public function test_own_text_is_used_only_while_its_switch_is_on(): void
    {
        $own = [['label' => 'Hand written', 'value' => 'Yes', 'icon' => 'star']];
        $plan = $this->premiumPlan(['features_en' => $own]);

        $this->assertArrayHasKey('Voice quality', $this->lines($plan, 'en'), 'off by default: the old hand-written list is ignored');

        $plan->update(['pricing_use_custom_text' => true]);
        $this->assertSame($own, PlanPricingLines::forPlan($plan->fresh(), 'en'));
        // No Arabic list written: the Arabic card keeps its automatic lines.
        $this->assertArrayHasKey('جودة الصوت', $this->lines($plan->fresh(), 'ar'));
    }

    public function test_the_pricing_page_shows_the_automatic_lines(): void
    {
        $plan = $this->premiumPlan([
            'features_en' => [['label' => 'Stale hand-written line', 'value' => 'x', 'icon' => 'check']],
            'features_ar' => [['label' => 'سطر قديم مكتوب يدويًا', 'value' => 'x', 'icon' => 'check']],
            'pricing_extra_lines_en' => [['label' => 'Owner extra line', 'value' => '', 'icon' => 'check']],
            'pricing_extra_lines_ar' => [['label' => 'سطر إضافي من المالك', 'value' => '', 'icon' => 'check']],
        ]);
        $locale = app()->getLocale();

        $page = $this->getLocalizedRoute('pricing')->assertOk();

        $page->assertSee(__('ui.pricing.lines.label_max_chars_per_request', [], $locale));
        // The card shows the credits with their icon, and the voice minutes they give.
        $page->assertSee('class="credit-icon plan-quota-coin"', false);
        $page->assertSee('<strong class="plan-quota-number">320,000</strong>', false);
        $page->assertSee('<span class="plan-quota-unit">'.__('ui.pricing.characters_per_month', [], $locale).'</span>', false);
        // The minutes lead the quota, above the credits.
        $page->assertSee('<strong class="plan-minutes-number">355</strong>', false);
        $page->assertSee(__('ui.pricing.minutes_per_month', [], $locale));
        $page->assertSee($locale === 'ar' ? 'سطر إضافي من المالك' : 'Owner extra line');
        $page->assertDontSee('Stale hand-written line');
        $page->assertDontSee('سطر قديم مكتوب يدويًا');

        $plan->update(['pricing_use_custom_text' => true]);

        $this->getLocalizedRoute('pricing')->assertOk()
            ->assertSee($locale === 'ar' ? 'سطر قديم مكتوب يدويًا' : 'Stale hand-written line')
            ->assertDontSee(__('ui.pricing.lines.label_max_chars_per_request', [], $locale));
    }

    // ──────────────────────────────────────────────────────────────────
    //  Admin form
    // ──────────────────────────────────────────────────────────────────

    public function test_the_admin_unticks_a_line_and_adds_extra_ones(): void
    {
        $own = [['label' => 'Hand written', 'value' => 'Yes', 'icon' => 'star']];
        $plan = $this->premiumPlan(['features_en' => $own, 'features_ar' => $own]);
        $this->asAdmin();

        $page = Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->assertFormFieldExists('pricing_lines')
            ->assertFormFieldIsHidden('features_en')
            ->assertSee('5,000 characters');

        $shown = $page->get('data.pricing_lines');
        $this->assertSame(PlanPricingLines::defaultShown(), $shown);

        $page->fillForm([
            'pricing_lines' => array_values(array_diff($shown, ['commercial_use'])),
            'pricing_extra_lines_en' => [['label' => 'Arabic and English voices', 'value' => '', 'icon' => 'globe']],
            'pricing_extra_lines_ar' => [['label' => 'أصوات عربية وإنجليزية', 'value' => '', 'icon' => 'globe']],
        ])
            ->call('save')
            ->assertHasNoFormErrors();

        $plan->refresh();
        $this->assertFalse($plan->pricing_lines['commercial_use']);
        $this->assertTrue($plan->pricing_lines['voice_quality']);
        $this->assertFalse($plan->pricing_lines['topup']);
        $this->assertSame('Arabic and English voices', $plan->pricing_extra_lines_en[0]['label']);
        $this->assertSame($own, $plan->features_en, 'hand-written lists stay saved while their switch is off');
        $this->assertSame($own, $plan->features_ar);
        $this->assertArrayNotHasKey('Commercial use', $this->lines($plan, 'en'));
    }

    public function test_the_admin_switches_to_own_text(): void
    {
        $plan = $this->premiumPlan();
        $this->asAdmin();

        $page = Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->fillForm(['pricing_use_custom_text' => true])
            ->assertFormFieldIsVisible('features_en')
            ->assertFormFieldIsHidden('pricing_lines');

        // The empty list starts from the automatic lines, ready to edit.
        $this->assertSame('Voice quality', collect($page->get('data.features_en'))->first()['label']);

        $page->fillForm([
            'features_en' => [['label' => 'My own line', 'value' => 'Yes', 'icon' => 'star']],
        ])->call('save')->assertHasNoFormErrors();

        $plan->refresh();
        $this->assertTrue($plan->pricing_use_custom_text);
        $this->assertSame([['label' => 'My own line', 'value' => 'Yes', 'icon' => 'star']], PlanPricingLines::forPlan($plan, 'en'));
    }

    public function test_the_view_page_shows_the_preview(): void
    {
        $plan = $this->premiumPlan();
        $this->asAdmin();

        Livewire::test(ViewPlan::class, ['record' => $plan->getRouteKey()])
            ->assertOk()
            ->assertSee('Max text per generation')
            ->assertSee('أقصى نص في المرة الواحدة');
    }

    public function test_a_new_plan_starts_with_the_default_lines(): void
    {
        $this->asAdmin();

        Livewire::test(CreatePlan::class)
            ->assertSet('data.pricing_lines', PlanPricingLines::defaultShown())
            ->assertSet('data.pricing_use_custom_text', false);
    }

    /**
     * @return array<string, string>
     */
    private function lines(Plan $plan, string $locale): array
    {
        return collect(PlanPricingLines::forPlan($plan, $locale))->pluck('value', 'label')->all();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function premiumPlan(array $overrides = []): Plan
    {
        return Plan::factory()->create(array_merge([
            'slug' => 'creator',
            'price_cents' => 1900,
            'monthly_chars' => 320000,
            'voice_tier' => 'hd',
            'max_chars_per_request' => 5000,
            'monthly_requests' => 0,
            'processing_priority' => 'high',
            'commercial_use_allowed' => true,
            'recent_voices_days' => 30,
            'dialogue_enabled' => true,
            'custom_prompt_enabled' => true,
            'download_formats' => ['mp3', 'wav'],
            'allow_overage' => false,
        ], $overrides));
    }

    private function asAdmin(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }
}
