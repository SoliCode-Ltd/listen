<?php

namespace Tests\Feature;

use App\Support\AudioEffects;
use App\Support\DialectCounts;
use App\Support\StudioChoices;
use Tests\TestCase;

class LandingMetricsTest extends TestCase
{
    public function test_the_homepage_counts_are_true(): void
    {
        $this->assertGreaterThanOrEqual(140, DialectCounts::arabic(), '+140 spoken dialects');
        $this->assertGreaterThanOrEqual(40, count(StudioChoices::STYLES), '+40 styles');
        $this->assertSame("\u{2066}+".DialectCounts::arabic()."\u{2069} لهجة", __('ui.landing.section2_card1_metric', [], 'ar'));
        $this->assertSame(DialectCounts::arabic().'+ dialects', __('ui.landing.section2_card1_metric', [], 'en'));
        $this->assertStringContainsString("\u{2066}+40\u{2069}", __('ui.landing.section2_card3_metric', [], 'ar'), 'the plus stays before the number in Arabic');
        $this->assertSame('40+ styles', __('ui.landing.section2_card3_metric', [], 'en'));
    }

    public function test_the_tour_counts_sound_environments_not_places(): void
    {
        // Places are only one kind of environment, beside devices and creative ones.
        $environments = count(array_filter(AudioEffects::ALL, fn (array $row): bool => $row[1] !== 'clean'));
        $this->assertGreaterThan(20, $environments, 'more than 20 sound environments');
        $this->assertStringContainsString('20+ sound environments, from a stadium to a phone call.', __('ui.landing.tour_places_body', [], 'en'));
        $this->assertStringContainsString('أكثر من 20 بيئة صوتية، من الملعب إلى المكالمة الهاتفية.', __('ui.landing.tour_places_body', [], 'ar'));
        $this->assertStringNotContainsString('places', __('ui.landing.tour_places_body', [], 'en'));
        $this->assertStringNotContainsString('مكاناً', __('ui.landing.tour_places_body', [], 'ar'));
    }
}
