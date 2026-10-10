<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MultiAccountEnforcementService;
use Illuminate\Console\Command;

/**
 * One-off catch-up for accounts made on a blocked member's browser before
 * the sign-in check existed. Lists them by default; --apply blocks them.
 */
class BlockEvadingAccountsCommand extends Command
{
    protected $signature = 'listen:block-evading-accounts
        {--apply : Block the accounts found (without it, only list them)}';

    protected $description = 'Find (and with --apply, block) accounts created on the browser of an already blocked account.';

    public function handle(MultiAccountEnforcementService $service): int
    {
        $apply = (bool) $this->option('apply');
        $rows = [];

        User::query()
            ->where('is_admin', false)
            ->where('is_blocked', false)
            ->where('multi_account_whitelisted', false)
            ->where(fn ($q) => $q->whereNotNull('registration_device_id')->orWhereNotNull('last_seen_device_id'))
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($service, $apply, &$rows): void {
                foreach ($users as $user) {
                    $source = $service->blockedAccountBefore($user);
                    if (! $source) {
                        continue;
                    }

                    $blocked = $apply && $service->blockIfEvadingBlock($user);
                    $rows[] = [
                        $user->id,
                        $this->mask($user->email),
                        (string) $user->created_at,
                        "#{$source->id} (blocked ".$source->blocked_at.')',
                        $apply ? ($blocked ? 'blocked' : 'skipped') : 'would block',
                    ];
                }
            });

        if ($rows === []) {
            $this->info('No accounts made after a block on the same browser.');

            return self::SUCCESS;
        }

        $this->table(['user', 'email', 'created', 'blocked account on that browser', 'result'], $rows);
        $this->info($apply
            ? count($rows).' account(s) handled.'
            : count($rows).' account(s) found. Run again with --apply to block them.');

        return self::SUCCESS;
    }

    private function mask(?string $email): string
    {
        $email = (string) $email;
        if (! str_contains($email, '@')) {
            return '***';
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 2).'***@'.$domain;
    }
}
