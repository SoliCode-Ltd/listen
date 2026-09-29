<?php

namespace App\Filament\Widgets;

use App\Models\BillingTrial;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TrialOutcomeStats extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $counts = BillingTrial::outcomeCounts();
        $revenue = '$'.number_format($counts['revenue_cents'] / 100, 2);

        return [
            Stat::make('In trial', (string) $counts['trialing']),
            Stat::make('Paid', (string) $counts['paid'])->description($revenue.' collected'),
            Stat::make('Canceled', (string) $counts['canceled'])->description('Canceled before the charge'),
            Stat::make('Charge failed', (string) $counts['charge_failed']),
            Stat::make('Blocked', (string) $counts['blocked'])->description('Second attempt refused'),
            Stat::make('Conversion', $counts['conversion_rate'].'%')->description('Paid out of finished trials'),
        ];
    }
}
