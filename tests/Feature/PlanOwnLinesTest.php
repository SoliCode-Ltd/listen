<?php

namespace Tests\Feature;

use App\Filament\Resources\PlanResource\Pages\EditPlan;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lines the admin writes for a plan's card (Admin → Plans → "Use my own text
 * instead") show on that card in both languages, and are put in order by
 * dragging them.
 */
class PlanOwnLinesTest extends TestCase
{
    use RefreshDatabase;

    public function test_hand_written_lines_show_on_every_card_even_when_the_plans_share_them(): void
    {
        // The Arabic is the same on both plans, the English is not.
        $this->plan('starter', 900, [['label' => 'Voice quality', 'value' => 'HD', 'icon' => 'mic']], [['label' => 'جودة الصوت', 'value' => 'متميزة', 'icon' => 'mic']]);
        $this->plan('creator', 2200, [['label' => 'Voice quality', 'value' => 'HD+', 'icon' => 'mic']], [['label' => 'جودة الصوت', 'value' => 'متميزة', 'icon' => 'mic']]);

        app()->setLocale('ar');
        $page = $this->getLocalizedRoute('pricing')->assertOk()->getContent();
        foreach (['starter', 'creator'] as $slug) {
            $this->assertStringContainsString('جودة الصوت', $this->cardLines($page, $slug));
            $this->assertStringContainsString('متميزة', $this->cardLines($page, $slug));
        }

        app()->setLocale('en');
        $page = $this->getLocalizedRoute('pricing')->assertOk()->getContent();
        $this->assertStringContainsString('HD', $this->cardLines($page, 'starter'));
        $this->assertStringContainsString('HD+', $this->cardLines($page, 'creator'));
    }

    public function test_automatic_lines_every_plan_shares_are_still_listed_once_under_the_cards(): void
    {
        $this->plan('starter', 900);
        $this->plan('creator', 2200);
        app()->setLocale('en');

        $page = $this->getLocalizedRoute('pricing')->assertOk()->getContent();

        // Both plans take 5,000 characters at a time: that line is in the table, not on the cards.
        $label = __('ui.pricing.lines.label_max_chars_per_request');
        $this->assertStringNotContainsString($label, $this->cardLines($page, 'starter'));
        $this->assertStringContainsString($label, substr($page, (int) strpos($page, 'class="compare-table"')));
    }

    public function test_the_lines_can_be_dragged_into_order(): void
    {
        $plan = $this->plan('creator', 2200, [['label' => 'Voice quality', 'value' => 'HD', 'icon' => 'mic']], [['label' => 'التوليدات يوميا', 'value' => '200', 'icon' => 'activity']]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $html = Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])->html();

        // Drag handles on the English list, the Arabic list and both extra-line lists.
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'x-sortable-handle'));
        // A collapsed line still says what it is, so it can be found and dragged.
        $this->assertStringContainsString('التوليدات يوميا — 200', $html);

        $plan->update(['pricing_use_custom_text' => false, 'pricing_extra_lines_en' => [['label' => 'Arabic and English voices', 'value' => '', 'icon' => 'globe']]]);
        $html = Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])->html();
        $this->assertStringContainsString('x-sortable-handle', $html);
    }

    public function test_a_dragged_line_is_saved_in_its_new_place(): void
    {
        $plan = $this->plan('creator', 2200,
            [['label' => 'Voice quality', 'value' => 'HD', 'icon' => 'mic'], ['label' => 'Generations per day', 'value' => '200', 'icon' => 'activity']],
            [['label' => 'جودة الصوت', 'value' => 'متميزة', 'icon' => 'mic'], ['label' => 'التوليدات يوميا', 'value' => '200', 'icon' => 'activity']]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $page = Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()]);
        foreach (['features_en', 'features_ar'] as $list) {
            // The browser sends the lines' keys in their new order.
            $page->callFormComponentAction($list, 'reorder', arguments: ['items' => array_reverse(array_keys($page->get('data.'.$list)))])
                ->assertHasNoFormErrors();
        }
        $page->call('save')->assertHasNoFormErrors();

        $plan->refresh();
        $this->assertSame(['Generations per day', 'Voice quality'], array_column($plan->features_en, 'label'));
        $this->assertSame(['التوليدات يوميا', 'جودة الصوت'], array_column($plan->features_ar, 'label'));
    }

    /**
     * @param  array<int, array<string, string>>|null  $english
     * @param  array<int, array<string, string>>|null  $arabic
     */
    private function plan(string $slug, int $priceCents, ?array $english = null, ?array $arabic = null): Plan
    {
        return Plan::factory()->create([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'price_cents' => $priceCents,
            'monthly_chars' => $priceCents * 300,
            'voice_tier' => 'hd',
            'max_chars_per_request' => 5000,
            'is_active' => true,
            'pricing_use_custom_text' => $english !== null,
            'features_en' => $english,
            'features_ar' => $arabic,
        ]);
    }

    /**
     * The text of the lines on one plan's card.
     */
    private function cardLines(string $page, string $slug): string
    {
        $start = strpos($page, 'data-plan-slug="'.$slug.'"');
        $this->assertNotFalse($start, "The page has the {$slug} card.");
        $list = strpos($page, '<ul class="plan-features', $start);
        $end = strpos($page, '</ul>', (int) $list);

        return trim((string) preg_replace('/\s+/u', ' ', strip_tags(substr($page, (int) $list, $end - $list))));
    }
}
