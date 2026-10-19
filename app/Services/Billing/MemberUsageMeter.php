<?php

namespace App\Services\Billing;

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\TextItem;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Api\ApiUsageMeter;
use App\Support\CreditPeriod;
use App\Support\EmailVerification;
use App\Support\PlanUsageGuard;
use App\Support\UsageEstimator;
use App\Support\VoiceTier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Charges a member's own generations (the studio and the browser extension)
 * to their allowance, as ApiUsageMeter does for the developer API: the usage
 * counter of the credit period they were made in, or the member's pack, and
 * the trial meter. Credits are reserved from the text before generating;
 * GenerateAudioJob corrects them to the real audio length, and a generation
 * that fails gives back everything its request reserved, exactly once.
 * Credits nobody paid for are spent only by an account whose email is
 * confirmed, as the free demo is (App\Support\EmailVerification).
 */
class MemberUsageMeter
{
    public function __construct(
        private readonly CharPackService $charPackService,
        private readonly TrialUsageRecorder $trialUsageRecorder,
    ) {}

    /**
     * Checks the daily and monthly limits, reserves the estimate and runs
     * $record (which saves the generation) in one transaction, or throws the
     * limit's ValidationException. A member's requests are taken one at a time
     * (their generations, then their usage row, are locked), so two at once
     * cannot both spend the last credits or the day's last generation; if
     * $record fails, nothing is reserved. What was reserved is kept on the
     * generation, so its correction and its refund use exactly that.
     *
     * @param  array{chars:int,words:int,seconds:int,minutes:int,credits:int}  $estimate
     * @param  callable(array{period:string, billed_to_pack:bool, credits:int, seconds:int}): array{item:TextItem, job:AudioGeneration}  $record
     * @return array{item:TextItem, job:AudioGeneration}
     */
    public function reserve(int $userId, ?Plan $quotaPlan, array $estimate, callable $record): array
    {
        // Made when missing before the transaction, which then reads only under its locks.
        $period = CreditPeriod::counter($userId)->period_ym;
        $reservation = [
            'period' => $period,
            'billed_to_pack' => CharPackService::billsToPack($quotaPlan),
            'credits' => (int) $estimate['credits'],
            'seconds' => (int) $estimate['seconds'],
        ];

        return DB::transaction(function () use ($userId, $quotaPlan, $estimate, $reservation, $record): array {
            // The member's generations first, then the usage row: the job and the
            // refunds lock a generation before the usage row, the same order.
            AudioGeneration::query()->where('user_id', $userId)->lockForUpdate()->max('user_sequence_number');
            $counter = UsageCounter::query()
                ->where('user_id', $userId)
                ->where('period_ym', $reservation['period'])
                ->lockForUpdate()
                ->firstOrFail();

            PlanUsageGuard::assertWithinDailyLimit($quotaPlan, $userId);

            if ($reservation['billed_to_pack']) {
                $this->charPackService->reserve($userId, $reservation['credits']);
            } else {
                PlanUsageGuard::assertWithinLimits($quotaPlan, $counter, $estimate);
                $this->assertMaySpendFreeCredits($userId);
                $counter->increment('total_requests');
                $counter->increment('total_chars', $reservation['credits']);
                $counter->addSeconds($reservation['seconds']);
                $this->trialUsageRecorder->add($userId, $estimate);
            }

            $recorded = $record($reservation);
            $job = $recorded['job'];
            $job->forceFill(['settings_snapshot' => array_merge((array) ($job->settings_snapshot ?? []), ['reservation' => $reservation])])->save();

            return $recorded;
        }, 3);
    }

    /**
     * The free plan's monthly credits and gifted credits need a confirmed
     * email; whoever pays or has paid never waits for it. Checked after the
     * limits, so a member without credits is told that first, and before
     * anything is reserved.
     */
    private function assertMaySpendFreeCredits(int $userId): void
    {
        $user = User::query()->find($userId);
        if (EmailVerification::requiredForFreeUse($user)) {
            throw ValidationException::withMessages([
                'content' => __('ui.verify_email.studio_required', ['email' => (string) $user->email]),
            ]);
        }
    }

    /**
     * What a generation's request reserved: kept on it when it was made (the
     * developer API, and now the studio and the extension), else the estimate
     * from its text, which is what the studio and the extension reserved before.
     *
     * @return array{period:string, billed_to_pack:bool, credits:int, seconds:int}
     */
    public static function reservationOf(AudioGeneration $generation): array
    {
        $stored = ApiUsageMeter::reservationOf($generation);
        if ($stored !== null) {
            return $stored;
        }

        $estimate = UsageEstimator::estimate((string) $generation->input_text, (string) ($generation->voice_tier ?: VoiceTier::STANDARD), (float) ($generation->speed ?: 1.0),
            $generation->only(['performance_style', 'emotion', 'dialect', 'style_prompt']));

        return [
            'period' => CreditPeriod::ofGeneration($generation),
            'billed_to_pack' => (bool) $generation->billed_to_pack,
            'credits' => $estimate['credits'],
            'seconds' => $estimate['seconds'],
        ];
    }

    /**
     * Marks a member's generation failed and gives back what its request
     * reserved, when it is still in one of the given states. The row is locked
     * and its state checked first, so however many places try (the job, its
     * failed() hook, the request, the stuck-generation sweep, deleting it),
     * the credits come back exactly once. Developer API generations are left
     * to ApiUsageMeter.
     *
     * @param  array<int, string>  $fromStatuses
     */
    public function failAndRefund(int $generationId, string $message, array $fromStatuses = ['queued', 'processing']): bool
    {
        $failed = DB::transaction(function () use ($generationId, $message, $fromStatuses): ?AudioGeneration {
            $generation = AudioGeneration::query()->whereKey($generationId)->lockForUpdate()->first();
            if (! $generation || $generation->api_key_id || ! in_array($generation->status, $fromStatuses, true)) {
                return null;
            }

            $generation->update(['status' => 'failed', 'error_message' => $message]);
            $this->release((int) $generation->user_id, self::reservationOf($generation));

            return $generation;
        }, 3);

        if ($failed?->text_item_id) {
            TextItem::whereKey($failed->text_item_id)->update(['status' => 'failed']);
        }

        return $failed !== null;
    }

    /**
     * Gives back what a request reserved, to the period (or the pack) it was
     * taken from. The usage row is locked and changed where it stands, so
     * another request's reservation made meanwhile is never written over.
     *
     * @param  array{period:string, billed_to_pack:bool, credits:int, seconds:int}  $reservation
     */
    private function release(int $userId, array $reservation): void
    {
        if ($reservation['billed_to_pack']) {
            $this->charPackService->release($userId, $reservation['credits']);

            return;
        }

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
}
