<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Support\Orbs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceOrbsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_homepage_tour_has_an_orb_for_each_kind_of_voice_with_a_sample_to_hear(): void
    {
        $this->artisan('tts:showcase', ['--import' => true]);

        app()->setLocale('en');
        $en = $this->getLocalizedRoute('home')->assertOk();
        $tour = $en->viewData('voiceTour');
        // English on the English page only.
        $this->assertSame(['english', 'emotions', 'dialogue', 'places'], array_column($tour, 'key'));
        // Every orb plays a real sample: nothing in the tour is silent.
        foreach ($tour as $item) {
            $this->assertNotSame('', $item['src'], $item['key']);
        }
        $this->assertStringEndsWith('#english-accents', $tour[0]['more']);
        $en->assertDontSee('data-orb="arabic"', false);
        $en->assertDontSee('data-orb="cloning"', false);
        $en->assertSee('Every kind of voice, in one studio');
        $en->assertSee('data-orb="places"', false);
        $en->assertSee('js/listen-orbs.js', false);
        // The hero's centre mark is the Listen orb.
        $en->assertSee('class="orb vs-cast-orb"', false);

        app()->setLocale('ar');
        $ar = $this->getLocalizedRoute('home')->assertOk();
        // Arabic on the Arabic page only.
        $this->assertSame(['arabic', 'emotions', 'dialogue', 'places'], array_column($ar->viewData('voiceTour'), 'key'));
        $ar->assertDontSee('data-orb="english"', false);
        $ar->assertSee('كل أنواع الأصوات في استوديو واحد');
    }

    public function test_each_plan_card_has_its_orb_and_the_popular_one_moves(): void
    {
        Plan::factory()->create(['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 10000, 'monthly_requests' => 50, 'max_chars_per_request' => 1200]);
        foreach (['starter' => 900, 'creator' => 2200, 'pro' => 4900, 'studio_max' => 9900] as $slug => $cents) {
            Plan::factory()->create(['slug' => $slug, 'name' => ucfirst($slug), 'price_cents' => $cents, 'monthly_chars' => $cents * 100, 'monthly_requests' => 0, 'max_chars_per_request' => 5000, 'is_active' => true]);
        }

        app()->setLocale('en');
        $page = $this->getLocalizedRoute('pricing')->assertOk();

        $html = $page->getContent();
        preg_match_all('/data-plan-slug="([a-z_]+)"/', $html, $slugs);
        $this->assertSame(['starter', 'creator', 'pro', 'studio_max'], array_values(array_unique($slugs[1])));
        foreach (array_unique($slugs[1]) as $slug) {
            $this->assertStringContainsString('data-orb="'.Orbs::forPlan($slug).'"', $html, $slug);
        }
        // Only the recommended plan's orb is alive; its card is held in a violet ring, not filled.
        $this->assertSame(1, substr_count($html, 'plan-orb is-live'));
        $page->assertSee('box-shadow: 0 0 0 2px var(--brand-accent)', false);
    }
}
