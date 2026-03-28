<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BlockedUserService;
use App\Services\MultiAccountEnforcementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class AuditBlockedUsersCommand extends Command
{
    protected $signature = 'listen:audit-blocked
        {--fix : Unblock users that were falsely blocked by the signature collision bug}
        {--user= : Audit a single user by ID}
        {--export= : Export unblocked emails to a file (e.g. --export=unblocked.txt)}';

    protected $description = 'Identify blocked users who may have been falsely blocked due to the request-signature collision bug.';

    public function handle(MultiAccountEnforcementService $enforcement, BlockedUserService $blockedService): int
    {
        $maxAccounts = $enforcement->maxAccountsPerIp();
        $fix = (bool) $this->option('fix');
        $singleUserId = $this->option('user');
        $exportPath = $this->option('export');

        $this->info("Max linked accounts allowed: {$maxAccounts}");
        $this->info($fix ? 'MODE: FIX (will unblock falsely blocked users)' : 'MODE: DRY-RUN (preview only)');
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

        $this->info("Found {$total} multi-account blocked user(s). Auditing...");
        $this->newLine();

        $hasSignatureColumns = Schema::hasColumn('users', 'registration_request_signature')
            && Schema::hasColumn('users', 'last_seen_request_signature');

        $falsePositives = 0;
        $legitimateBlocks = 0;
        $uncertain = 0;
        $unblockedEmails = [];

        $rows = [];

        $query->chunkById(100, function ($users) use (
            $enforcement, $blockedService, $maxAccounts, $hasSignatureColumns,
            $fix, &$falsePositives, &$legitimateBlocks, &$uncertain, &$rows, &$unblockedEmails
        ) {
            foreach ($users as $user) {
                $signals = $enforcement->linkedSignals($user);
                $fullCount = $enforcement->linkedAccountCount($user);

                // Recount WITHOUT request_signature (the buggy signal)
                $countWithoutSig = $this->countWithoutSignature($user, $signals, $hasSignatureColumns);

                // Determine if signature was the deciding factor
                $wasBlockedBySigAlone = $fullCount > $maxAccounts && $countWithoutSig <= $maxAccounts;

                // Also check: is the user's signature shared with many users?
                // This indicates the collision bug specifically
                $sigUserCount = $this->signatureSharedCount($user, $hasSignatureColumns);

                $verdict = 'LEGITIMATE';
                if ($wasBlockedBySigAlone && $sigUserCount > 10) {
                    $verdict = 'FALSE POSITIVE (signature collision)';
                    $falsePositives++;
                } elseif ($wasBlockedBySigAlone) {
                    $verdict = 'LIKELY FALSE POSITIVE';
                    $falsePositives++;
                } elseif ($countWithoutSig > $maxAccounts) {
                    $verdict = 'LEGITIMATE';
                    $legitimateBlocks++;
                } else {
                    $verdict = 'UNCERTAIN';
                    $uncertain++;
                }

                $rows[] = [
                    $user->id,
                    $user->email,
                    $user->name,
                    $fullCount,
                    $countWithoutSig,
                    $sigUserCount,
                    implode(', ', $signals['ips']) ?: '-',
                    $verdict,
                ];

                if ($fix && str_contains($verdict, 'FALSE POSITIVE')) {
                    $blockedService->unblock($user);
                    $this->line("  -> UNBLOCKED user #{$user->id} ({$user->email})");

                    $unblockedEmails[] = $user->email;

                    // Clear the bad signatures so they don't cause issues again
                    if ($hasSignatureColumns) {
                        $user->forceFill([
                            'registration_request_signature' => null,
                            'last_seen_request_signature' => null,
                        ])->saveQuietly();
                    }
                }
            }
        });

        $this->table(
            ['ID', 'Email', 'Name', 'Full Count', 'Count (no sig)', 'Sig Shared By', 'IPs', 'Verdict'],
            $rows
        );

        $this->newLine();
        $this->info("Results:");
        $this->line("  False positives:   {$falsePositives}");
        $this->line("  Legitimate blocks: {$legitimateBlocks}");
        $this->line("  Uncertain:         {$uncertain}");

        if ($falsePositives > 0 && ! $fix) {
            $this->newLine();
            $this->warn("Run with --fix to unblock the {$falsePositives} false positive(s).");
        }

        if ($fix && $unblockedEmails !== []) {
            Cache::forget('admin:suspicious_users_count');

            $this->newLine();
            $this->info("Unblocked {$falsePositives} user(s). Their request signatures were cleared.");

            $this->newLine();
            $this->info('=== UNBLOCKED EMAILS (copy for outreach) ===');
            foreach ($unblockedEmails as $email) {
                $this->line($email);
            }

            if ($exportPath) {
                file_put_contents($exportPath, implode("\n", $unblockedEmails) . "\n");
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
     * Count linked accounts using only IP + Device ID, excluding request_signature.
     */
    private function countWithoutSignature(User $user, array $signals, bool $hasSignatureColumns): int
    {
        $ips = $signals['ips'] ?? [];
        $deviceIds = $signals['device_ids'] ?? [];

        if ($ips === [] && $deviceIds === []) {
            return 0;
        }

        $hasDeviceColumns = Schema::hasColumn('users', 'registration_device_id')
            && Schema::hasColumn('users', 'last_seen_device_id');

        return (int) User::query()
            ->where('is_admin', false)
            ->where(function ($q) use ($ips, $deviceIds, $hasDeviceColumns) {
                if ($ips !== []) {
                    $q->whereIn('registration_ip', $ips)
                        ->orWhereIn('last_login_ip', $ips);
                }

                if ($deviceIds !== [] && $hasDeviceColumns) {
                    $q->orWhereIn('registration_device_id', $deviceIds)
                        ->orWhereIn('last_seen_device_id', $deviceIds);
                }
            })
            ->count();
    }

    /**
     * How many distinct users share this user's request signature?
     * A high number (>10) strongly indicates the collision bug.
     */
    private function signatureSharedCount(User $user, bool $hasSignatureColumns): int
    {
        if (! $hasSignatureColumns) {
            return 0;
        }

        $signatures = array_values(array_unique(array_filter([
            $user->registration_request_signature,
            $user->last_seen_request_signature,
        ])));

        if ($signatures === []) {
            return 0;
        }

        return (int) User::query()
            ->where(function ($q) use ($signatures) {
                $q->whereIn('registration_request_signature', $signatures)
                    ->orWhereIn('last_seen_request_signature', $signatures);
            })
            ->count();
    }
}
