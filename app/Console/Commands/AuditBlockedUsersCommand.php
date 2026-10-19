<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BlockedUserService;
use App\Services\MultiAccountEnforcementService;
use App\Support\PaidMember;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class AuditBlockedUsersCommand extends Command
{
    protected $signature = 'listen:audit-blocked
        {--fix : Unblock false positives and whitelist them}
        {--user= : Audit a single user by ID}
        {--export= : Export unblocked emails to a file (e.g. --export=unblocked.txt)}';

    protected $description = 'Re-evaluate multi-account blocked users against the current enforcement rules (accounts made on one browser; members who pay or have paid are never blocked). Users blocked by IP, signature, sign-ins or a paid account are false positives.';

    public function handle(MultiAccountEnforcementService $enforcement, BlockedUserService $blockedService): int
    {
        $maxAccounts = $enforcement->maxAccountsPerIp();
        $fix = (bool) $this->option('fix');
        $singleUserId = $this->option('user');
        $exportPath = $this->option('export');

        $this->info("Max linked accounts allowed: {$maxAccounts}");
        $this->info($fix ? 'MODE: FIX (will unblock false positives)' : 'MODE: DRY-RUN (preview only)');
        $this->newLine();

        $query = User::query()
            ->where('is_blocked', true)
            ->where(function ($q) {
                $q->where('blocked_reason_en', 'like', '%multiple accounts%')
                    ->orWhere('blocked_reason', 'like', '%multiple accounts%')
                    ->orWhere('blocked_reason_ar', 'like', '%حسابات متعددة%');
            })
            ->orderBy('id');

        if ($singleUserId) {
            $query->where('id', (int) $singleUserId);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No multi-account blocked users found.');

            return self::SUCCESS;
        }

        $this->info("Found {$total} multi-account blocked user(s). Auditing against device-only rules...");
        $this->newLine();

        $falsePositives = 0;
        $legitimateBlocks = 0;
        $unblockedEmails = [];
        $rows = [];

        $query->chunkById(100, function ($users) use (
            $blockedService, $maxAccounts,
            $fix, &$falsePositives, &$legitimateBlocks, &$rows, &$unblockedEmails
        ) {
            foreach ($users as $user) {
                // The ONLY signal that matters for enforcement is the browser the
                // accounts were made on (device cookie).
                $deviceCount = $this->accountsOnBrowserWhenMade($user);

                // For context, also show IP-linked count (not used for blocking).
                $ipCount = $this->countAccountsSharingIp($user);

                // Members who pay or have paid are never blocked by the rule.
                if ($deviceCount > $maxAccounts && ! PaidMember::is($user)) {
                    $verdict = 'LEGITIMATE';
                    $legitimateBlocks++;
                } else {
                    $verdict = 'FALSE POSITIVE';
                    $falsePositives++;
                }

                $rows[] = [
                    $user->id,
                    $user->email,
                    $user->name,
                    $deviceCount,
                    $ipCount,
                    $user->registration_ip ?: '-',
                    $verdict,
                ];

                if ($fix && $verdict === 'FALSE POSITIVE') {
                    $blockedService->unblock($user);
                    $unblockedEmails[] = $user->email;
                    $this->line("  -> UNBLOCKED #{$user->id} ({$user->email})");
                }
            }
        });

        $this->table(
            ['ID', 'Email', 'Name', 'Device-Linked', 'IP-Linked (info)', 'Registration IP', 'Verdict'],
            $rows
        );

        $this->newLine();
        $this->info('Results:');
        $this->line("  False positives:   {$falsePositives}");
        $this->line("  Legitimate blocks: {$legitimateBlocks}");

        if ($falsePositives > 0 && ! $fix) {
            $this->newLine();
            $this->warn("Run with --fix to unblock the {$falsePositives} false positive(s).");
        }

        if ($fix && $unblockedEmails !== []) {
            Cache::forget('admin:suspicious_users_count');

            $this->newLine();
            $this->info("Unblocked {$falsePositives} user(s). They have been whitelisted.");

            $this->newLine();
            $this->info('=== UNBLOCKED EMAILS ===');
            foreach ($unblockedEmails as $email) {
                $this->line($email);
            }

            if ($exportPath) {
                file_put_contents($exportPath, implode("\n", $unblockedEmails)."\n");
                $this->newLine();
                $this->info("Emails exported to: {$exportPath}");
            }
        } elseif ($fix) {
            $this->newLine();
            $this->info('No users were unblocked.');
        }

        return self::SUCCESS;
    }

    /**
     * What the rule counted when this account was made: accounts made on the
     * same browser (device cookie at sign-up) up to this one, leaving out
     * admins and accounts already blocked by then. Signing in on a browser
     * links nothing. Unblocking with --fix does not change the count of the
     * accounts made after, so the account over the limit stays blocked.
     */
    private function accountsOnBrowserWhenMade(User $user): int
    {
        $deviceId = trim((string) $user->registration_device_id);
        if ($deviceId === '' || ! $user->created_at) {
            return 1;
        }

        return 1 + (int) User::query()
            ->where('registration_device_id', $deviceId)
            ->where('id', '<', $user->id)
            ->where('is_admin', false)
            ->where(fn ($query) => $query->whereNull('blocked_at')->orWhere('blocked_at', '>', $user->created_at))
            ->count();
    }

    /**
     * Count accounts sharing this user's IP (for display context only).
     */
    private function countAccountsSharingIp(User $user): int
    {
        $ips = array_values(array_unique(array_filter([
            $user->registration_ip,
            $user->last_login_ip,
        ])));

        if ($ips === []) {
            return 1;
        }

        return (int) User::query()
            ->where('is_admin', false)
            ->where(function ($q) use ($ips) {
                $q->whereIn('registration_ip', $ips)
                    ->orWhereIn('last_login_ip', $ips);
            })
            ->count();
    }
}
