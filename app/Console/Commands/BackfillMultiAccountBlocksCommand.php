<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MultiAccountEnforcementService;
use App\Support\PaidMember;
use Illuminate\Console\Command;

class BackfillMultiAccountBlocksCommand extends Command
{
    protected $signature = 'listen:multi-account-backfill
        {--dry-run : Preview users that would be blocked without changing data}
        {--chunk=200 : Number of users per processing chunk}';

    protected $description = 'Backfill multi-account auto-blocking for existing users: accounts over the limit on the browser they were made on.';

    public function handle(MultiAccountEnforcementService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(50, (int) $this->option('chunk'));
        $maxAccounts = $service->maxAccountsPerIp();

        // Newest first, as at sign-up: the accounts made after a browser reached
        // the limit are the ones blocked, never the first ones made there.
        $query = User::query()
            ->where('is_admin', false)
            ->where('is_blocked', false)
            ->where('multi_account_whitelisted', false)
            ->whereNotNull('registration_device_id');

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
        // Per browser, the accounts a dry run would have blocked: a blocked
        // account no longer counts against the others.
        $dryRunBlocked = [];

        $query->chunkByIdDesc($chunk, function ($users) use ($service, $maxAccounts, $dryRun, &$scanned, &$wouldBlock, &$blocked, &$dryRunBlocked): void {
            foreach ($users as $user) {
                $scanned++;

                $device = (string) $user->registration_device_id;
                $count = $service->accountsMadeOnSameBrowser($user) - ($dryRunBlocked[$device] ?? 0);
                if ($count <= $maxAccounts || PaidMember::is($user)) {
                    continue;
                }

                $wouldBlock++;

                if ($dryRun) {
                    $dryRunBlocked[$device] = ($dryRunBlocked[$device] ?? 0) + 1;

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
