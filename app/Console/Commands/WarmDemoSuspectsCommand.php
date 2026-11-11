<?php

namespace App\Console\Commands;

use App\Services\DemoSuspects;
use Illuminate\Console\Command;

/**
 * Works out Admin → Suspicious Users' list (and its badge on every admin page)
 * ahead of time, every 5 minutes (routes/console.php), so no admin waits for it.
 */
class WarmDemoSuspectsCommand extends Command
{
    protected $signature = 'listen:warm-demo-suspects';

    protected $description = 'Work out the accounts Admin → Suspicious Users lists, ahead of time.';

    public function handle(DemoSuspects $suspects): int
    {
        $started = microtime(true);
        $count = $suspects->refresh();
        $this->line(sprintf('%d linked accounts, in %.1f s.', $count, microtime(true) - $started));

        return self::SUCCESS;
    }
}
