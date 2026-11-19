<?php

namespace Tests\Feature;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The scheduled jobs start spread over the minutes (routes/console.php): each one starts the
 * whole app, so a dozen starting in one minute is a spike on a busy server. The sweeps for
 * stuck voices find them by an index instead of reading every voice ever made.
 */
class ScheduleSpreadTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<Event> the jobs that start at set minutes (not the every-minute ones or the workers) */
    private function timedJobs(): array
    {
        return array_values(array_filter(app(Schedule::class)->events(), function (Event $event): bool {
            $minute = explode(' ', $event->expression)[0];

            return $minute !== '*' && ! str_starts_with($minute, '*/') && ! str_contains((string) $event->command, 'queue:work')
                && ! str_contains($event->expression, '1-59/2');
        }));
    }

    /** @return list<Event> */
    private function dueAt(Carbon $at): array
    {
        return array_values(array_filter($this->timedJobs(), fn (Event $event): bool => (new CronExpression($event->expression))->isDue($at, 'UTC')));
    }

    public function test_no_more_than_three_jobs_start_in_any_minute_of_the_hour(): void
    {
        $hour = Carbon::parse('2026-11-19 14:00:00', 'UTC');

        for ($minute = 0; $minute < 60; $minute++) {
            $due = $this->dueAt($hour->copy()->addMinutes($minute));
            $this->assertLessThanOrEqual(3, count($due), sprintf(':%02d starts %s', $minute, implode(', ', array_map(fn (Event $e) => $e->command ?? $e->description, $due))));
        }
    }

    public function test_none_starts_on_a_multiple_of_five_when_other_sites_on_the_server_run_theirs(): void
    {
        // An afternoon hour, away from the jobs that run once a day.
        $hour = Carbon::parse('2026-11-19 14:00:00', 'UTC');

        for ($minute = 0; $minute < 60; $minute += 5) {
            $this->assertSame([], $this->dueAt($hour->copy()->addMinutes($minute)), sprintf(':%02d', $minute));
        }
    }

    public function test_every_job_still_runs_as_often_as_before(): void
    {
        $runsPerHour = [];
        $hour = Carbon::parse('2026-11-19 14:00:00', 'UTC');
        foreach ($this->timedJobs() as $event) {
            $name = (string) ($event->command ?? $event->description);
            for ($minute = 0; $minute < 60; $minute++) {
                if ((new CronExpression($event->expression))->isDue($hour->copy()->addMinutes($minute), 'UTC')) {
                    $runsPerHour[$name] = ($runsPerHour[$name] ?? 0) + 1;
                }
            }
        }
        $count = fn (string $command): int => (int) collect($runsPerHour)->first(fn ($runs, $name) => str_contains($name, $command));

        foreach (['plans:expire-discounts', 'listen:purchase-follow-ups', 'listen:chatgpt-ads-send', 'api:expire-stuck-generations', 'listen:expire-stuck-generations'] as $command) {
            $this->assertSame(12, $count($command), $command);
        }
        foreach (['dubs:prune', 'transcriptions:prune', 'listen:warm-demo-suspects', 'listen:warm-business-stats'] as $command) {
            $this->assertSame(6, $count($command), $command);
        }
        $this->assertSame(4, $count('listen:billing-sync'));
        $this->assertSame(2, $count('voice-design:prune'));
        foreach (['listen:billing-enforce-grace', 'agents:read-links', 'agents:read-websites'] as $command) {
            $this->assertSame(1, $count($command), $command);
        }
    }

    public function test_the_stuck_voice_sweeps_have_their_index(): void
    {
        $this->assertTrue(collect(Schema::getIndexes('audio_generations'))
            ->contains(fn (array $index): bool => $index['name'] === 'audio_generations_status_updated_at_index' && $index['columns'] === ['status', 'updated_at']));

        // The database looks the stuck ones up by it.
        $plan = collect(DB::select("EXPLAIN QUERY PLAN SELECT id FROM audio_generations WHERE status = 'processing' AND updated_at < ? ORDER BY id LIMIT 500", [now()->toDateTimeString()]))
            ->pluck('detail')->implode(' ');
        $this->assertStringContainsString('audio_generations_status_updated_at_index', $plan);
    }
}
