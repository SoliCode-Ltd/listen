<?php

namespace App\Services;

use App\Models\BillingSubscription;
use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
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
    ): ManualPaymentRequest {
        return DB::transaction(function () use ($user, $plan, $billingCycle, $paymentMethod, $quote, $destinationSnapshot, $proofImage) {
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
            ]);
        });
    }

    public function approve(ManualPaymentRequest $manualPaymentRequest, User $reviewer, ?string $reviewerNotes = null): ManualPaymentRequest
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

            if ($this->hasActiveStripeSubscription($request->user)) {
                throw new DomainException(__('ui.billing.manual_active_stripe_conflict'));
            }

            $currentSubscription = Subscription::query()
                ->where('user_id', $request->user->id)
                ->latest('id')
                ->first();

            $priceSnapshot = is_array($request->price_snapshot) ? $request->price_snapshot : [];
            $priceSnapshot['approval_context'] = $this->snapshotApprovalContext($currentSubscription);

            $periodEnd = strtolower($request->billing_cycle) === 'yearly'
                ? now()->addYear()
                : now()->addMonth();

            $subscription = $this->planAccessService->assignPlan(
                $request->user,
                $request->plan,
                'active',
                $periodEnd,
            );

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

            if ($request->status === ManualPaymentRequest::STATUS_APPROVED && $request->user) {
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

        $subscription = $this->planAccessService->assignPlan(
            $request->user,
            $targetPlan,
            $targetStatus,
            $periodEnd,
        );

        $subscription->forceFill([
            'billing_payment_state' => 'ok',
        ])->save();

        return [true, $usedFallbackPlan];
    }
}
