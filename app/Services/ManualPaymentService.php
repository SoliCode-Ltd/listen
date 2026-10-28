<?php

namespace App\Services;

use App\Models\BillingSubscription;
use App\Models\CharPackPurchase;
use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\AdsPurchases;
use App\Services\Billing\CharPackService;
use App\Support\CreditPeriod;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ManualPaymentService
{
    public function __construct(
        private readonly PlanAccessService $planAccessService,
    ) {}

    /**
     * @param  array<string, mixed>  $quote
     * @param  array<string, mixed>  $destinationSnapshot
     */
    public function createRequest(
        User $user,
        Plan $plan,
        string $billingCycle,
        string $paymentMethod,
        array $quote,
        array $destinationSnapshot,
        ?UploadedFile $proofImage = null,
        ?string $gclid = null,
    ): ManualPaymentRequest {
        return DB::transaction(function () use ($user, $plan, $billingCycle, $paymentMethod, $quote, $destinationSnapshot, $proofImage, $gclid) {
            if ($this->hasPendingRequest($user, $plan, $billingCycle, $paymentMethod)) {
                throw new DomainException(__('ui.billing.manual_pending_exists'));
            }

            if ($this->hasActiveStripeSubscription($user)) {
                throw new DomainException(__('ui.billing.manual_active_stripe_conflict'));
            }

            $proofPath = $proofImage?->store('manual-payment-proofs', 'local');

            return ManualPaymentRequest::query()->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'billing_cycle' => $billingCycle,
                'payment_method' => $paymentMethod,
                'expected_amount_minor' => (int) ($quote['amount_minor'] ?? 0),
                'currency' => strtoupper((string) ($quote['currency'] ?? 'USD')),
                'country_code' => strtoupper((string) (($quote['country_code'] ?? '') ?: ($destinationSnapshot['country_code'] ?? ''))),
                'status' => ManualPaymentRequest::STATUS_PENDING,
                'price_snapshot' => $quote,
                'destination_snapshot' => $destinationSnapshot,
                'proof_path' => $proofPath,
                'submitted_at' => now(),
                // The Google Ads click the member came from: the sale is uploaded once approved.
                'gclid' => $gclid,
            ]);
        });
    }

    public function approve(ManualPaymentRequest $manualPaymentRequest, User $reviewer, ?string $reviewerNotes = null): ManualPaymentRequest
    {
        $approved = $this->approveWhileLocked($manualPaymentRequest, $reviewer, $reviewerNotes);
        // Only now is it a sale: kept for the Google Ads upload when it came from an ad.
        app(AdsPurchases::class)->recordManualPayment($approved);

        return $approved;
    }

    private function approveWhileLocked(ManualPaymentRequest $manualPaymentRequest, User $reviewer, ?string $reviewerNotes): ManualPaymentRequest
    {
        return DB::transaction(function () use ($manualPaymentRequest, $reviewer, $reviewerNotes) {
            /** @var ManualPaymentRequest $request */
            $request = ManualPaymentRequest::query()
                ->whereKey($manualPaymentRequest->id)
                ->lockForUpdate()
                ->with(['user', 'plan'])
                ->firstOrFail();

            if ($request->status !== ManualPaymentRequest::STATUS_PENDING) {
                throw new DomainException(__('ui.billing.admin_request_already_reviewed'));
            }

            if (! $request->user || ! $request->plan) {
                throw new DomainException(__('ui.billing.admin_request_missing_relation'));
            }

            if ($request->plan->isPack()) {
                return $this->approvePack($request, $reviewer, $reviewerNotes);
            }

            if ($this->hasActiveStripeSubscription($request->user)) {
                throw new DomainException(__('ui.billing.manual_active_stripe_conflict'));
            }

            $currentSubscription = Subscription::query()
                ->where('user_id', $request->user->id)
                ->latest('id')
                ->first();

            $priceSnapshot = is_array($request->price_snapshot) ? $request->price_snapshot : [];
            $priceSnapshot['approval_context'] = $this->snapshotApprovalContext($currentSubscription);

            // Paid before the plan paid by hand has ended (the reminders ask members to
            // renew before then): the new period follows the one already paid for, so
            // no paid day is lost. Otherwise it starts now.
            $paidUntil = $this->paidByHandUntil($currentSubscription);
            $from = $paidUntil ?? now();
            $periodEnd = strtolower($request->billing_cycle) === 'yearly'
                ? $from->copy()->addYear()
                : $from->copy()->addMonth();

            // Paid in full for a period of its own: its credits start afresh, even
            // when it replaces a plan given by the team before that one ends (a plan
            // change by card carries the credits on instead, App\Support\CreditPeriod).
            // A period that follows one paid by hand keeps that one's start, so its
            // credits run to its end and the new period's credits start fresh then.
            $subscription = CreditPeriod::startingAfresh(fn (): Subscription => $this->planAccessService->assignPlan(
                $request->user,
                $request->plan,
                'active',
                $periodEnd,
                $paidUntil !== null ? ($currentSubscription->current_period_start ?? $currentSubscription->created_at) : null,
            ));

            $subscription->forceFill([
                'billing_payment_state' => 'manual_override',
            ])->save();

            $request->forceFill([
                'status' => ManualPaymentRequest::STATUS_APPROVED,
                'price_snapshot' => $priceSnapshot,
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer->id,
                'reviewer_notes' => $reviewerNotes,
                'approved_subscription_id' => $subscription->id,
            ])->save();

            return $request->fresh(['user', 'plan', 'reviewer']);
        });
    }

    /**
     * Delete a manual payment request safely.
     *
     * If the request was approved and still controls the user's active manual-overridden
     * subscription, rollback to the previous plan snapshot (or free plan fallback).
     *
     * @return array{rolled_back:bool,used_fallback_plan:bool}
     */
    public function deleteRequest(ManualPaymentRequest $manualPaymentRequest): array
    {
        return DB::transaction(function () use ($manualPaymentRequest): array {
            /** @var ManualPaymentRequest $request */
            $request = ManualPaymentRequest::query()
                ->whereKey($manualPaymentRequest->id)
                ->lockForUpdate()
                ->with(['user', 'plan'])
                ->firstOrFail();

            $rolledBack = false;
            $usedFallbackPlan = false;

            if ($request->status === ManualPaymentRequest::STATUS_APPROVED && $request->plan?->isPack()) {
                // Deleting an approved pack payment takes back its unused characters.
                $purchase = CharPackPurchase::query()->where('manual_payment_request_id', $request->id)->first();
                if ($purchase) {
                    $this->charPacks()->refund($purchase);
                    $rolledBack = true;
                }
            } elseif ($request->status === ManualPaymentRequest::STATUS_APPROVED && $request->user) {
                [$rolledBack, $usedFallbackPlan] = $this->rollbackApprovedRequestPlan($request);
            }

            if (is_string($request->proof_path) && trim($request->proof_path) !== '') {
                Storage::disk('local')->delete($request->proof_path);
            }

            $request->delete();

            return [
                'rolled_back' => $rolledBack,
                'used_fallback_plan' => $usedFallbackPlan,
            ];
        });
    }

    public function reject(ManualPaymentRequest $manualPaymentRequest, User $reviewer, ?string $reviewerNotes = null): ManualPaymentRequest
    {
        return DB::transaction(function () use ($manualPaymentRequest, $reviewer, $reviewerNotes) {
            /** @var ManualPaymentRequest $request */
            $request = ManualPaymentRequest::query()
                ->whereKey($manualPaymentRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status !== ManualPaymentRequest::STATUS_PENDING) {
                throw new DomainException(__('ui.billing.admin_request_already_reviewed'));
            }

            $request->forceFill([
                'status' => ManualPaymentRequest::STATUS_REJECTED,
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer->id,
                'reviewer_notes' => $reviewerNotes,
            ])->save();

            return $request->fresh(['user', 'plan', 'reviewer']);
        });
    }

    /**
     * A pack paid by InstaPay / Vodafone Cash: add its characters. The member's
     * plan is not touched; a pack never becomes the subscription.
     */
    private function approvePack(ManualPaymentRequest $request, User $reviewer, ?string $reviewerNotes): ManualPaymentRequest
    {
        $packs = $this->charPacks();
        $purchase = $packs->startPurchase(
            $request->user,
            $request->plan,
            (string) $request->payment_method,
            (int) $request->expected_amount_minor,
            (string) $request->currency,
            (int) $request->id,
        );
        $packs->markPaid($purchase);

        $request->forceFill([
            'status' => ManualPaymentRequest::STATUS_APPROVED,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer->id,
            'reviewer_notes' => $reviewerNotes,
        ])->save();

        return $request->fresh(['user', 'plan', 'reviewer']);
    }

    private function charPacks(): CharPackService
    {
        return app(CharPackService::class);
    }

    public function hasPendingRequest(User $user, Plan $plan, string $billingCycle, string $paymentMethod): bool
    {
        return ManualPaymentRequest::query()
            ->where('user_id', $user->id)
            ->where('plan_id', $plan->id)
            ->where('billing_cycle', $billingCycle)
            ->where('payment_method', $paymentMethod)
            ->where('status', ManualPaymentRequest::STATUS_PENDING)
            ->exists();
    }

    public function latestPendingRequest(User $user, Plan $plan, string $billingCycle, string $paymentMethod): ?ManualPaymentRequest
    {
        return ManualPaymentRequest::query()
            ->where('user_id', $user->id)
            ->where('plan_id', $plan->id)
            ->where('billing_cycle', $billingCycle)
            ->where('payment_method', $paymentMethod)
            ->where('status', ManualPaymentRequest::STATUS_PENDING)
            ->latest('id')
            ->first();
    }

    public function proofResponse(ManualPaymentRequest $manualPaymentRequest)
    {
        $path = trim((string) $manualPaymentRequest->proof_path);
        abort_if($path === '', 404);

        // Try the configured local disk first (storage/app/private/)
        if (Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->response($path, basename($path), [
                'Content-Disposition' => 'inline; filename="'.basename($path).'"',
            ]);
        }

        // Fallback: file may have been stored before the disk root changed to app/private
        $legacyAbsolute = storage_path('app/'.$path);
        if (is_file($legacyAbsolute)) {
            return response()->file($legacyAbsolute, [
                'Content-Disposition' => 'inline; filename="'.basename($path).'"',
            ]);
        }

        abort(404);
    }

    /**
     * When the member's plan paid by hand ends, while it is still running: the
     * plan an approved InstaPay / Vodafone Cash payment set. Null when it has
     * ended, or was not paid by hand (given by the team, paid by card, free).
     */
    private function paidByHandUntil(?Subscription $current): ?Carbon
    {
        $end = $current?->current_period_end;
        if ($end === null || $end->lte(now()) || (string) $current->status !== 'active'
            || (string) $current->billing_payment_state !== 'manual_override') {
            return null;
        }

        $paidByHand = ManualPaymentRequest::query()
            ->where('user_id', $current->user_id)
            ->where('status', ManualPaymentRequest::STATUS_APPROVED)
            ->where('approved_subscription_id', $current->id)
            ->where('plan_id', $current->plan_id)
            ->exists();

        return $paidByHand ? $end->copy() : null;
    }

    private function hasActiveStripeSubscription(User $user): bool
    {
        /** @var BillingSubscription|null $billingSubscription */
        $billingSubscription = BillingSubscription::query()
            ->where('user_id', $user->id)
            ->where('type', 'default')
            ->latest('id')
            ->first();

        return (bool) ($billingSubscription?->valid() ?? false);
    }

    /**
     * @return array{
     *   previous_subscription_id:?int,
     *   previous_plan_id:?int,
     *   previous_status:?string,
     *   previous_period_start:?string,
     *   previous_period_end:?string,
     *   captured_at:string
     * }
     */
    private function snapshotApprovalContext(?Subscription $subscription): array
    {
        return [
            'previous_subscription_id' => $subscription?->id ? (int) $subscription->id : null,
            'previous_plan_id' => $subscription?->plan_id ? (int) $subscription->plan_id : null,
            'previous_status' => $subscription?->status ? (string) $subscription->status : null,
            'previous_period_start' => $subscription?->current_period_start?->toDateTimeString(),
            'previous_period_end' => $subscription?->current_period_end?->toDateTimeString(),
            'captured_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * @return array{0:bool,1:bool}
     */
    private function rollbackApprovedRequestPlan(ManualPaymentRequest $request): array
    {
        $latestSubscription = Subscription::query()
            ->where('user_id', $request->user_id)
            ->latest('id')
            ->first();

        if (! $latestSubscription) {
            return [false, false];
        }

        // Only rollback when this request still controls the active manual override.
        if (
            (int) ($request->approved_subscription_id ?? 0) !== (int) $latestSubscription->id
            || (string) ($latestSubscription->billing_payment_state ?? '') !== 'manual_override'
        ) {
            return [false, false];
        }

        $approvalContext = is_array($request->price_snapshot)
            ? (array) ($request->price_snapshot['approval_context'] ?? [])
            : [];

        $targetPlan = null;
        $usedFallbackPlan = false;
        $previousPlanId = (int) ($approvalContext['previous_plan_id'] ?? 0);
        if ($previousPlanId > 0) {
            $targetPlan = Plan::query()->find($previousPlanId);
        }

        if (! $targetPlan) {
            $targetPlan = $this->planAccessService->freePlan();
            $usedFallbackPlan = true;
        }

        if (! $targetPlan) {
            return [false, false];
        }

        $targetStatusRaw = trim((string) ($approvalContext['previous_status'] ?? ''));
        $targetStatus = in_array($targetStatusRaw, ['active', 'expired', 'canceled'], true)
            ? $targetStatusRaw
            : 'active';

        $periodEnd = null;
        $periodEndRaw = trim((string) ($approvalContext['previous_period_end'] ?? ''));
        if ($periodEndRaw !== '') {
            try {
                $periodEnd = Carbon::parse($periodEndRaw);
            } catch (\Throwable) {
                $periodEnd = null;
            }
        }

        // The previous plan comes back with its own dates, so its credits carry on
        // where they were (App\Support\CreditPeriod) instead of starting again.
        $periodStart = null;
        $periodStartRaw = trim((string) ($approvalContext['previous_period_start'] ?? ''));
        if ($periodStartRaw !== '' && $periodEnd !== null) {
            try {
                $periodStart = Carbon::parse($periodStartRaw);
            } catch (\Throwable) {
                $periodStart = null;
            }
        }

        $subscription = $this->planAccessService->assignPlan(
            $request->user,
            $targetPlan,
            $targetStatus,
            $periodEnd,
            $periodStart,
        );

        $subscription->forceFill([
            'billing_payment_state' => 'ok',
        ])->save();

        return [true, $usedFallbackPlan];
    }
}
