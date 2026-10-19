<?php

namespace Tests\Feature\Api;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The developer API reserves credits locking the member's generations first
 * and their usage row second, in the same order as the studio, the job and
 * the refunds: a studio request and an API request for one member at the
 * same moment then wait in turn instead of deadlocking.
 */
class ApiReserveLockOrderTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
    }

    public function test_the_generations_are_locked_before_the_usage_row(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $base = DB::transactionLevel();
        $inTransaction = [];
        DB::listen(function (QueryExecuted $query) use (&$inTransaction, $base): void {
            if ($query->connection->transactionLevel() > $base) {
                $inTransaction[] = $query->sql;
            }
        });

        $this->speak($plain, $this->premiumVoice())->assertOk();

        $first = function (string $pattern) use ($inTransaction): int {
            foreach ($inTransaction as $index => $sql) {
                if (preg_match($pattern, $sql) === 1) {
                    return $index;
                }
            }
            $this->fail("No query in a transaction matches {$pattern}");
        };
        $generations = $first('/select max\("id"\) as aggregate from "audio_generations" where "user_id"/');
        $usageRow = $first('/from "usage_counters" where "user_id"/');
        $this->assertLessThan($usageRow, $generations, 'the generations are locked before the usage row');
    }
}
