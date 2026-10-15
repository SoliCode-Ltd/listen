<?php

namespace Tests\Feature;

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
        $this->assertSame(DialectCounts::arabic().'+ Dialects', __('ui.landing.section2_card1_metric', [], 'en'));
        $this->assertStringContainsString("\u{2066}+40\u{2069}", __('ui.landing.section2_card3_metric', [], 'ar'), 'the plus stays before the number in Arabic');
        $this->assertSame('40+ Styles', __('ui.landing.section2_card3_metric', [], 'en'));
    }
}
