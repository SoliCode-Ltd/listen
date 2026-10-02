<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesBusinessStats;
use Filament\Widgets\ChartWidget;

class ApiCostChart extends ChartWidget
{
    use UsesBusinessStats;

    protected static ?string $heading = 'API cost and characters per month';

    protected static ?string $description = 'Text-to-speech cost by provider (USD, stacked) and characters processed, members + demo + admins.';

    protected static ?int $sort = -17;

    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '300px';

    protected function getData(): array
    {
        $monthly = collect($this->stats()['api_usage']['monthly'] ?? []);

        return [
            'datasets' => [
                [
                    'type' => 'bar',
                    'label' => 'Google Cloud TTS (USD)',
                    'data' => $monthly->pluck('cost_google')->all(),
                    'backgroundColor' => 'rgba(59, 130, 246, 0.75)',
                    'stack' => 'cost',
                    'yAxisID' => 'y',
                ],
                [
                    'type' => 'bar',
                    'label' => 'Gemini (USD)',
                    'data' => $monthly->pluck('cost_gemini')->all(),
                    'backgroundColor' => 'rgba(16, 185, 129, 0.75)',
                    'stack' => 'cost',
                    'yAxisID' => 'y',
                ],
                [
                    'type' => 'bar',
                    'label' => 'Other (USD)',
                    'data' => $monthly->pluck('cost_other')->all(),
                    'backgroundColor' => 'rgba(156, 163, 175, 0.75)',
                    'stack' => 'cost',
                    'yAxisID' => 'y',
                ],
                [
                    'type' => 'line',
                    'label' => 'Characters (thousands)',
                    'data' => $monthly->map(fn ($r) => round($r['chars'] / 1000, 1))->all(),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'transparent',
                    'tension' => 0.3,
                    'yAxisID' => 'chars',
                ],
            ],
            'labels' => $monthly->pluck('month')->map(fn ($m) => static::monthLabel($m))->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true, 'position' => 'left', 'title' => ['display' => true, 'text' => 'USD']],
                'chars' => ['position' => 'right', 'grid' => ['drawOnChartArea' => false], 'title' => ['display' => true, 'text' => 'Characters (k)']],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
