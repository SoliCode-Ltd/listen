<?php

namespace App\Services;

use App\Models\DemoVoiceGeneration;
use App\Models\Plan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DemoHistoryImporter
{
    /**
     * Keep demo rows tagged for paid users. No history copy is created.
     */
    public function importForPaidUser(int $userId, ?Plan $activePlan, int $limit = 25): int
    {
        if ($userId <= 0 || ! $this->isPaidPlan($activePlan)) {
            return 0;
        }

        $planSlug = strtolower(trim((string) ($activePlan?->slug ?? '')));

        return $this->syncPaidTagOnDemoRows($userId, true, $planSlug, $limit);
    }

    /**
     * @return array{moved_to_history:int,moved_to_demo:int}
     */
    public function syncStorageForPlan(int $userId, ?Plan $activePlan, int $limit = 25): array
    {
        if ($userId <= 0) {
            return ['moved_to_history' => 0, 'moved_to_demo' => 0];
        }

        $isPaid = $this->isPaidPlan($activePlan);
        $planSlug = strtolower(trim((string) ($activePlan?->slug ?? '')));

        // Runs on every signed-in page: while the member's paid state and plan are
        // what they were at the last run (in the last 10 minutes), the rows are left alone.
        $state = ($isPaid ? 'paid' : 'free').':'.$planSlug;
        $stateKey = 'demo_history_plan_state:'.$userId;
        if (Cache::get($stateKey) === $state) {
            return ['moved_to_history' => 0, 'moved_to_demo' => 0];
        }

        $updated = $this->syncPaidTagOnDemoRows($userId, $isPaid, $planSlug, $limit);
        Cache::put($stateKey, $state, now()->addMinutes(10));

        return [
            'moved_to_history' => $isPaid ? $updated : 0,
            'moved_to_demo' => $isPaid ? 0 : $updated,
        ];
    }

    private function isPaidPlan(?Plan $plan): bool
    {
        if (! $plan) {
            return false;
        }

        $slug = strtolower(trim((string) ($plan->slug ?? '')));
        $priceCents = (int) ($plan->price_cents ?? 0);

        return $priceCents > 0 || ($slug !== '' && $slug !== 'free');
    }

    private function syncPaidTagOnDemoRows(int $userId, bool $isPaid, string $planSlug, int $limit = 25): int
    {
        $batchSize = max(1, min(500, $limit));

        $candidateIds = DemoVoiceGeneration::query()
            ->where('user_id', $userId)
            ->whereNotNull('audio_path')
            ->where('audio_path', '<>', '')
            ->orderByDesc('id')
            ->limit($batchSize)
            ->pluck('id')
            ->all();

        $updated = 0;

        foreach ($candidateIds as $generationId) {
            try {
                $didUpdate = DB::transaction(function () use ($generationId, $userId, $isPaid, $planSlug): bool {
                    $demo = DemoVoiceGeneration::query()
                        ->whereKey($generationId)
                        ->lockForUpdate()
                        ->first();

                    if (! $demo || (int) $demo->user_id !== $userId) {
                        return false;
                    }

                    $existingMetadata = is_array($demo->metadata) ? $demo->metadata : [];
                    $nextMetadata = $existingMetadata;
                    $nextMetadata['paid_user'] = $isPaid;

                    if ($isPaid && $planSlug !== '' && $planSlug !== 'free') {
                        $nextMetadata['paid_plan_slug'] = $planSlug;
                    } else {
                        unset($nextMetadata['paid_plan_slug']);
                    }

                    // No duplication between demo and audio generations.
                    $hadLegacyCopyKeys = array_key_exists('copied_to_history', $nextMetadata)
                        || array_key_exists('copied_to_history_at', $nextMetadata)
                        || array_key_exists('copied_audio_generation_id', $nextMetadata);
                    unset(
                        $nextMetadata['copied_to_history'],
                        $nextMetadata['copied_to_history_at'],
                        $nextMetadata['copied_audio_generation_id']
                    );

                    $expectedPlanSlug = ($isPaid && $planSlug !== '' && $planSlug !== 'free') ? $planSlug : null;
                    $currentPlanSlug = isset($existingMetadata['paid_plan_slug']) ? (string) $existingMetadata['paid_plan_slug'] : null;
                    $stateChanged = ($existingMetadata['paid_user'] ?? null) !== $isPaid
                        || $currentPlanSlug !== $expectedPlanSlug
                        || $hadLegacyCopyKeys;

                    if ($stateChanged) {
                        $nextMetadata['paid_user_updated_at'] = now()->toIso8601String();
                    }

                    if ($nextMetadata === $existingMetadata) {
                        return false;
                    }

                    $demo->metadata = $nextMetadata;
                    $demo->save();

                    return true;
                }, 3);

                if ($didUpdate) {
                    $updated++;
                }
            } catch (\Throwable $e) {
                Log::warning('Failed syncing paid tag on demo generation.', [
                    'user_id' => $userId,
                    'demo_generation_id' => $generationId,
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                ]);
            }
        }

        return $updated;
    }
}
