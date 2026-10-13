<?php

namespace Tests\Unit;

use App\Support\UsageEstimator;
use PHPUnit\Framework\TestCase;

class UsageEstimatorTest extends TestCase
{
    public function test_every_character_is_billable_spaces_and_line_breaks_too(): void
    {
        // Gemini bills every character it reads, so members are charged for them too.
        $this->assertSame(20, UsageEstimator::billableChars(" Hi, Test! 123 -- @#$ \n\t "));
        $this->assertSame(11, UsageEstimator::billableChars("مرحبا\nبالكل"));
        $this->assertSame(0, UsageEstimator::billableChars("  \n  "));
    }

    public function test_estimate_uses_every_character(): void
    {
        $estimate = UsageEstimator::estimate('@@@ ### ---');

        $this->assertSame(11, $estimate['chars']);
        $this->assertGreaterThanOrEqual(1, $estimate['minutes']);
        $this->assertGreaterThanOrEqual(3, $estimate['seconds']);
        // 1,500 characters at 15 a second: 100 seconds.
        $this->assertSame(100, UsageEstimator::estimate(str_repeat('a', 1500))['seconds']);
    }
}
