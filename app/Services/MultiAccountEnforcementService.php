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
     *
     * Signals NOT used for auto-blocking (and why):
     *  - IP address: shared by unrelated users via CGNAT / shared WiFi.
     *  - Request signature: only a browser-config hash (Chrome version +
     *    platform + language), shared by millions of users with the same
     *    browser setup. NOT a fingerprint.
     *
     * Both IP and request signature remain visible on the admin
     * suspicious-users page for manual investigation.
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
     * Collect all identity signals for admin display / investigation.
     * This is NOT used for enforcement decisions.
     *
     * @return array{
     *   ips: list<string>,
     *   device_ids: list<string>,
     *   request_signatures: list<string>
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

        $requestSignatures = [];
        if ($this->hasRequestSignatureColumns()) {
            $requestSignatures = array_values(array_unique(array_filter([
                $user->registration_request_signature,
                $user->last_seen_request_signature,
            ])));
        }

        return [
            'ips' => $ips,
            'device_ids' => $deviceIds,
            'request_signatures' => $requestSignatures,
        ];
    }

    /**
     * Count linked accounts using ALL signals (for admin display).
     */
    public function linkedAccountCount(User $user): int
    {
        $signals = $this->linkedSignals($user);
        $ips = $signals['ips'] ?? [];
        $deviceIds = $signals['device_ids'] ?? [];
        $requestSignatures = $signals['request_signatures'] ?? [];

        if ($ips === [] && $deviceIds === [] && $requestSignatures === []) {
            return 0;
        }

        return (int) User::query()
            ->where('is_admin', false)
            ->where(function ($q) use ($ips, $deviceIds, $requestSignatures) {
                if ($ips !== []) {
                    $q->whereIn('registration_ip', $ips)
                        ->orWhereIn('last_login_ip', $ips);
                }

                if ($deviceIds !== [] && $this->hasDeviceColumns()) {
                    $q->orWhereIn('registration_device_id', $deviceIds)
                        ->orWhereIn('last_seen_device_id', $deviceIds);
                }

                if ($requestSignatures !== [] && $this->hasRequestSignatureColumns()) {
                    $q->orWhereIn('registration_request_signature', $requestSignatures)
                        ->orWhereIn('last_seen_request_signature', $requestSignatures);
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

    private function hasRequestSignatureColumns(): bool
    {
        return Schema::hasColumn('users', 'registration_request_signature')
            && Schema::hasColumn('users', 'last_seen_request_signature');
    }
}
