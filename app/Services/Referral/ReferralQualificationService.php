<?php

namespace App\Services\Referral;

use App\Models\Plan;
use App\Models\ReferralCampaign;
use App\Models\ReferralConversion;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserReferralProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Evaluates whether a referred user has met the campaign's qualification step
 * and, if so, promotes their conversion to approved/flagged and grants the
 * referrer a demo reward.
 *
 * Safe to call multiple times (idempotent via conversion status).
 */
class ReferralQualificationService
{
    public function __construct(
        private readonly DemoRewardLedgerService $ledger,
        private readonly ReferralAbuseGuard $abuseGuard,
        private readonly ReferralService $referralService,
    ) {}

    /**
     * Entry point: call whenever a qualifying event happens for $referredUser.
     * Event categories: 'signup', 'email_verified', 'first_demo_generation', 'paid_plan'.
     */
    public function evaluate(User $referredUser, string $eventType): ?ReferralConversion
    {
        $campaign = ReferralCampaign::active();
        if (! $campaign) {
            return null;
        }

        $conversion = ReferralConversion::query()
            ->where('campaign_id', $campaign->id)
            ->where('referred_user_id', $referredUser->id)
            ->first();

        if (! $conversion) {
            return null;
        }

        if ($conversion->status !== ReferralConversion::STATUS_PENDING) {
            return $conversion; // already decided
        }

        if (! $this->eventMatchesQualification($campaign, $eventType, $referredUser)) {
            return $conversion;
        }

        return $this->promoteConversion($conversion, $campaign, $referredUser);
    }

    private function eventMatchesQualification(ReferralCampaign $campaign, string $eventType, User $referredUser): bool
    {
        return match ($campaign->qualification_step) {
            ReferralCampaign::QUALIFICATION_SIGNUP => $eventType === 'signup',
            ReferralCampaign::QUALIFICATION_EMAIL_VERIFIED => $eventType === 'email_verified'
                || (bool) $referredUser->email_verified_at,
            ReferralCampaign::QUALIFICATION_FIRST_DEMO => $eventType === 'first_demo_generation',
            ReferralCampaign::QUALIFICATION_PAID_PLAN => $eventType === 'paid_plan'
                && $this->userHasActivePaidPlan($referredUser),
            default => false,
        };
    }

    private function userHasActivePaidPlan(User $user): bool
    {
        return Subscription::query()
            ->where('subscriptions.user_id', $user->id)
            ->where('subscriptions.status', 'active')
            ->where(function ($q) {
                $q->whereNull('subscriptions.current_period_end')
                    ->orWhere('subscriptions.current_period_end', '>=', now());
            })
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('plans.price_cents', '>', 0)
            ->exists();
    }

    private function promoteConversion(ReferralConversion $conversion, ReferralCampaign $campaign, User $referredUser): ReferralConversion
    {
        return DB::transaction(function () use ($conversion, $campaign, $referredUser): ReferralConversion {
            /** @var ReferralConversion $conv */
            $conv = ReferralConversion::query()
                ->whereKey($conversion->id)
                ->lockForUpdate()
                ->first();

            if (! $conv || $conv->status !== ReferralConversion::STATUS_PENDING) {
                return $conv ?: $conversion;
            }

            $referrer = User::query()->find($conv->referrer_user_id);
            if (! $referrer) {
                $conv->forceFill([
                    'status' => ReferralConversion::STATUS_REJECTED,
                    'rejected_at' => now(),
                    'rejection_reason' => 'referrer_missing',
                    'qualified_at' => now(),
                ])->save();

                return $conv;
            }

            $referredProfile = UserReferralProfile::query()
                ->where('user_id', $referredUser->id)
                ->first();

            $evaluation = $this->abuseGuard->evaluate($referrer, $referredUser, $campaign, $referredProfile, $conv);

            $now = now();
            $conv->forceFill([
                'qualified_at' => $now,
                'fraud_indicators' => $evaluation['indicators'],
            ])->save();

            if ($evaluation['verdict'] === 'reject') {
                $conv->forceFill([
                    'status' => ReferralConversion::STATUS_REJECTED,
                    'rejected_at' => $now,
                    'rejection_reason' => $evaluation['reason'],
                ])->save();

                $referrerProfile = UserReferralProfile::query()
                    ->where('user_id', $referrer->id)
                    ->first();
                if ($referrerProfile) {
                    $this->referralService->recomputeProfileCounters($referrerProfile);
                }

                return $conv;
            }

            if ($evaluation['verdict'] === 'flag') {
                $conv->forceFill([
                    'status' => ReferralConversion::STATUS_FLAGGED,
                    'flagged_at' => $now,
                    'rejection_reason' => null,
                ])->save();

                // Flagged = manual admin review. Don't auto-grant.
                $referrerProfile = UserReferralProfile::query()
                    ->where('user_id', $referrer->id)
                    ->first();
                if ($referrerProfile) {
                    $this->referralService->recomputeProfileCounters($referrerProfile);
                }

                return $conv;
            }

            // verdict === 'approve' — auto-approve and grant reward.
            $this->approveAndGrant($conv, $campaign, $referrer);

            return $conv->refresh();
        });
    }

