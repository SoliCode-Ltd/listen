<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesBusinessStats;
use Filament\Widgets\ChartWidget;

class BusinessCostChart extends ChartWidget
{
    use UsesBusinessStats;

    protected static ?string $heading = 'TTS cost and billable characters';

    protected static ?string $description = 'Recorded provider cost estimates (USD) against characters billed to members per month.';

    protected static ?int $sort = -17;

    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '300px';

    protected function getData(): array
    {
        $monthly = collect($this->stats()['monthly'] ?? []);

        return [
            'datasets' => [
                [
                    'type' => 'bar',
                    'label' => 'Billable characters (thousands)',
                    'data' => $monthly->map(fn ($r) => round($r['billable_chars'] / 1000, 1))->all(),
                    'backgroundColor' => 'rgba(245, 158, 11, 0.6)',
                    'yAxisID' => 'y',
                ],
                [
                    'type' => 'line',
                    'label' => 'Est. cost (USD)',
                    'data' => $monthly->pluck('est_cost_usd')->all(),
                    'borderColor' => '#ef4444',
                    'backgroundColor' => 'transparent',
                    'tension' => 0.3,
                    'yAxisID' => 'cost',
                ],
            ],
            'labels' => $monthly->pluck('month')->map(fn ($m) => static::monthLabel($m))->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => ['position' => 'left', 'title' => ['display' => true, 'text' => 'Characters (k)']],
                'cost' => ['position' => 'right', 'grid' => ['drawOnChartArea' => false], 'title' => ['display' => true, 'text' => 'USD']],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
