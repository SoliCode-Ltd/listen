<?php

namespace App\Console\Commands;

use App\Models\DemoRewardTransaction;
use App\Models\User;
use App\Models\UserReferralProfile;
use App\Services\Referral\DemoRewardLedgerService;
use App\Services\Referral\ReferralService;
use Illuminate\Console\Command;

/**
 * Reconcile orphaned referral reward grants.
 *
 * A "grant" transaction becomes orphaned when its source conversion was
 * deleted before the deleting-hook that revokes rewards was in place. The
 * FK `nullOnDelete` zeroes the `conversion_id` but leaves the positive
 * delta_chars in the ledger, so the referrer keeps reward characters they
 * are no longer entitled to.
 *
 * This command finds every grant row with conversion_id IS NULL and reason
 * matching the grant origin (`referral_conversion_approved`), then revokes
 * the net-outstanding amount per user and recomputes profile counters.
 */
class ReconcileReferralRewardsCommand extends Command
{
    protected $signature = 'referral:reconcile-rewards
        {--user= : Restrict to a single user (id or email)}
        {--dry : Report only; do not write revoke transactions}';

    protected $description = 'Revoke reward grants whose source conversion has been deleted (backfill for pre-hook deletions).';

    public function handle(DemoRewardLedgerService $ledger, ReferralService $referral): int
    {
        $dry = (bool) $this->option('dry');
        $userFilter = $this->option('user');

        $userQ = User::query();
        if ($userFilter) {
            $userQ->where(function ($q) use ($userFilter) {
                $q->where('id', $userFilter)->orWhere('email', $userFilter);
            });
        }

        $users = $userQ->get();
        if ($users->isEmpty()) {
            $this->warn('No users match the filter.');

            return self::SUCCESS;
        }

        $totalRevoked = 0;
        $usersAffected = 0;

        foreach ($users as $user) {
            $orphanSum = (int) DemoRewardTransaction::query()
                ->where('user_id', $user->id)
                ->where('kind', DemoRewardTransaction::KIND_GRANT)
                ->whereNull('conversion_id')
                ->where('reason', 'referral_conversion_approved')
                ->sum('delta_chars');

            if ($orphanSum <= 0) {
                continue;
            }

            $this->line(sprintf(
                '  %s (#%d) — orphaned grants: %d chars',
                $user->email,
                $user->id,
                $orphanSum
            ));

            if (! $dry) {
                $ledger->revoke($user, $orphanSum, null, 'conversion_deleted_backfill');
                $profile = UserReferralProfile::query()->where('user_id', $user->id)->first();
                if ($profile) {
                    $referral->recomputeProfileCounters($profile);
                }
            }

            $totalRevoked += $orphanSum;
            $usersAffected++;
        }

        $verb = $dry ? 'Would revoke' : 'Revoked';
        $this->info(sprintf('%s %d chars across %d user(s).', $verb, $totalRevoked, $usersAffected));

        return self::SUCCESS;
    }
}
