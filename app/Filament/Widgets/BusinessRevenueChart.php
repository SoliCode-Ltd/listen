<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesBusinessStats;
use Filament\Widgets\ChartWidget;

class BusinessRevenueChart extends ChartWidget
{
    use UsesBusinessStats;

    protected static ?string $heading = 'Monetisation: new vs lost customers';

    protected static ?string $description = 'Stripe subscriptions started and ended, trials started and paid, and approved manual payments per month.';

    protected static ?int $sort = -18;

    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '300px';

    protected function getData(): array
    {
        $monthly = collect($this->stats()['monthly'] ?? []);

        $series = [
            'stripe_started' => ['Card plans bought (no trial)', 'rgba(16, 185, 129, 0.8)'],
            'stripe_ended' => ['Paid card plans ended', 'rgba(239, 68, 68, 0.8)'],
            'trials_started' => ['Trials started', 'rgba(245, 158, 11, 0.8)'],
            'trials_paid' => ['Trials paid', 'rgba(14, 165, 233, 0.8)'],
            'manual_approved' => ['Manual payments approved', 'rgba(139, 92, 246, 0.8)'],
        ];

        return [
            'datasets' => collect($series)->map(fn ($meta, $key) => [
                'label' => $meta[0],
                'data' => $monthly->pluck($key)->all(),
                'backgroundColor' => $meta[1],
            ])->values()->all(),
            'labels' => $monthly->pluck('month')->map(fn ($m) => static::monthLabel($m))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
