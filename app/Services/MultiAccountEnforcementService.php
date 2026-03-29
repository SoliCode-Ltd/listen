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
     * Linked accounts are detected by shared IP and, when available,
     * shared device IDs and request signatures.
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
        $signals = $this->linkedSignals($user);

        if ($signals['ips'] === [] && $signals['device_ids'] === [] && $signals['request_signatures'] === []) {
            return;
        }

        $accountCount = $this->linkedAccountCountFromSignals($signals);

        if ($accountCount <= $maxAccounts) {
            return;
        }

        // This user pushed the count over the limit — block them.
        Log::warning('Multi-account auto-block triggered.', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ips' => $signals['ips'],
            'device_ids' => $signals['device_ids'],
            'request_signatures' => $signals['request_signatures'],
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

    public function linkedAccountCount(User $user): int
    {
        return $this->linkedAccountCountFromSignals($this->linkedSignals($user));
    }

    /**
     * @param  array{
     *   ips: list<string>,
     *   device_ids: list<string>,
     *   request_signatures: list<string>
     * }  $signals
     */
    private function linkedAccountCountFromSignals(array $signals): int
    {
        $ips = $signals['ips'] ?? [];
        $deviceIds = $signals['device_ids'] ?? [];
        $requestSignatures = $signals['request_signatures'] ?? [];

        if ($ips === [] && $deviceIds === [] && $requestSignatures === []) {
            return 0;
        }

        return (int) User::query()
            ->where('is_admin', false)
            ->where('multi_account_whitelisted', false)
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
