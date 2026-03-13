<?php

namespace App\Services;

use App\Models\BillingSubscription;
use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
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
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer->id,
                'reviewer_notes' => $reviewerNotes,
                'approved_subscription_id' => $subscription->id,
            ])->save();

            return $request->fresh(['user', 'plan', 'reviewer']);
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
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, basename($path), [
            'Content-Disposition' => 'inline; filename="'.basename($path).'"',
        ]);
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
}
