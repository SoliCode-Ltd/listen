<?php

namespace Tests\Feature;

use App\Support\OnlineSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * A migration's change to a table members are using waits a few seconds at most
 * when the table is busy, tries again, and gives up rather than hold members'
 * pages up behind it (App\Support\OnlineSchema).
 *
 * The waits only happen on MySQL (live): run these against it with
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=... php artisan test tests/Feature/OnlineSchemaTest.php
 * On the tests' own database only the first one runs. Each makes and drops its
 * own small table, so nothing else is touched.
 */
class OnlineSchemaTest extends TestCase
{
    private const TABLE = 'online_schema_probe';

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `'.self::TABLE.'`');
        }
        parent::tearDown();
    }

    public function test_on_another_database_the_change_simply_runs(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL waits are checked by the tests below.');
        }

        $this->assertSame('done', OnlineSchema::change(fn (): string => 'done'));
    }

    public function test_a_change_to_a_busy_table_gives_up_after_its_tries_instead_of_waiting_for_it(): void
    {
        $this->probeTable();
        $before = $this->sessionWait();
        $holder = $this->otherConnection();
        // A query that has not finished: here, a transaction left open after reading the table.
        $holder->beginTransaction();
        $holder->query('SELECT * FROM `'.self::TABLE.'`')->fetchAll();

        $started = microtime(true);
        try {
            OnlineSchema::change(fn () => DB::statement($this->addIndex()), waitSeconds: 1, attempts: 2, pauseSeconds: 0.2);
            $this->fail('The change went through while the table was busy.');
        } catch (QueryException $e) {
            $this->assertSame(1205, (int) $e->errorInfo[1], 'MySQL said the table stayed busy');
        } finally {
            $holder->rollBack();
        }
        $took = microtime(true) - $started;

        // Two tries of a second each and the pause between: not MySQL's own limit of a year.
        $this->assertGreaterThanOrEqual(2.0, $took);
        $this->assertLessThan(6.0, $took);
        $this->assertSame($before, $this->sessionWait(), 'the connection keeps its own limit afterwards');
        $this->assertFalse($this->hasIndex());
    }

    public function test_the_change_goes_through_once_the_table_is_free(): void
    {
        $this->probeTable();
        // A slow query on the table, still running when the change first asks for it.
        $slow = mysqli_connect(...$this->mysqliArgs());
        $slow->query('SELECT SLEEP(1.6), COUNT(*) FROM `'.self::TABLE.'`', MYSQLI_ASYNC);
        usleep(200_000);

        $tries = 0;
        OnlineSchema::change(function () use (&$tries) {
            $tries++;

            return DB::statement($this->addIndex());
        }, waitSeconds: 1, attempts: 5, pauseSeconds: 0.2);

        $slow->reap_async_query();
        $slow->close();
        $this->assertSame(2, $tries, 'the first try gave way, the next went through');
        $this->assertTrue($this->hasIndex());
    }

    public function test_any_other_error_is_not_tried_again(): void
    {
        $this->probeTable();
        $tries = 0;

        try {
            OnlineSchema::change(function () use (&$tries) {
                $tries++;

                return DB::statement('ALTER TABLE `'.self::TABLE.'` ADD INDEX `p` (`no_such_column`)');
            }, waitSeconds: 1, attempts: 5, pauseSeconds: 0.2);
            $this->fail('An index on a missing column was added.');
        } catch (QueryException) {
        }

        $this->assertSame(1, $tries);
    }

    private function probeTable(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Only MySQL makes a change wait for a busy table.');
        }
        DB::statement('DROP TABLE IF EXISTS `'.self::TABLE.'`');
        DB::statement('CREATE TABLE `'.self::TABLE.'` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `seen_at` TIMESTAMP NULL)');
        DB::statement('INSERT INTO `'.self::TABLE.'` (`seen_at`) VALUES (NOW()), (NOW())');
    }

    private function addIndex(): string
    {
        return 'ALTER TABLE `'.self::TABLE.'` ADD INDEX `'.self::TABLE.'_seen_at_index` (`seen_at`), ALGORITHM=INPLACE, LOCK=NONE';
    }

    private function hasIndex(): bool
    {
        return DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [self::TABLE, self::TABLE.'_seen_at_index'])->n > 0;
    }

    private function sessionWait(): int
    {
        return (int) DB::selectOne('SELECT @@SESSION.lock_wait_timeout AS seconds')->seconds;
    }

    private function otherConnection(): PDO
    {
        [$host, $user, $password, $database, $port] = $this->mysqliArgs();

        return new PDO("mysql:host={$host};port={$port};dbname={$database}", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: int} */
    private function mysqliArgs(): array
    {
        $c = DB::connection()->getConfig();

        return [(string) $c['host'], (string) $c['username'], (string) $c['password'], (string) $c['database'], (int) ($c['port'] ?? 3306)];
    }
}
