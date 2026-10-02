<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesBusinessStats;
use Filament\Widgets\ChartWidget;

class BusinessGrowthChart extends ChartWidget
{
    use UsesBusinessStats;

    protected static ?string $heading = 'Growth: signups vs active members';

    protected static ?string $description = 'Monthly signups (excl. admins), members who generated audio that month, and demo generations.';

    protected static ?int $sort = -20;

    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '300px';

    protected function getData(): array
    {
        $monthly = collect($this->stats()['monthly'] ?? []);

        return [
            'datasets' => [
                [
                    'label' => 'Signups',
                    'data' => $monthly->pluck('signups')->all(),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Active members',
                    'data' => $monthly->pluck('active_members')->all(),
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Demo generations',
                    'data' => $monthly->pluck('demo_generations')->all(),
                    'borderColor' => '#6366f1',
                    'backgroundColor' => 'transparent',
                    'borderDash' => [6, 4],
                    'tension' => 0.3,
                ],
            ],
            'labels' => $monthly->pluck('month')->map(fn ($m) => static::monthLabel($m))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
