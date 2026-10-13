<?php

namespace App\Services\Billing;

use App\Models\CharPackPurchase;
use App\Models\CharPackWallet;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PlanAccessService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * One-time character packs. A member on the free plan buys a pack, gets the
 * pack plan's features (the premium voice) while pack characters are left,
 * and spends them in the dashboard and the browser extension. Buying again
 * adds the characters and restarts the clock. Moving to a paid plan carries
 * what is left into that month's allowance.
 */
class CharPackService
{
    public function wallet(int $userId): ?CharPackWallet
    {
        return CharPackWallet::query()->where('user_id', $userId)->first();
    }

    public function remaining(int $userId): int
    {
        return (int) ($this->wallet($userId)?->remaining() ?? 0);
    }

    /**
     * @return array{active:bool, total:int, used:int, remaining:int, expires_at:?Carbon}
     */
    public function summary(int $userId): array
    {
        $wallet = $this->wallet($userId);
        $active = (bool) $wallet?->isActive();

        return [
            'active' => $active,
            'total' => $active ? (int) $wallet->chars_total : 0,
            'used' => $active ? (int) $wallet->chars_used : 0,
            'remaining' => $active ? $wallet->remaining() : 0,
            'expires_at' => $wallet?->expires_at,
        ];
    }

    /**
     * The pack plan whose features a member has while pack characters are left.
     */
    public function activePackPlan(int $userId): ?Plan
    {
        if ($this->remaining($userId) <= 0) {
            return null;
        }

        $planId = CharPackPurchase::query()
            ->where('user_id', $userId)
            ->where('status', CharPackPurchase::STATUS_PAID)
            ->whereNotNull('plan_id')
            ->latest('paid_at')
            ->value('plan_id');

        return ($planId ? Plan::query()->packs()->find($planId) : null)
            ?? Plan::query()->packs()->orderBy('id')->first();
    }

    public static function billsToPack(?Plan $quotaPlan): bool
    {
        return (bool) ($quotaPlan?->getAttribute('billed_to_pack') ?? false);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Spending
    // ──────────────────────────────────────────────────────────────────

    /**
     * Takes the characters for a generation, or refuses. Two requests at once
     * cannot both spend the last characters: the check and the spend are one update.
     */
    public function reserve(int $userId, int $chars): void
    {
        $chars = max(0, $chars);

        $spent = CharPackWallet::query()
            ->where('user_id', $userId)
            ->where('expires_at', '>', now())
            ->whereRaw('chars_total - chars_used >= ?', [$chars])
            ->update([
                'chars_used' => DB::raw('chars_used + '.$chars),
                'updated_at' => now(),
            ]);

        if ($spent === 1) {
            return;
        }

        $remaining = $this->remaining($userId);

        throw ValidationException::withMessages([
            'content' => $remaining > 0
                ? __('ui.backend.pack_not_enough', ['remaining' => number_format($remaining), 'needed' => number_format($chars)])
                : __('ui.backend.pack_ended'),
        ]);
    }

    /** Gives back characters for a generation that failed. */
    public function release(int $userId, int $chars): void
    {
        $this->adjust($userId, -max(0, $chars));
    }

    /**
     * Corrects the spend once the real character count is known. Never goes
     * below zero, and never above what the member bought.
     */
    public function adjust(int $userId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        DB::transaction(function () use ($userId, $delta): void {
            $wallet = CharPackWallet::query()->where('user_id', $userId)->lockForUpdate()->first();
            if (! $wallet) {
                return;
            }

            $wallet->chars_used = min((int) $wallet->chars_total, max(0, (int) $wallet->chars_used + $delta));
            $wallet->save();
        });
    }

    // ──────────────────────────────────────────────────────────────────
    //  Buying
    // ──────────────────────────────────────────────────────────────────

    /** The pack shown on the pricing page and sold to members. */
    public function offeredPack(): ?Plan
    {
        return Plan::query()
            ->packs()
            ->where('is_active', true)
            ->where('price_cents', '>', 0)
            ->orderBy('price_cents')
            ->first();
    }

    /**
     * Packs are for members without a paid plan. A member on a plan or in a
     * trial already has the premium voice.
     */
    public function canBuy(User $user, ?Plan $pack = null): bool
    {
        $pack ??= $this->offeredPack();
        if (! $pack || ! $pack->isPack() || ! $pack->is_active || (int) $pack->price_cents <= 0) {
            return false;
        }

        $plans = app(PlanAccessService::class);
        $current = $plans->resolveActivePlanForUserId((int) $user->id);

        return (int) ($current?->price_cents ?? 0) <= 0
            && ! $plans->userHasUnpaidTrialWindow((int) $user->id);
    }

    public function startPurchase(
        User $user,
        Plan $pack,
        string $method,
        int $amountMinor,
        string $currency,
        ?int $manualPaymentRequestId = null,
    ): CharPackPurchase {
        return CharPackPurchase::query()->create([
            'user_id' => $user->id,
            'plan_id' => $pack->id,
            'chars' => max(1, (int) $pack->monthly_chars),
            'days' => $pack->packDays(),
            'amount_minor' => max(0, $amountMinor),
            'currency' => strtoupper($currency),
            'method' => $method,
            'status' => CharPackPurchase::STATUS_PENDING,
            'manual_payment_request_id' => $manualPaymentRequestId,
        ]);
    }

    /**
     * Adds a paid purchase to the member's pack. Safe to call more than once:
     * only the first call adds characters.
     */
    public function markPaid(CharPackPurchase $purchase): CharPackPurchase
    {
        return DB::transaction(function () use ($purchase): CharPackPurchase {
            /** @var CharPackPurchase $locked */
            $locked = CharPackPurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === CharPackPurchase::STATUS_PAID || $locked->status === CharPackPurchase::STATUS_REFUNDED) {
                return $locked;
            }

            $wallet = $this->lockedWallet((int) $locked->user_id);
            if ($wallet->isExpired() || $wallet->remaining() <= 0) {
                // A finished pack does not come back: start again from the new characters.
                $wallet->chars_total = (int) $locked->chars;
                $wallet->chars_used = 0;
            } else {
                $wallet->chars_total = (int) $wallet->chars_total + (int) $locked->chars;
            }
            $wallet->expires_at = now()->addDays(max(1, (int) $locked->days));
            $wallet->save();

            $locked->forceFill([
                'status' => CharPackPurchase::STATUS_PAID,
                'paid_at' => now(),
                'failure_message' => null,
            ])->save();

            Log::info('Character pack added.', [
                'user_id' => $locked->user_id,
                'purchase_id' => $locked->id,
                'chars' => $locked->chars,
                'method' => $locked->method,
            ]);

            return $locked;
        });
    }

