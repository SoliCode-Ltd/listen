<?php

namespace App\Filament\Widgets;

use App\Models\SubscriptionCancellation;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Billing → Cancellations: how many cancelled and why, over the last 90 days. Cancellations
 * the member took back (turned renewal on again) are not counted; they have their own card.
 */
class CancellationReasonStats extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $since = now()->subDays(90);
        $counts = SubscriptionCancellation::query()->counted()
            ->where('cancelled_at', '>=', $since)
            ->selectRaw('reason, count(*) as n')
            ->groupBy('reason')
            ->pluck('n', 'reason')
            ->all();
        $total = array_sum($counts);
        $share = fn (int $n): string => $total > 0 ? round($n * 100 / $total).'% of cancellations' : '';
        $last30 = SubscriptionCancellation::query()->counted()->where('cancelled_at', '>=', now()->subDays(30))->count();
        $allTime = SubscriptionCancellation::query()->counted()->count();

        $stats = [
            Stat::make('Cancelled, last 90 days', (string) $total)
                ->description($last30.' in the last 30 days, '.$allTime.' in all'),
        ];
        foreach (SubscriptionCancellation::REASONS as $reason) {
            $n = (int) ($counts[$reason] ?? 0);
            $stats[] = Stat::make(SubscriptionCancellation::reasonLabel($reason), (string) $n)->description($share($n));
        }
        // Before the cancel dialog asked why, or cancelled in Stripe without its survey. A null key is '' in PHP.
        $none = (int) ($counts[''] ?? 0);
        $stats[] = Stat::make('No reason given', (string) $none)
            ->description('Cancelled before '.Carbon::parse(SubscriptionCancellation::REASONS_SINCE)->format('j M Y').', or in Stripe');
        $kept = SubscriptionCancellation::query()->whereNotNull('resumed_at')->where('resumed_at', '>=', $since)->count();
        $stats[] = Stat::make('Kept their plan', (string) $kept)
            ->description('Cancelled, then turned renewal back on');

        return $stats;
    }
}
