<?php

namespace App\Services\Billing;

use App\Models\AudioGeneration;
use App\Models\TextItem;
use App\Models\UsageCounter;
use App\Services\Api\ApiUsageMeter;
use App\Support\CreditPeriod;
use App\Support\UsageEstimator;
use Illuminate\Support\Facades\DB;

/**
 * Charges a member's own generations (the studio and the browser extension)
 * to their allowance, as ApiUsageMeter does for the developer API: the usage
 * counter of the credit period they were made in, or the member's pack, and
 * the trial meter. A generation that fails gives back everything its request
 * reserved, exactly once.
 */
class MemberUsageMeter
{
    public function __construct(
        private readonly CharPackService $charPackService,
        private readonly TrialUsageRecorder $trialUsageRecorder,
    ) {}

    /**
     * What a generation's request reserved: kept on it when it was made (the
     * developer API keeps it), else the estimate from its text, which is what
     * the studio and the extension reserve.
     *
     * @return array{period:string, billed_to_pack:bool, credits:int, seconds:int}
     */
    public static function reservationOf(AudioGeneration $generation): array
    {
        $stored = ApiUsageMeter::reservationOf($generation);
        if ($stored !== null) {
            return $stored;
        }

        $estimate = UsageEstimator::estimate((string) $generation->input_text);

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
