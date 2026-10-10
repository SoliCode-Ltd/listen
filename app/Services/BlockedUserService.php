<?php

namespace App\Services;

use App\Models\User;
use App\Services\Billing\TrialSubscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BlockedUserService
{
    private const SUPPORT_EMAIL = 'help@listenai.studio';
    public const DEFAULT_REASON_AR = 'تكرار إدخال عبارات مسيئة أو محظورة';
    public const DEFAULT_REASON_EN = 'Repeated submission of abusive or prohibited language';

    public function isBlocked(?User $user): bool
    {
        return $user instanceof User && (bool) $user->is_blocked;
    }

    public function messageFor(User $user): string
    {
        $reason = $this->localizedReason($user);

        if ($reason !== null) {
            return __('ui.backend.account_blocked_with_reason', [
                'reason' => $reason,
                'email' => self::SUPPORT_EMAIL,
            ]);
        }

        return __('ui.backend.account_blocked', [
            'email' => self::SUPPORT_EMAIL,
        ]);
    }

    /**
     * @param  array{ar?:string|null,en?:string|null}|null  $localizedReasons
     */
    public function block(User $user, ?string $reason = null, ?User $blockedBy = null, ?array $localizedReasons = null): void
    {
        $reasonAr = trim((string) ($localizedReasons['ar'] ?? ''));
        $reasonEn = trim((string) ($localizedReasons['en'] ?? ''));
        $fallbackReason = trim((string) ($reason ?? ''));
        $fallbackIsArabic = $fallbackReason !== '' && preg_match('/[\x{0600}-\x{06FF}]/u', $fallbackReason) === 1;

        if ($fallbackReason !== '') {
            if ($fallbackIsArabic && $reasonAr === '') {
                $reasonAr = $fallbackReason;
            }

            if (! $fallbackIsArabic && $reasonEn === '') {
                $reasonEn = $fallbackReason;
            }
        }

        if ($reasonAr === '') {
            $reasonAr = self::DEFAULT_REASON_AR;
        }

        if ($reasonEn === '') {
            $reasonEn = self::DEFAULT_REASON_EN;
        }

        if ($fallbackReason === '') {
            $fallbackReason = $reasonEn !== '' ? $reasonEn : $reasonAr;
        }

        $user->forceFill([
            'is_blocked' => true,
            'blocked_at' => now(),
            'blocked_reason' => $fallbackReason !== '' ? $fallbackReason : null,
            'blocked_reason_ar' => $reasonAr !== '' ? $reasonAr : null,
            'blocked_reason_en' => $reasonEn !== '' ? $reasonEn : null,
            'blocked_by' => $blockedBy?->id,
            'multi_account_whitelisted' => false,
            'remember_token' => Str::random(60),
        ])->save();

        $this->invalidateSessions($user);
        $this->cancelOpenTrial($user);
    }

    /**
     * A blocked member can no longer use the account, so their free trial must
     * not turn into a charge. Only where switched on (live), never on staging.
     * A failure here never stops the block itself.
     */
    private function cancelOpenTrial(User $user): void
    {
        if (! TrialSubscriptionService::stripeTrialActionsEnabled()) {
            return;
        }

        try {
            app(TrialSubscriptionService::class)->cancelOpenTrialsForBlockedUser($user);
        } catch (\Throwable $exception) {
            Log::error('Cancelling a blocked member\'s free trial failed.', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function unblock(User $user): void
    {
        $user->forceFill([
            'is_blocked' => false,
            'blocked_at' => null,
            'blocked_reason' => null,
            'blocked_reason_ar' => null,
            'blocked_reason_en' => null,
            'blocked_by' => null,
            'multi_account_whitelisted' => true,
            'remember_token' => Str::random(60),
        ])->save();

        $this->invalidateSessions($user);
    }

    /**
     * @return array{title:string,body:string,reason:?string,email:string}
     */
    public function noticePayload(User $user): array
    {
        return [
            'title' => __('ui.auth.blocked_notice_title'),
            'body' => __('ui.auth.blocked_notice_body'),
            'reason' => $this->localizedReason($user),
            'email' => self::SUPPORT_EMAIL,
        ];
    }

    private function localizedReason(User $user): ?string
    {
        $locale = strtolower((string) app()->getLocale());
        $isArabic = str_starts_with($locale, 'ar');

        $candidates = $isArabic
            ? [
                $user->blocked_reason_ar ?? null,
                $user->blocked_reason ?? null,
                $user->blocked_reason_en ?? null,
            ]
            : [
                $user->blocked_reason_en ?? null,
                $user->blocked_reason ?? null,
                $user->blocked_reason_ar ?? null,
            ];

        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $isArabic ? self::DEFAULT_REASON_AR : self::DEFAULT_REASON_EN;
    }

    private function invalidateSessions(User $user): void
    {
        if (! Schema::hasTable(config('session.table', 'sessions'))) {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->delete();
    }
}
