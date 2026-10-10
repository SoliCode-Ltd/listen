<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class MultiAccountEnforcementService
{
    public const DEFAULT_MAX_ACCOUNTS = 2;

    public const BLOCK_REASON_EN = 'Your account has been suspended because multiple accounts were created from the same network.';

    public const BLOCK_REASON_AR = 'تم تعليق حسابك بسبب إنشاء حسابات متعددة من نفس الشبكة.';
    public const EVASION_REASON_EN = 'Your account has been suspended because it was created after another account on this device was suspended.';

    public const EVASION_REASON_AR = 'تم تعليق حسابك لأنه أُنشئ بعد إيقاف حساب آخر على نفس الجهاز.';

    public const BLOCK_REASON_EN_SETTING_KEY = 'multi_account_block_reason_en';
    public const BLOCK_REASON_AR_SETTING_KEY = 'multi_account_block_reason_ar';

    public function __construct(
        private readonly BlockedUserService $blockedUserService,
    ) {
    }

    /**
     * Check if the given user exceeds the max linked-account limit and
     * auto-block them if so. Admins are never auto-blocked.
     *
     * Auto-blocking uses ONLY the device cookie as evidence. The device
     * cookie is a random 48-char token unique to each browser instance —
     * if multiple accounts share one, it is hard proof of same-browser
     * multi-accounting.
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

        if (! $this->hasDeviceColumns()) {
            return;
        }

        $deviceIds = array_values(array_unique(array_filter([
            $user->registration_device_id,
            $user->last_seen_device_id,
        ])));

        if ($deviceIds === []) {
            return;
        }

        if ($this->blockIfEvadingBlock($user)) {
            return;
        }

        $maxAccounts = $this->maxAccountsPerIp();
        $accountCount = $this->countAccountsSharingDevice($deviceIds);

        if ($accountCount <= $maxAccounts) {
            return;
        }

        Log::warning('Multi-account auto-block triggered.', [
            'user_id' => $user->id,
            'email' => $user->email,
            'device_ids' => $deviceIds,
            'account_count' => $accountCount,
            'max_allowed' => $maxAccounts,
        ]);

        $reasonEn = $this->blockReasonEn();
        $reasonAr = $this->blockReasonAr();

        $this->blockedUserService->block(
            $user,
            $reasonEn,
            null,
            ['en' => $reasonEn, 'ar' => $reasonAr],
        );

        Cache::forget('admin:suspicious_users_count');
    }

    /**
     * The account was made on a browser where another account had already
     * been blocked: a blocked member starting over under a new name. Accounts
     * that were there before the block (a shared family computer) are left
     * alone, and an admin can unblock this one (that whitelists it).
     */
    public function blockIfEvadingBlock(User $user): bool
    {
        if (! config('listen.abuse.block_evasion', true)
            || $user->is_admin
            || $user->isBlocked()
            || $user->multi_account_whitelisted
            || ! $this->hasDeviceColumns()) {
            return false;
        }

        $source = $this->blockedAccountBefore($user);
        if (! $source) {
            return false;
        }

        Log::warning('Account blocked: made on the browser of an already blocked account.', [
            'user_id' => $user->id,
            'blocked_account_id' => $source->id,
            'blocked_account_blocked_at' => (string) $source->blocked_at,
        ]);

        $this->blockedUserService->block(
            $user,
            self::EVASION_REASON_EN,
            null,
            ['en' => self::EVASION_REASON_EN, 'ar' => self::EVASION_REASON_AR],
        );
        Cache::forget('admin:suspicious_users_count');

        return true;
    }

    /**
     * A blocked account on the same browser (device cookie) whose block came
     * before this account was created.
     */
    public function blockedAccountBefore(User $user): ?User
    {
        $deviceIds = array_values(array_unique(array_filter([
            $user->registration_device_id,
            $user->last_seen_device_id,
        ])));

        if ($deviceIds === [] || ! $user->created_at) {
            return null;
        }

        return User::query()
            ->whereKeyNot($user->id)
            ->where('is_blocked', true)
            ->whereNotNull('blocked_at')
            ->where('blocked_at', '<=', $user->created_at)
            ->where(function ($q) use ($deviceIds) {
                $q->whereIn('registration_device_id', $deviceIds)
                    ->orWhereIn('last_seen_device_id', $deviceIds);
            })
            ->orderBy('blocked_at')
            ->first();
    }

    public function maxAccountsPerIp(): int
    {
        return max(1, (int) SiteSetting::get('max_accounts_per_ip', self::DEFAULT_MAX_ACCOUNTS));
    }

    public function blockReasonEn(): string
    {
        $value = trim((string) SiteSetting::get(self::BLOCK_REASON_EN_SETTING_KEY, self::BLOCK_REASON_EN));

        return $value !== '' ? $value : self::BLOCK_REASON_EN;
    }

    public function blockReasonAr(): string
    {
        $value = trim((string) SiteSetting::get(self::BLOCK_REASON_AR_SETTING_KEY, self::BLOCK_REASON_AR));

        return $value !== '' ? $value : self::BLOCK_REASON_AR;
    }

    /**
     * Collect identity signals for admin display / investigation.
     * Only IP and device cookie — request signature has been removed
     * because it was just a browser-config hash shared by millions.
     *
     * @return array{
     *   ips: list<string>,
     *   device_ids: list<string>
     * }
     */
    public function linkedSignals(User $user): array
    {
        $ips = array_values(array_unique(array_filter([
            $user->registration_ip,
            $user->last_login_ip,
        ])));

        $deviceIds = [];
        if ($this->hasDeviceColumns()) {
            $deviceIds = array_values(array_unique(array_filter([
                $user->registration_device_id,
                $user->last_seen_device_id,
            ])));
        }

        return [
            'ips' => $ips,
            'device_ids' => $deviceIds,
        ];
    }

    /**
     * Count linked accounts using IP + device cookie (for admin display).
     */
    public function linkedAccountCount(User $user): int
    {
        $signals = $this->linkedSignals($user);
        $ips = $signals['ips'] ?? [];
        $deviceIds = $signals['device_ids'] ?? [];

        if ($ips === [] && $deviceIds === []) {
            return 0;
        }

        return (int) User::query()
            ->where('is_admin', false)
            ->where(function ($q) use ($ips, $deviceIds) {
                if ($ips !== []) {
                    $q->whereIn('registration_ip', $ips)
                        ->orWhereIn('last_login_ip', $ips);
                }

                if ($deviceIds !== [] && $this->hasDeviceColumns()) {
                    $q->orWhereIn('registration_device_id', $deviceIds)
                        ->orWhereIn('last_seen_device_id', $deviceIds);
                }
            })
            ->count();
    }

    /**
     * Count accounts sharing the same device cookie.
     * This is the ONLY signal used for auto-blocking.
     *
     * @param  list<string>  $deviceIds
     */
    private function countAccountsSharingDevice(array $deviceIds): int
    {
        if ($deviceIds === []) {
            return 0;
        }

        return (int) User::query()
            ->where('is_admin', false)
            ->where('multi_account_whitelisted', false)
            ->where(function ($q) use ($deviceIds) {
                $q->whereIn('registration_device_id', $deviceIds)
                    ->orWhereIn('last_seen_device_id', $deviceIds);
            })
            ->count();
    }

    private function hasDeviceColumns(): bool
    {
        return Schema::hasColumn('users', 'registration_device_id')
            && Schema::hasColumn('users', 'last_seen_device_id');
    }
}
