<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsUsageFilters;
use App\Services\Analytics\UsageInsights;
use Filament\Widgets\Widget;

/**
 * What members chose in the month picked on the Monthly usage page: where they
 * made it, studio features, voices, accents, emotions, styles and environments.
 */
class MonthlyUsageBreakdown extends Widget
{
    use ReadsUsageFilters;

    protected static bool $isDiscovered = false;

    protected static string $view = 'filament.widgets.usage-breakdown';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        return [
            'monthLabel' => $this->usageMonthLabel(),
            'breakdowns' => app(UsageInsights::class)->breakdowns($this->usageGenerations($this->usageMonth())),
        ];
    }
}
