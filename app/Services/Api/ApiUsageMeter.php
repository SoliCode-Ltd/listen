<?php

namespace App\Services\Api;

use App\Models\ApiKey;
use App\Models\ApiKeyUsage;
use App\Models\Plan;
use App\Models\UsageCounter;
use App\Services\Billing\CharPackService;
use App\Services\Billing\TrialUsageRecorder;
use App\Support\Api\ApiError;
use App\Support\PlanUsageGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Charges API generations to the same allowance as the dashboard: this
 * month's usage counter (or the member's pack), the trial meter and, for the
 * key's own budget, the key's monthly row. Credits are reserved from the text
 * before generating; GenerateAudioJob corrects them to the real audio length,
 * and a generation that fails gives everything back.
 *
 * Plans never go over their credits (PlanUsageGuard::allowsOverage is false
 * everywhere), so the API stops at the plan limit: a hard cap.
 */
class ApiUsageMeter
{
    public function __construct(
        private readonly CharPackService $charPackService,
        private readonly TrialUsageRecorder $trialUsageRecorder,
    ) {}

    /**
     * Checks every limit, reserves the estimate and runs $record (which saves
     * the generation) in one transaction, or throws an ApiError. Requests from
     * one member are handled one at a time (the month's usage row is locked),
     * so two at once cannot both spend the last credits or the day's last
     * generation; if $record fails, nothing is reserved.
     *
     * @template T
     *
     * @param  array{chars:int,words:int,seconds:int,minutes:int,credits:int}  $estimate
     * @param  callable(array{period:string, billed_to_pack:bool}): T  $record
     * @return array{0: array{period:string, billed_to_pack:bool}, 1: T}
     */
    public function reserve(int $userId, ApiKey $apiKey, ?Plan $quotaPlan, array $estimate, callable $record): array
    {
        $period = now()->format('Y-m');
        UsageCounter::firstOrCreate(
            ['user_id' => $userId, 'period_ym' => $period],
            ['total_minutes' => 0, 'total_chars' => 0, 'total_requests' => 0]
        );
        $billToPack = CharPackService::billsToPack($quotaPlan);

        $reservation = ['period' => $period, 'billed_to_pack' => $billToPack];

        $recorded = DB::transaction(function () use ($userId, $apiKey, $quotaPlan, $estimate, $period, $billToPack, $reservation, $record) {
            $counter = UsageCounter::query()
                ->where('user_id', $userId)
                ->where('period_ym', $period)
                ->lockForUpdate()
                ->firstOrFail();

            try {
                PlanUsageGuard::assertWithinDailyLimit($quotaPlan, $userId);
            } catch (ValidationException $e) {
                throw new ApiError(429, ApiError::TYPE_QUOTA, 'daily_limit_exceeded', self::firstMessage($e), null, [
                    'Retry-After' => (string) max(1, (int) ceil(now()->diffInSeconds(PlanUsageGuard::startOfMemberDay()->addDay(), true))),
                ]);
            }

            $keyLimit = (int) ($apiKey->monthly_credit_limit ?? 0);
            if ($keyLimit > 0) {
                $keyUsed = (int) ApiKeyUsage::query()
                    ->where('api_key_id', $apiKey->id)
                    ->where('period_ym', $period)
                    ->lockForUpdate()
                    ->value('credits');
                if ($keyUsed + (int) $estimate['credits'] > $keyLimit) {
                    throw new ApiError(402, ApiError::TYPE_QUOTA, 'key_credit_limit_exceeded',
                        'This API key has reached its monthly credit limit. Raise the key\'s limit in the dashboard or use another key.',
                        null, [], ['credits_needed' => (int) $estimate['credits'], 'credits_remaining' => max(0, $keyLimit - $keyUsed)]);
                }
            }

            if ($billToPack) {
                try {
                    $this->charPackService->reserve($userId, (int) $estimate['credits']);
                } catch (ValidationException $e) {
                    throw self::quotaExceeded($e, (int) $estimate['credits']);
                }
            } else {
                try {
                    PlanUsageGuard::assertWithinLimits($quotaPlan, $counter, $estimate);
                } catch (ValidationException $e) {
                    throw self::quotaExceeded($e, (int) $estimate['credits']);
                }

                $counter->increment('total_requests');
                $counter->increment('total_chars', (int) $estimate['credits']);
                $counter->addSeconds((int) $estimate['seconds']);
                $this->trialUsageRecorder->add($userId, $estimate);
            }

            ApiKeyUsage::apply((int) $apiKey->id, $period, 1, (int) $estimate['credits']);

            return $record($reservation);
        });

        return [$reservation, $recorded];
    }

    /**
     * Gives back what reserve() took, for a generation that failed.
     *
     * @param  array{chars:int,words:int,seconds:int,minutes:int,credits:int}  $estimate
     * @param  array{period:string, billed_to_pack:bool}  $reservation
     */
    public function release(int $userId, ApiKey $apiKey, array $estimate, array $reservation): void
    {
        DB::transaction(function () use ($userId, $apiKey, $estimate, $reservation): void {
            if ($reservation['billed_to_pack']) {
                $this->charPackService->release($userId, (int) $estimate['credits']);
            } else {
                $counter = UsageCounter::query()
                    ->where('user_id', $userId)
                    ->where('period_ym', $reservation['period'])
                    ->lockForUpdate()
                    ->first();
                if ($counter) {
                    $counter->update([
                        'total_requests' => max(0, (int) $counter->total_requests - 1),
                        'total_chars' => max(0, (int) $counter->total_chars - (int) $estimate['credits']),
                    ]);
                    $counter->addSeconds(-(int) $estimate['seconds']);
                }
                $this->trialUsageRecorder->release($userId, $estimate);
            }

            ApiKeyUsage::apply((int) $apiKey->id, $reservation['period'], -1, -(int) $estimate['credits']);
        });
    }

    private static function quotaExceeded(ValidationException $e, int $needed): ApiError
    {
        return new ApiError(402, ApiError::TYPE_QUOTA, 'quota_exceeded', self::firstMessage($e), null, [], [
            'credits_needed' => $needed,
        ]);
    }

    private static function firstMessage(ValidationException $e): string
    {
        foreach ($e->errors() as $messages) {
            foreach ((array) $messages as $message) {
                return (string) $message;
            }
        }

        return 'Your plan has no credits left for this request.';
    }
}
