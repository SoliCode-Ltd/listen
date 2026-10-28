<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Support\DialectCounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pricing page: every monthly plan as a card, side by side, then a calculator where
 * the visitor picks minutes on a voice track and sees the plan that fits. Every monthly
 * plan is a card, a row in the phones' list and a stop on the track; the calculator
 * starts on the recommended plan.
 */
class PricingMinutesTrackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::query()->update(['is_active' => false]);
        foreach (['basic' => [400, 14000], 'starter' => [900, 180000], 'creator' => [2200, 450000], 'pro' => [4900, 1125000]] as $slug => [$cents, $credits]) {
            $plan = Plan::query()->firstOrNew(['slug' => $slug]);
            $plan->forceFill(array_merge(Plan::factory()->raw(), [
                'slug' => $slug, 'name' => ucfirst($slug), 'kind' => Plan::KIND_SUBSCRIPTION, 'is_active' => true,
                'price_cents' => $cents, 'monthly_chars' => $credits, 'is_featured' => $slug === 'starter',
            ]))->save();
        }
    }

    public function test_every_plan_is_a_card_and_on_the_track_and_the_calculator_starts_on_the_recommended_one(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $html = $this->getLocalizedRoute('pricing')->assertOk()
                ->assertSee(__('ui.pricing.hero_title'))
                ->assertSee(__('ui.pricing.hero_title_accent'))
                ->assertSee(__('ui.pricing.minutes_label'))
                ->assertSee(__('ui.pricing.voices_proof', ['count' => DialectCounts::arabic() + DialectCounts::english()]))
                ->getContent();

            // The track: each plan with its minutes, fewest first.
            preg_match('/data-plans="([^"]+)"/', $html, $track);
            $plans = json_decode(html_entity_decode($track[1]), true);
            $this->assertSame(['basic', 'starter', 'creator', 'pro'], array_column($plans, 'slug'));
            $this->assertSame([15, 200, 500, 1250], array_column($plans, 'minutes'));

            // Each plan's card once, all of them in reach, in the order the admin set; the
            // recommended one with its badge.
            preg_match_all('/<div class="plan-card[^"]*"[^>]*data-plan-slug="([a-z_]+)"/', $html, $cards);
            $this->assertSame(['basic', 'starter', 'creator', 'pro'], $cards[1]);
            $this->assertStringContainsString('<div class="plan-grid" id="planGrid" data-count="4">', $html);
            $this->assertSame(0, preg_match_all('/<div class="plan-card[^>]*\binert\b/', $html));
            $this->assertMatchesRegularExpression('/class="plan-card popular[^"]*" data-orb-host\s+id="plan-starter"/', $html);
            $this->assertSame(1, substr_count($html, 'class="popular-badge"'));

            // The calculator starts on the recommended plan; the other plans wait out of reach.
            preg_match_all('/class="fit-plan( is-active)?" data-fit-slug="([a-z_]+)" data-orb-host\s*(inert)?/', $html, $fits);
            $this->assertSame(['basic', 'starter', 'creator', 'pro'], $fits[2]);
            $this->assertSame(['', ' is-active', '', ''], $fits[1]);
            $this->assertSame(['inert', '', 'inert', 'inert'], $fits[3]);

            // The heading is one colour, and the track is a voice in two layers: the chosen part fills in.
            $this->assertStringNotContainsString('class="accent"', $html);
            $this->assertStringContainsString('class="studio-bars-base"', $html);
            $this->assertStringContainsString('class="studio-bars-fill"', $html);

            // The orbs on the track pick a plan; each name in the phones' switch goes to its card.
            $this->assertSame(4, substr_count($html, 'class="studio-station"'));
            preg_match_all('/class="plan-tab( is-popular)?" href="#plan-([a-z_]+)"/', $html, $tabs);
            $this->assertSame(['basic', 'starter', 'creator', 'pro'], $tabs[2]);
            $this->assertSame([' is-popular'], array_values(array_filter($tabs[1])));
            $this->assertStringContainsString(__('ui.pricing.all_plans'), $html);

            // Every line is on the card; on phones a card with more than five folds after four,
            // with a button for the rest.
            preg_match_all('/<ul class="plan-features( is-long)?" id="plan-lines-([a-z_]+)">(.*?)<\/ul>\s*(<button type="button" class="plan-more")?/s', $html, $lists, PREG_SET_ORDER);
            $this->assertCount(4, $lists);
            foreach ($lists as [, $long, $slug, $items]) {
                $count = preg_match_all('/<li[\s>]/', $items);
                $button = str_contains(substr($html, strpos($html, 'id="plan-lines-'.$slug.'"'), 4000), 'aria-controls="plan-lines-'.$slug.'"');
                $this->assertSame($count > 5, $long === ' is-long', $slug);
                $this->assertSame($count > 5, $button, $slug);
            }
        }
    }

    public function test_phones_compare_two_plans_starting_with_the_one_on_the_track(): void
    {
        $html = $this->getLocalizedRoute('pricing')->assertOk()->getContent();

        // Two pickers above the table; the plan the calculator starts on and the next one up come first.
        $this->assertSame(2, substr_count($html, 'class="compare-pick"'));
        // A tap reaches the select (it opens the phone's own list): the face under it lets taps through.
        $this->assertMatchesRegularExpression('/\.compare-pick-face \{[^}]*pointer-events: none;/', $html);
        $this->assertMatchesRegularExpression('/\.compare-pick \{[^}]*z-index: 1;/', $html);
        preg_match('/class="compare-table" data-pick="(\d+),(\d+)"/', $html, $pick);
        preg_match_all('/class="compare-plan[^"]*" data-col="(\d+)" data-slug="([a-z_]+)"/', $html, $columns);
        $slugs = array_combine($columns[1], $columns[2]);
        $this->assertSame('starter', $slugs[$pick[1]]);
        $this->assertSame('creator', $slugs[$pick[2]]);
    }

    public function test_a_member_on_the_featured_plan_still_sees_its_crown_and_badge(): void
    {
        $member = \App\Models\User::factory()->create();
        app(\App\Services\PlanAccessService::class)->assignPlan($member, Plan::query()->where('slug', 'starter')->firstOrFail());

        $html = $this->actingAs($member)->getLocalizedRoute('pricing')->assertOk()->getContent();

        // Their own plan stays the featured one: its badge on the card, the crown on the track
        // and in the phones' switch, and the calculator starts on it.
        $this->assertMatchesRegularExpression('/class="plan-card popular[^"]*is-current[^"]*" data-orb-host\s+id="plan-starter"/', $html);
        $this->assertSame(1, substr_count($html, 'class="popular-badge"'));
        $this->assertStringContainsString('class="fit-plan is-active" data-fit-slug="starter"', $html);
        $this->assertSame(1, substr_count($html, 'class="crown studio-station-crown"'));
        $this->assertSame(1, substr_count($html, 'class="plan-tab is-popular"'));
        preg_match('/data-plans="([^"]+)"/', $html, $track);
        $this->assertSame(['starter'], array_column(array_filter(json_decode(html_entity_decode($track[1]), true), fn ($p) => $p['popular']), 'slug'));
    }

    public function test_the_page_never_shows_a_price_per_minute(): void
    {
        foreach (['en' => ['per minute', '/min'], 'ar' => ['للدقيقة', 'لكل دقيقة']] as $locale => $phrases) {
            app()->setLocale($locale);
            $html = $this->getLocalizedRoute('pricing')->assertOk()->getContent();
            foreach ($phrases as $phrase) {
                $this->assertStringNotContainsString($phrase, $html, $locale);
            }
        }
    }
}
