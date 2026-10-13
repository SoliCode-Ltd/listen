<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\UsageCounter;
use App\Support\Credits;
use App\Support\PlanUsageGuard;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Credits are charged for the audio made (15 a second), so a plan's credits
 * and its voice minutes always match.
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

    public function test_credits_are_charged_for_audio_so_they_always_match_the_minutes(): void
    {
        // 15 credits a second: 160 minutes of audio uses exactly 144,000 credits.
        $this->assertSame(144000, Credits::forSeconds(160 * 60));
        $this->assertSame(Credits::minutesFor(144000) * 60, intdiv(Credits::forSeconds(160 * 60), Credits::PER_SECOND));

        // A plan of 9,000 credits (10 minutes) with 580 s used (8,700 credits).
        $plan = new Plan(['monthly_chars' => 9000, 'monthly_requests' => 0]);
        $counter = new UsageCounter(['total_chars' => 8700, 'total_requests' => 3, 'total_seconds' => 580, 'bonus_chars' => 0]);

        // A text reserving 20 s (300 credits) fits exactly; one more second does not.
        PlanUsageGuard::assertWithinLimits($plan, $counter, ['chars' => 300, 'credits' => 300, 'seconds' => 20]);
        $this->expectException(ValidationException::class);
        PlanUsageGuard::assertWithinLimits($plan, $counter, ['chars' => 315, 'credits' => 315, 'seconds' => 21]);
    }

    public function test_gifted_credits_also_give_minutes(): void
    {
        $plan = new Plan(['monthly_chars' => 9000, 'monthly_requests' => 0]);
        // 9,000 gifted credits double the month to 20 minutes: 15 minutes used still leaves room.
        $counter = new UsageCounter(['total_chars' => 13500, 'total_requests' => 3, 'total_seconds' => 900, 'bonus_chars' => 9000]);

        PlanUsageGuard::assertWithinLimits($plan, $counter, ['chars' => 225, 'credits' => 225, 'seconds' => 15]);
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
