<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MultiAccountEnforcementService
{
    public const DEFAULT_MAX_ACCOUNTS = 2;

    public const BLOCK_REASON_EN = 'Your account has been suspended because multiple accounts were created from the same network. Each person is allowed one account only.';

    public const BLOCK_REASON_AR = 'تم تعليق حسابك بسبب إنشاء حسابات متعددة من نفس الشبكة. يُسمح لكل شخص بحساب واحد فقط.';

    public function __construct(
        private readonly BlockedUserService $blockedUserService,
    ) {
    }

    /**
     * Check if the given user exceeds the max-accounts-per-IP limit and
     * auto-block them if so.  Admins are never auto-blocked.
     *
     * Should be called after every successful registration or login
     * (once IPs have been stamped).
     */
    public function enforceAfterAuth(User $user): void
    {
        if ($user->is_admin) {
            return;
        }

        if ($user->isBlocked()) {
            return;
        }

        if ($user->multi_account_whitelisted) {
            return;
        }

        $maxAccounts = $this->maxAccountsPerIp();

        $ips = array_values(array_unique(array_filter([
            $user->registration_ip,
            $user->last_login_ip,
        ])));

        if ($ips === []) {
            return;
        }

        // Count non-admin accounts sharing any of this user's IPs.
        $accountCount = User::query()
            ->where('is_admin', false)
            ->where(fn ($q) => $q->whereIn('registration_ip', $ips)->orWhereIn('last_login_ip', $ips))
            ->count();

        if ($accountCount <= $maxAccounts) {
            return;
        }

        // This user pushed the count over the limit — block them.
        Log::warning('Multi-account auto-block triggered.', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ips' => $ips,
            'account_count' => $accountCount,
            'max_allowed' => $maxAccounts,
        ]);

        $this->blockedUserService->block(
            $user,
            self::BLOCK_REASON_EN,
            null,
            ['en' => self::BLOCK_REASON_EN, 'ar' => self::BLOCK_REASON_AR],
        );

        Cache::forget('admin:suspicious_users_count');
    }

    public function maxAccountsPerIp(): int
    {
        return max(1, (int) SiteSetting::get('max_accounts_per_ip', self::DEFAULT_MAX_ACCOUNTS));
    }
}
