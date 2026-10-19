<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The side player's speed button says 1x, 1.25x, 1.5x and 2x, and each plays
 * at that rate: it played 1.25x at 1.08, 1.5x at 1.16 and 2x at 1.28.
 */
class ExtensionPlayerSpeedTest extends TestCase
{
    public function test_each_speed_plays_at_the_rate_its_label_says(): void
    {
        $js = (string) file_get_contents(public_path('extensions/chrome-listen-reader/content.js'));

        preg_match_all("/\{ label: '([\d.]+)x', playbackRate: ([\d.]+) \}/", $js, $speeds, PREG_SET_ORDER);
        $this->assertSame(['1', '1.25', '1.5', '2'], array_column($speeds, 1));
        foreach ($speeds as [, $label, $rate]) {
            $this->assertSame((float) $label, (float) $rate, "{$label}x");
        }

        // And nothing holds the rate below what was chosen.
        $this->assertSame(1, preg_match('/const preferred = clamp\(Number\(currentSpeedOption\(\)\.playbackRate \|\| 1\), ([\d.]+), ([\d.]+)\);/', $js, $clamp));
        $this->assertLessThanOrEqual(1.0, (float) $clamp[1]);
        $this->assertGreaterThanOrEqual(2.0, (float) $clamp[2]);
    }
}
