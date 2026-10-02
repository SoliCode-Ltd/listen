<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesBusinessStats;
use Filament\Widgets\ChartWidget;

class BusinessUsageChart extends ChartWidget
{
    use UsesBusinessStats;

    protected static ?string $heading = 'Usage: generations and audio minutes';

    protected static ?string $description = 'Member generations per month (successful vs failed) and minutes of audio produced.';

    protected static ?int $sort = -19;

    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '300px';

    protected function getData(): array
    {
        $monthly = collect($this->stats()['monthly'] ?? []);

        return [
            'datasets' => [
                [
                    'type' => 'bar',
                    'label' => 'Successful generations',
                    'data' => $monthly->map(fn ($r) => $r['generations'] - $r['failed_generations'])->all(),
                    'backgroundColor' => 'rgba(139, 92, 246, 0.7)',
                    'stack' => 'generations',
                    'yAxisID' => 'y',
                ],
                [
                    'type' => 'bar',
                    'label' => 'Failed generations',
                    'data' => $monthly->pluck('failed_generations')->all(),
                    'backgroundColor' => 'rgba(239, 68, 68, 0.8)',
                    'stack' => 'generations',
                    'yAxisID' => 'y',
                ],
                [
                    'type' => 'line',
                    'label' => 'Audio minutes',
                    'data' => $monthly->pluck('audio_minutes')->all(),
                    'borderColor' => '#0ea5e9',
                    'backgroundColor' => 'transparent',
                    'tension' => 0.3,
                    'yAxisID' => 'minutes',
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
                'y' => ['stacked' => true, 'position' => 'left', 'title' => ['display' => true, 'text' => 'Generations']],
                'minutes' => ['position' => 'right', 'grid' => ['drawOnChartArea' => false], 'title' => ['display' => true, 'text' => 'Minutes']],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
