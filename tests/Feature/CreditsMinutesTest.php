<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\UsageCounter;
use App\Support\Credits;
use App\Support\PlanUsageGuard;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A plan's credits (1 per character, spaces included) also set its voice
 * minutes for the month, and generation stops at whichever runs out first.
 */
class CreditsMinutesTest extends TestCase
{
    public function test_credits_give_voice_minutes(): void
    {
        $this->assertSame(900, Credits::PER_MINUTE);
        $this->assertSame(160, Credits::minutesFor(144000));
        $this->assertSame(0, Credits::minutesFor(500));
        $this->assertSame(600, Credits::secondsFor(9000));
    }

    public function test_generation_stops_when_the_voice_minutes_run_out_before_the_credits(): void
    {
        // 9,000 credits = 10 voice minutes.
        $plan = new Plan(['monthly_chars' => 9000, 'monthly_requests' => 0]);
        $counter = new UsageCounter(['total_chars' => 1000, 'total_requests' => 3, 'total_seconds' => 580, 'bonus_chars' => 0]);

        // 580 s used + 15 s = 595 s: still within 600 s.
        PlanUsageGuard::assertWithinLimits($plan, $counter, ['chars' => 50, 'seconds' => 15]);

        // Slow speech used the minutes first: credits are left but the audio is not.
        $counter->total_seconds = 595;
        try {
            PlanUsageGuard::assertWithinLimits($plan, $counter, ['chars' => 50, 'seconds' => 15]);
            $this->fail('The voice minutes were not enforced.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('10 voice minutes', $e->errors()['content'][0]);
        }
    }

    public function test_gifted_credits_also_give_minutes(): void
    {
        $plan = new Plan(['monthly_chars' => 9000, 'monthly_requests' => 0]);
        // 9,000 gifted credits double the month to 20 minutes.
        $counter = new UsageCounter(['total_chars' => 1000, 'total_requests' => 3, 'total_seconds' => 900, 'bonus_chars' => 9000]);

        PlanUsageGuard::assertWithinLimits($plan, $counter, ['chars' => 50, 'seconds' => 15]);
        $this->assertTrue(true);
    }

    public function test_the_old_overage_switch_no_longer_lifts_the_limit(): void
    {
        // Overage was never charged, so a plan with it switched on still stops at its credits.
        $plan = new Plan(['monthly_chars' => 9000, 'monthly_requests' => 0, 'allow_overage' => true]);
        $counter = new UsageCounter(['total_chars' => 8990, 'total_requests' => 3, 'total_seconds' => 10, 'bonus_chars' => 0]);

        $this->assertFalse(PlanUsageGuard::allowsOverage($plan));
        $this->expectException(ValidationException::class);
        PlanUsageGuard::assertWithinLimits($plan, $counter, ['chars' => 50, 'seconds' => 5]);
    }
}
