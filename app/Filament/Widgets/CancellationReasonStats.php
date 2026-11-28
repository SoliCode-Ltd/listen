<?php

namespace App\Filament\Widgets;

use App\Models\SubscriptionCancellation;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Billing → Cancellations: how many cancelled, and why, over the last 90 days. */
class CancellationReasonStats extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $since = now()->subDays(90);
        $counts = SubscriptionCancellation::query()
            ->whereNotNull('cancelled_at')
            ->where('cancelled_at', '>=', $since)
            ->selectRaw('reason, count(*) as n')
            ->groupBy('reason')
            ->pluck('n', 'reason')
            ->all();
        $total = array_sum($counts);
        $share = fn (int $n): string => $total > 0 ? round($n * 100 / $total).'% of cancellations' : '';

        $stats = [
            Stat::make('Cancelled, last 90 days', (string) $total)
                ->description(SubscriptionCancellation::query()->whereNotNull('cancelled_at')->where('cancelled_at', '>=', now()->subDays(30))->count().' in the last 30 days'),
        ];
        // A reason is required in the cancel dialog, so every cancellation listed has one.
        foreach (SubscriptionCancellation::REASONS as $reason) {
            $n = (int) ($counts[$reason] ?? 0);
            $stats[] = Stat::make(SubscriptionCancellation::reasonLabel($reason), (string) $n)->description($share($n));
        }

        return $stats;
    }
}
