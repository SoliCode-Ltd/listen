<?php

namespace App\Filament\Widgets;

use App\Models\UsageCounter;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class UsageChart extends ChartWidget
{
    protected static ?string $heading = 'Character Usage (Last 6 Months)';

    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 'full';

    protected function getData(): array
    {
        $data = [];
        $labels = [];

        for ($i = 5; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
            $periodYm = $date->format('Y-m');
            $labels[] = $date->format('M Y');
            $data[] = UsageCounter::where('period_ym', $periodYm)->sum('total_chars');
        }

        return [
            'datasets' => [
                [
                    'label' => 'Total Characters',
                    'data' => $data,
                    'backgroundColor' => [
                        'rgba(139, 92, 246, 0.8)',
                        'rgba(168, 85, 247, 0.8)',
                        'rgba(192, 132, 252, 0.8)',
                        'rgba(216, 180, 254, 0.8)',
                        'rgba(139, 92, 246, 0.8)',
                        'rgba(168, 85, 247, 0.8)',
                    ],
                    'borderRadius' => 6,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
