<?php

namespace App\Filament\Widgets;

use App\Models\UsageCounter;
use App\Support\PlanCostEstimate;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * This month's totals on the Monthly usage page.
 */
class MonthlyUsageStats extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        // Periods that started this month: calendar months and plans' own months alike.
        $month = UsageCounter::query()->where('period_ym', 'like', now()->format('Y-m').'%');
        $members = (clone $month)->where('total_requests', '>', 0)->count();
        $chars = (int) (clone $month)->sum('total_chars');
        $generations = (int) (clone $month)->sum('total_requests');
        $seconds = (int) (clone $month)->sum('total_seconds');
        $gifted = (int) (clone $month)->sum('bonus_chars');

        return [
            Stat::make('Members who generated', number_format($members))->description(now()->format('F Y')),
            Stat::make('Characters used', number_format($chars))
                ->description('≈ $'.number_format(PlanCostEstimate::forChars($chars)['cost'], 2).' if all on the premium voice'),
            Stat::make('Generations', number_format($generations)),
            Stat::make('Audio length', number_format($seconds / 60, 1).' min')
                ->description('Real length of the audio made this month'),
            Stat::make('Characters gifted', number_format($gifted)),
        ];
    }
}
