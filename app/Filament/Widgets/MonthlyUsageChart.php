<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsUsageFilters;
use App\Services\Analytics\UsageInsights;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Generations and members who generated, each day of the month picked on the Monthly usage page.
 */
class MonthlyUsageChart extends ChartWidget
{
    use ReadsUsageFilters;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '260px';

    public function getHeading(): ?string
    {
        return 'Each day in '.$this->usageMonthLabel($this->chartMonth());
    }

    public function getDescription(): ?string
    {
        return 'Generations made and members who made them. Failed generations are left out.';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $ym = $this->chartMonth();
        $days = app(UsageInsights::class)->daily($this->usageGenerations($ym), $ym);

        return [
            'datasets' => [
                [
                    'label' => 'Generations',
                    'data' => array_column($days, 'generations'),
                    'backgroundColor' => 'rgba(118, 89, 250, 0.7)',
                    'borderColor' => 'rgb(118, 89, 250)',
                ],
                [
                    'label' => 'Members',
                    'data' => array_column($days, 'members'),
                    'backgroundColor' => 'rgba(245, 158, 11, 0.7)',
                    'borderColor' => 'rgb(245, 158, 11)',
                ],
            ],
            'labels' => array_map(fn (string $day): string => Carbon::parse($day)->format('j M'), array_keys($days)),
        ];
    }

    /** Every month at once has no days to draw, so it shows this month. */
    private function chartMonth(): string
    {
        return $this->usageMonth() ?? now()->format('Y-m');
    }
}
