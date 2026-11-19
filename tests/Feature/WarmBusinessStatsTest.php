<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\Analytics\BusinessStatsService;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The admin dashboard's numbers are rebuilt ahead of time on the live site
 * (listen:warm-business-stats), so no admin waits for them; never on staging,
 * whose numbers read Stripe with the live keys.
 */
class WarmBusinessStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Plan::factory()->free()->create();
        User::factory()->count(3)->create();
    }

    public function test_on_the_live_site_the_numbers_are_ready_before_an_admin_opens_the_dashboard(): void
    {
        config(['listen.live_actions' => true]);
        $key = BusinessStatsService::cacheKey(BusinessStatsService::DEFAULT_MONTHS);
        $this->assertFalse(Cache::has($key));

        $this->artisan('listen:warm-business-stats')->assertSuccessful();

        $this->assertTrue(Cache::has($key));
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $snapshot = app(BusinessStatsService::class)->snapshot();
        $this->assertSame(0, $queries, 'the dashboard reads what was worked out');
        $this->assertSame(Cache::get($key), $snapshot);
    }

    public function test_on_staging_nothing_is_built(): void
    {
        config(['listen.live_actions' => false]);
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->artisan('listen:warm-business-stats')->assertSuccessful()
            ->expectsOutputToContain('Not the live site');

        $this->assertFalse(Cache::has(BusinessStatsService::cacheKey(BusinessStatsService::DEFAULT_MONTHS)));
        $this->assertSame(0, $queries);
    }

    public function test_a_rebuild_takes_the_old_numbers_place_without_removing_them_first(): void
    {
        $key = BusinessStatsService::cacheKey(BusinessStatsService::DEFAULT_MONTHS);
        Cache::put($key, ['old' => true], 600);
        $forgotten = [];
        Event::listen(KeyForgotten::class, function (KeyForgotten $e) use (&$forgotten): void {
            $forgotten[] = $e->key;
        });

        $fresh = app(BusinessStatsService::class)->refresh();

        $this->assertNotContains($key, $forgotten, 'an admin opening the page meanwhile still finds numbers');
        $this->assertSame($fresh, Cache::get($key));
        $this->assertArrayNotHasKey('old', $fresh);
    }

    public function test_it_runs_every_ten_minutes_in_the_background_on_the_live_site_only(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'listen:warm-business-stats'));

        $this->assertNotNull($event);
        $this->assertSame('2-59/10 * * * *', $event->expression);
        $this->assertTrue($event->runInBackground);
        $this->assertTrue($event->withoutOverlapping);

        config(['listen.live_actions' => true]);
        $this->assertTrue($event->filtersPass($this->app));
        // Staging does not even start it.
        config(['listen.live_actions' => false]);
        $this->assertFalse($event->filtersPass($this->app));
    }
}
