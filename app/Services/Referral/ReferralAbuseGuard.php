<?php

namespace App\Services\Referral;

use App\Models\ReferralCampaign;
use App\Models\ReferralConversion;
use App\Models\User;
use App\Models\UserReferralProfile;

/**
 * Evaluates fraud indicators for a referred user prior to approving a conversion.
 *
 * Returns an array of string indicator codes. An empty array means the
 * conversion can be auto-approved. A non-empty array means the conversion
 * must be flagged for manual review (or outright rejected).
 */
class ReferralAbuseGuard
{
    /**
     * @return array{indicators: list<string>, verdict: 'approve'|'flag'|'reject', reason: ?string}
     */
    public function evaluate(User $referrer, User $referred, ReferralCampaign $campaign, ?UserReferralProfile $referredProfile = null, ?ReferralConversion $currentConversion = null): array
    {
        $indicators = [];
        $reason = null;
        $verdict = 'approve';

        if ((int) $referrer->id === (int) $referred->id) {
            return [
                'indicators' => ['self_referral'],
                'verdict' => 'reject',
                'reason' => 'self_referral',
            ];
        }

        // Another referred user conversion under this campaign besides the one
        // currently being evaluated. The DB unique constraint enforces the
        // hard guarantee; this pre-flight just flags accidental duplicates.
        $existingConversion = ReferralConversion::query()
            ->where('campaign_id', $campaign->id)
            ->where('referred_user_id', $referred->id)
            ->when($currentConversion, fn ($q) => $q->where('id', '!=', $currentConversion->id))
            ->exists();

        if ($existingConversion) {
            return [
                'indicators' => ['duplicate_referred_user'],
                'verdict' => 'reject',
                'reason' => 'duplicate_referred_user',
            ];
        }

        // IP reuse between referrer and referred.
        if (
            filled($referrer->registration_ip)
            && filled($referred->registration_ip)
            && $referrer->registration_ip === $referred->registration_ip
        ) {
            $indicators[] = 'shared_registration_ip';
        }

        if (
            filled($referrer->last_login_ip)
            && filled($referred->registration_ip)
            && $referrer->last_login_ip === $referred->registration_ip
        ) {
            $indicators[] = 'referrer_ip_matches_referred_registration';
        }

        // Device reuse.
        $referrerDevices = array_filter([
            $referrer->registration_device_id,
            $referrer->last_seen_device_id,
        ]);
        $referredDevices = array_filter([
            $referred->registration_device_id,
            $referred->last_seen_device_id,
        ]);

        if (! empty(array_intersect($referrerDevices, $referredDevices))) {
            $indicators[] = 'shared_device_id';
        }

        // Rapid signup pattern: referred user signed up very shortly after
        // referrer (< 10 minutes) — possible same-person dual-account abuse.
        if (
            $referrer->created_at
            && $referred->created_at
            && $referrer->created_at->diffInMinutes($referred->created_at) < 10
        ) {
            $indicators[] = 'rapid_tandem_signup';
        }

        // Rate limit: too many approved conversions by this referrer in the
        // last day or ever.
        $perDayLimit = (int) ($campaign->max_conversions_per_referrer_per_day ?? 0);
        if ($perDayLimit > 0) {
            $countToday = ReferralConversion::query()
                ->where('referrer_user_id', $referrer->id)
                ->whereIn('status', [ReferralConversion::STATUS_APPROVED, ReferralConversion::STATUS_PENDING])
                ->where('created_at', '>=', now()->subDay())
                ->count();

            if ($countToday >= $perDayLimit) {
                $indicators[] = 'referrer_daily_rate_exceeded';
            }
        }

        $totalLimit = (int) ($campaign->max_conversions_per_referrer_total ?? 0);
        if ($totalLimit > 0) {
            $countTotal = ReferralConversion::query()
                ->where('referrer_user_id', $referrer->id)
                ->whereIn('status', [ReferralConversion::STATUS_APPROVED, ReferralConversion::STATUS_PENDING])
                ->count();

            if ($countTotal >= $totalLimit) {
                $indicators[] = 'referrer_total_cap_exceeded';
            }
        }

        // Referred user is currently blocked — never reward.
        if ($referred->isBlocked()) {
            return [
                'indicators' => array_merge($indicators, ['referred_user_blocked']),
                'verdict' => 'reject',
                'reason' => 'referred_user_blocked',
            ];
        }

        // Referrer is blocked — never reward.
        if ($referrer->isBlocked()) {
            return [
                'indicators' => array_merge($indicators, ['referrer_blocked']),
                'verdict' => 'reject',
                'reason' => 'referrer_blocked',
            ];
        }

        if ($indicators !== []) {
            $verdict = 'flag';
            $reason = 'fraud_indicators_detected';
        }

        return [
            'indicators' => array_values($indicators),
            'verdict' => $verdict,
            'reason' => $reason,
        ];
    }
}
