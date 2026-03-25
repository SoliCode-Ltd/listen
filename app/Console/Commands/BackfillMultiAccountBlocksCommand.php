<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MultiAccountEnforcementService;
use Illuminate\Console\Command;

class BackfillMultiAccountBlocksCommand extends Command
{
    protected $signature = 'listen:multi-account-backfill
        {--dry-run : Preview users that would be blocked without changing data}
        {--chunk=200 : Number of users per processing chunk}';

    protected $description = 'Backfill multi-account auto-blocking for existing users based on current linked-account signals.';

    public function handle(MultiAccountEnforcementService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(50, (int) $this->option('chunk'));
        $maxAccounts = $service->maxAccountsPerIp();

        $query = User::query()
            ->where('is_admin', false)
            ->where('is_blocked', false)
            ->where('multi_account_whitelisted', false)
            ->orderBy('id');

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No eligible users found for backfill.');

            return self::SUCCESS;
        }

        $this->info(
            sprintf(
                'Scanning %d users with max linked accounts limit = %d%s',
                $total,
                $maxAccounts,
                $dryRun ? ' (dry-run)' : ''
            )
        );

        $scanned = 0;
        $wouldBlock = 0;
        $blocked = 0;

        $query->chunkById($chunk, function ($users) use ($service, $maxAccounts, $dryRun, &$scanned, &$wouldBlock, &$blocked): void {
            foreach ($users as $user) {
                $scanned++;

                $linkedCount = $service->linkedAccountCount($user);
                if ($linkedCount <= $maxAccounts) {
                    continue;
                }

                $wouldBlock++;

                if ($dryRun) {
                    continue;
                }

                $service->enforceAfterAuth($user);
                $user->refresh();

                if ($user->isBlocked()) {
                    $blocked++;
                }
            }
        });

        $this->line("Scanned: {$scanned}");
        $this->line("Would block: {$wouldBlock}");

        if (! $dryRun) {
            $this->line("Blocked: {$blocked}");
        }

        $this->info($dryRun ? 'Dry-run complete.' : 'Backfill complete.');

        return self::SUCCESS;
    }
}
