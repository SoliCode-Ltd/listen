<?php

namespace Tests\Unit;

use App\Support\UsageEstimator;
use PHPUnit\Framework\TestCase;

class UsageEstimatorTest extends TestCase
{
    public function test_billable_chars_count_letters_and_numbers_only(): void
    {
        $text = " Hi, Test! 123 -- @#$ \n\t ";

        $this->assertSame(9, UsageEstimator::billableChars($text));
    }

    public function test_estimate_uses_billable_char_count(): void
    {
        $estimate = UsageEstimator::estimate('@@@ ### ---');

        $this->assertSame(0, $estimate['chars']);
        $this->assertGreaterThanOrEqual(1, $estimate['minutes']);
        $this->assertGreaterThanOrEqual(3, $estimate['seconds']);
    }
}
