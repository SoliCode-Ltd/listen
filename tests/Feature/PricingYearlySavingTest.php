<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The pricing page says what yearly billing saves before anyone presses the switch: a pill
 * inside the switch's yearly half with the share ("Save 20%"), and on yearly each card
 * shows, beside what the year costs, what it saves in money.
 */
class PricingYearlySavingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::query()->update(['is_active' => false]);
        foreach (['basic' => [400, 14000], 'starter' => [900, 180000], 'creator' => [2200, 450000]] as $slug => [$cents, $credits]) {
            $plan = Plan::query()->firstOrNew(['slug' => $slug]);
            $plan->forceFill(array_merge(Plan::factory()->raw(), [
                'slug' => $slug, 'name' => ucfirst($slug), 'kind' => Plan::KIND_SUBSCRIPTION, 'is_active' => true,
                'price_cents' => $cents, 'monthly_chars' => $credits, 'is_featured' => $slug === 'starter',
            ]))->save();
        }
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_the_switch_says_the_yearly_saving_in_both_languages(): void
    {
        $this->assertMatchesRegularExpression(
            '#<button type="button" id="yearlyBtn" aria-pressed="false">Yearly<span class="billing-save">Save 20%</span></button>#',
            $this->page('en'),
        );
        $this->assertMatchesRegularExpression(
            '#<button type="button" id="yearlyBtn" aria-pressed="false">سنوي<span class="billing-save">خصم 20%</span></button>#u',
            $this->page('ar'),
        );
    }

    public function test_the_pill_follows_the_yearly_discount_the_admin_sets(): void
    {
        $this->setYearlyDiscount(25);
        $this->assertStringContainsString('<span class="billing-save">Save 25%</span>', $this->page('en'));

        // No yearly discount: nothing to say, so no pill.
        $this->setYearlyDiscount(0);
        $this->assertStringNotContainsString('class="billing-save"', $this->page('en'));
    }

    public function test_each_card_carries_what_its_year_saves_in_money(): void
    {
        $html = $this->page('en');

        // Starter is $9 a month: twelve months are $108, the year is $86.40, so it saves $21.60;
        // the card shows that amount in the visitor's own currency.
        $this->assertMatchesRegularExpression('/id="plan-starter"[^>]*data-savings-cents="2160"/s', $html);
        preg_match('/id="plan-starter"[^>]*data-display-savings="([^"]*)"/s', $html, $saving);
        $this->assertMatchesRegularExpression('/\d/', html_entity_decode($saving[1] ?? ''));

        // The page writes it beside the year's total, in the visitor's language.
        $this->assertStringContainsString('"save_amount":"Save :amount"', $html);
        $this->assertStringContainsString("saving.className = 'price-saving'", $html);
        $this->assertStringContainsString('"save_amount":'.json_encode('وفّر :amount'), $this->page('ar'));
    }

    private function page(string $locale): string
    {
        app()->setLocale($locale);

        return (string) $this->getLocalizedRoute('pricing')->assertOk()->getContent();
    }

    private function setYearlyDiscount(int $percent): void
    {
        SiteSetting::set('billing_yearly_discount_percentage', (string) $percent, 'billing');
        Cache::flush();
    }
}
