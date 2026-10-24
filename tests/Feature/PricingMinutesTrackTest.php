<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Support\DialectCounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pricing page: the visitor picks minutes on a voice track and sees the plan
 * that fits, which is that plan's own card as the admin set it up. Every monthly
 * plan is on the track and in the list under it; the recommended plan is shown first.
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

    public function test_every_plan_is_on_the_track_and_the_recommended_one_is_shown_first(): void
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

            // Each plan's card once; the recommended one shown, the others out of reach until picked.
            preg_match_all('/<div class="plan-card[^"]*"[^>]*data-plan-slug="([a-z_]+)"/', $html, $cards);
            $this->assertSame([], array_diff(['basic', 'starter', 'creator', 'pro'], $cards[1]));
            $this->assertSame(1, preg_match_all('/class="plan-card[^"]*is-active"/', $html));
            $this->assertMatchesRegularExpression('/class="plan-card popular[^"]*is-active" data-orb-host\s+data-plan-slug="starter"/', $html);
            $this->assertSame(3, preg_match_all('/data-orb-host\s+inert/', $html));

            // The heading is one colour, and the track is a voice in two layers: the chosen part fills in.
            $this->assertStringNotContainsString('class="accent"', $html);
            $this->assertStringContainsString('class="studio-bars-base"', $html);
            $this->assertStringContainsString('class="studio-bars-fill"', $html);

            // The orbs on the track and the list under the card pick a plan.
            $this->assertSame(4, substr_count($html, 'class="studio-station"'));
            $this->assertSame(4, substr_count($html, 'class="plan-pick"'));
            $this->assertStringContainsString(__('ui.pricing.all_plans'), $html);
        }
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
