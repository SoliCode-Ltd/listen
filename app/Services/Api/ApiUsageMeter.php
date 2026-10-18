<?php

namespace App\Services\Api;

use App\Models\ApiKey;
use App\Models\ApiKeyUsage;
use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\TextItem;
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
 * and a generation that fails gives everything back, exactly once.
 *
 * What was reserved is stored on the generation (settings_snapshot.reservation),
 * so the correction and the refund always use the amounts really taken, in
 * the month they were taken.
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
     * @param  callable(array{period:string, billed_to_pack:bool, credits:int, seconds:int}): T  $record
     * @return array{0: array{period:string, billed_to_pack:bool, credits:int, seconds:int}, 1: T}
     */
    public function reserve(int $userId, ApiKey $apiKey, ?Plan $quotaPlan, array $estimate, callable $record): array
    {
        $period = now()->format('Y-m');
        UsageCounter::firstOrCreate(
            ['user_id' => $userId, 'period_ym' => $period],
            ['total_minutes' => 0, 'total_chars' => 0, 'total_requests' => 0]
        );
        $billToPack = CharPackService::billsToPack($quotaPlan);

        $reservation = [
            'period' => $period,
            'billed_to_pack' => $billToPack,
            'credits' => (int) $estimate['credits'],
            'seconds' => (int) $estimate['seconds'],
        ];

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
     * What reserve() took for this generation, as stored on it, or null for a
     * generation the developer API did not make.
     *
     * @return array{period:string, billed_to_pack:bool, credits:int, seconds:int}|null
     */
    public static function reservationOf(AudioGeneration $generation): ?array
    {
        $stored = ($generation->settings_snapshot ?? [])['reservation'] ?? null;
        if (! is_array($stored) || ! isset($stored['credits'], $stored['seconds'], $stored['period'])) {
            return null;
        }

        return [
            'period' => (string) $stored['period'],
            'billed_to_pack' => (bool) ($stored['billed_to_pack'] ?? false),
            'credits' => max(0, (int) $stored['credits']),
            'seconds' => max(0, (int) $stored['seconds']),
        ];
    }

    /**
     * Marks an API generation failed and gives back everything its request
     * took, when it is still in one of the given states. The row is locked
     * and the state checked first, so however many places try (the job, its
     * failed() hook, the stuck-generation sweep, the request itself), the
     * credits come back exactly once.
     *
     * @param  array<int, string>  $fromStatuses
     */
    public function failAndRefund(int $generationId, string $message, array $fromStatuses = ['queued', 'processing']): bool
    {
        $failed = DB::transaction(function () use ($generationId, $message, $fromStatuses): ?AudioGeneration {
            $generation = AudioGeneration::query()->whereKey($generationId)->lockForUpdate()->first();
            if (! $generation || ! in_array($generation->status, $fromStatuses, true)) {
                return null;
            }

            $generation->update(['status' => 'failed', 'error_message' => $message]);

            $reservation = self::reservationOf($generation);
            if ($reservation !== null) {
                $this->release((int) $generation->user_id, (int) $generation->api_key_id, $reservation);
            }

            return $generation;
        });

        if ($failed?->text_item_id) {
            TextItem::whereKey($failed->text_item_id)->update(['status' => 'failed']);
        }

        return $failed !== null;
    }

    /**
     * Gives back what reserve() took.
     *
     * @param  array{period:string, billed_to_pack:bool, credits:int, seconds:int}  $reservation
     */
    private function release(int $userId, int $apiKeyId, array $reservation): void
    {
        DB::transaction(function () use ($userId, $apiKeyId, $reservation): void {
            if ($reservation['billed_to_pack']) {
                $this->charPackService->release($userId, $reservation['credits']);
            } else {
                $counter = UsageCounter::query()
                    ->where('user_id', $userId)
                    ->where('period_ym', $reservation['period'])
                    ->lockForUpdate()
                    ->first();
                if ($counter) {
                    $counter->update([
                        'total_requests' => max(0, (int) $counter->total_requests - 1),
                        'total_chars' => max(0, (int) $counter->total_chars - $reservation['credits']),
                    ]);
                    $counter->addSeconds(-$reservation['seconds']);
                }
                $this->trialUsageRecorder->release($userId, ['credits' => $reservation['credits'], 'seconds' => $reservation['seconds']]);
            }

            if ($apiKeyId > 0) {
                ApiKeyUsage::apply($apiKeyId, $reservation['period'], -1, -$reservation['credits']);
            }
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
