<?php

namespace App\Filament\Widgets;

use App\Models\TtsLog;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class TtsProviderChart extends ChartWidget
{
    protected static ?string $heading = 'TTS Provider Usage (30 Days)';

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $startDate = Carbon::now()->subDays(29)->startOfDay();

        $logs = TtsLog::query()
            ->where('created_at', '>=', $startDate)
            ->selectRaw("DATE(created_at) as date, provider, COUNT(*) as count")
            ->groupByRaw('DATE(created_at), provider')
            ->orderBy('date')
            ->get();

        $dates = collect();
        for ($i = 0; $i < 30; $i++) {
            $dates->push($startDate->copy()->addDays($i)->format('Y-m-d'));
        }

        $googleData = [];
        $geminiData = [];

        foreach ($dates as $date) {
            $googleData[] = $logs->where('date', $date)->where('provider', 'google_cloud')->first()?->count ?? 0;
            $geminiData[] = $logs->where('date', $date)->where('provider', 'gemini')->first()?->count ?? 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Google Cloud TTS',
                    'data' => $googleData,
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'fill' => true,
                ],
                [
                    'label' => 'Gemini TTS',
                    'data' => $geminiData,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => true,
                ],
            ],
            'labels' => $dates->map(fn (string $d) => Carbon::parse($d)->format('M d'))->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
