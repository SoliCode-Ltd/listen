<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The scheduled billing jobs never start again while a run is still going,
 * and a lock left by a run that died lapses on its own.
 */
class BillingScheduleTest extends TestCase
{
    public function test_billing_jobs_never_run_on_top_of_themselves(): void
    {
        $events = collect(app(Schedule::class)->events());

        foreach ([
            'listen:billing-sync' => 60,
            'listen:billing-reminders' => 120,
            'listen:billing-enforce-grace' => 50,
            'plans:expire-discounts' => 10,
        ] as $command => $minutes) {
            $event = $events->first(fn (Event $event): bool => str_ends_with((string) $event->command, ' '.$command));

            $this->assertNotNull($event, "{$command} is scheduled");
            $this->assertTrue($event->withoutOverlapping, "{$command} waits for its last run");
            $this->assertSame($minutes, $event->expiresAt, "{$command}'s lock lapses after {$minutes} minutes");
        }
    }
}