    /**
     * Admin manual approve path (e.g. from Filament). Use when promoting a
     * flagged conversion.
     */
    public function manuallyApprove(ReferralConversion $conversion, User $reviewer, ?string $notes = null): ReferralConversion
    {
        return DB::transaction(function () use ($conversion, $reviewer, $notes): ReferralConversion {
            /** @var ReferralConversion $conv */
            $conv = ReferralConversion::query()
                ->whereKey($conversion->id)
                ->lockForUpdate()
                ->first();

            if (! $conv) {
                return $conversion;
            }

            if ($conv->status === ReferralConversion::STATUS_APPROVED) {
                return $conv;
            }

            $campaign = ReferralCampaign::query()->find($conv->campaign_id);
            $referrer = User::query()->find($conv->referrer_user_id);
            if (! $campaign || ! $referrer) {
                return $conv;
            }

            $conv->forceFill([
                'reviewed_by' => $reviewer->id,
                'reviewer_notes' => $notes,
            ])->save();

            $this->approveAndGrant($conv, $campaign, $referrer);

            return $conv->refresh();
        });
    }

    public function manuallyReject(ReferralConversion $conversion, User $reviewer, ?string $notes = null, ?string $reason = null): ReferralConversion
    {
        return DB::transaction(function () use ($conversion, $reviewer, $notes, $reason): ReferralConversion {
            /** @var ReferralConversion $conv */
            $conv = ReferralConversion::query()
                ->whereKey($conversion->id)
                ->lockForUpdate()
                ->first();

            if (! $conv) {
                return $conversion;
            }

            if ($conv->status === ReferralConversion::STATUS_APPROVED) {
                // Already granted: revoke the reward chars from the referrer.
                $alreadyGranted = (int) $conv->reward_chars;
                $referrer = User::query()->find($conv->referrer_user_id);
                if ($referrer && $alreadyGranted > 0) {
                    $this->ledger->revoke($referrer, $alreadyGranted, $conv, 'admin_revoke');
                }
            }

            $conv->forceFill([
                'status' => ReferralConversion::STATUS_REJECTED,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
                'reviewed_by' => $reviewer->id,
                'reviewer_notes' => $notes,
            ])->save();

            $referrerProfile = UserReferralProfile::query()
                ->where('user_id', $conv->referrer_user_id)
                ->first();
            if ($referrerProfile) {
                $this->referralService->recomputeProfileCounters($referrerProfile);
            }

            return $conv;
        });
    }

    private function approveAndGrant(ReferralConversion $conv, ReferralCampaign $campaign, User $referrer): void
    {
        $rewardChars = (int) ($conv->reward_chars > 0 ? $conv->reward_chars : $campaign->reward_chars);
        $conv->forceFill([
            'status' => ReferralConversion::STATUS_APPROVED,
            'approved_at' => now(),
            'reward_chars' => $rewardChars,
        ])->save();

        try {
            $this->ledger->grant($referrer, $rewardChars, $conv, 'referral_conversion_approved');
        } catch (\Throwable $e) {
            Log::error('Failed to grant demo reward characters.', [
                'conversion_id' => $conv->id,
                'referrer_user_id' => $referrer->id,
                'exception' => $e->getMessage(),
            ]);
        }

        $referrerProfile = UserReferralProfile::query()
            ->where('user_id', $referrer->id)
            ->first();
        if ($referrerProfile) {
            $this->referralService->recomputeProfileCounters($referrerProfile);
        }
    }

    /**
     * Convenience for PlanAccessService hook: detect whether the plan being
     * assigned is a paid plan and that we're transitioning the user from
     * non-paid to paid for the first time.
     */
    public function isFirstPaidPlanTransition(User $user, Plan $newPlan): bool
    {
        if ((int) $newPlan->price_cents <= 0) {
            return false;
        }

        $hasPaidHistory = Subscription::query()
            ->where('subscriptions.user_id', $user->id)
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('plans.price_cents', '>', 0)
            ->exists();

        return ! $hasPaidHistory;
    }
}
