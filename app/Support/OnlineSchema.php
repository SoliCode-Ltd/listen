<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * For migrations that change a table members are using, on MySQL (live).
 *
 * Before MySQL changes a table it waits for the queries already running on it,
 * and every query that arrives meanwhile waits behind the change. MySQL's own
 * limit on that wait is a year, so one slow query on the table could hold up
 * every page that reads it. Here the change waits a few seconds at most, then
 * gives way to the pages, pauses and tries again. After the last try the
 * migration stops with MySQL's error; the migrations that use this skip what is
 * already done, so running them again later finishes the job.
 *
 * On another database (the tests) the change simply runs.
 */
final class OnlineSchema
{
    /** The longest a change waits for the table before it gives way. */
    public const WAIT_SECONDS = 2;

    public const ATTEMPTS = 10;

    public const PAUSE_SECONDS = 3.0;

    /** MySQL: "Lock wait timeout exceeded" (the table was busy for the whole wait). */
    private const LOCK_WAIT_TIMEOUT = 1205;

    /** MySQL: the asked-for way of changing the table is not possible for this change. */
    private const NOT_SUPPORTED = [1845, 1846];

    /**
     * @template T
     *
     * @param  callable(): T  $change
     * @return T
     */
    public static function change(callable $change, int $waitSeconds = self::WAIT_SECONDS, int $attempts = self::ATTEMPTS, float $pauseSeconds = self::PAUSE_SECONDS): mixed
    {
        if (DB::getDriverName() !== 'mysql') {
            return $change();
        }

        $before = (int) DB::selectOne('SELECT @@SESSION.lock_wait_timeout AS seconds')->seconds;
        DB::statement('SET SESSION lock_wait_timeout = '.max(1, $waitSeconds));
        try {
            for ($try = 1; ; $try++) {
                try {
                    return $change();
                } catch (QueryException $e) {
                    if (! self::is($e, [self::LOCK_WAIT_TIMEOUT]) || $try >= $attempts) {
                        throw $e;
                    }
                    usleep((int) round($pauseSeconds * 1_000_000));
                }
            }
        } finally {
            DB::statement('SET SESSION lock_wait_timeout = '.max(1, $before));
        }
    }

    /** Whether MySQL refused the change because the asked-for way (INSTANT, INPLACE, LOCK=NONE) is not possible. */
    public static function notSupported(QueryException $e): bool
    {
        return self::is($e, self::NOT_SUPPORTED);
    }

    /** @param  list<int>  $codes */
    private static function is(QueryException $e, array $codes): bool
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), $codes, true);
    }
}