    public function markFailed(CharPackPurchase $purchase, string $message): void
    {
        CharPackPurchase::query()
            ->whereKey($purchase->id)
            ->where('status', CharPackPurchase::STATUS_PENDING)
            ->update([
                'status' => CharPackPurchase::STATUS_FAILED,
                'failure_message' => mb_substr($message, 0, 500),
                'updated_at' => now(),
            ]);
    }

    /**
     * The payment was refunded: takes back what is still unused of this purchase.
     * Characters already spent stay spent.
     */
    public function refund(CharPackPurchase $purchase): int
    {
        return DB::transaction(function () use ($purchase): int {
            /** @var CharPackPurchase $locked */
            $locked = CharPackPurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== CharPackPurchase::STATUS_PAID) {
                return 0;
            }

            $wallet = $this->lockedWallet((int) $locked->user_id);
            $taken = min((int) $locked->chars, $wallet->remaining());
            $wallet->chars_total = max((int) $wallet->chars_used, (int) $wallet->chars_total - $taken);
            $wallet->save();

            $locked->forceFill([
                'status' => CharPackPurchase::STATUS_REFUNDED,
                'refunded_at' => now(),
            ])->save();

            Log::info('Character pack refunded.', [
                'user_id' => $locked->user_id,
                'purchase_id' => $locked->id,
                'chars_taken_back' => $taken,
            ]);

            return $taken;
        });
    }

    // ──────────────────────────────────────────────────────────────────
    //  Moving to a plan
    // ──────────────────────────────────────────────────────────────────

    /**
     * When a pack holder starts a paid plan, what is left of the pack is added
     * to this month's allowance as a gift from Listen, and the pack is closed.
     */
    public function moveLeftoverToPlan(Subscription $subscription): int
    {
        $userId = (int) $subscription->user_id;

        $leftover = DB::transaction(function () use ($userId): int {
            $wallet = CharPackWallet::query()->where('user_id', $userId)->lockForUpdate()->first();
            $leftover = (int) ($wallet?->remaining() ?? 0);
            if ($leftover <= 0) {
                return 0;
            }

            $wallet->chars_used = (int) $wallet->chars_total;
            $wallet->save();

            return $leftover;
        });

        if ($leftover > 0) {
            app(CharGiftService::class)->grant(
                $subscription,
                min($leftover, CharGiftService::MAX_GIFT),
                'Unused characters carried over from the premium pack.',
            );
        }

        return $leftover;
    }

    private function lockedWallet(int $userId): CharPackWallet
    {
        CharPackWallet::query()->firstOrCreate(['user_id' => $userId], ['chars_total' => 0, 'chars_used' => 0]);

        return CharPackWallet::query()->where('user_id', $userId)->lockForUpdate()->firstOrFail();
    }
}
