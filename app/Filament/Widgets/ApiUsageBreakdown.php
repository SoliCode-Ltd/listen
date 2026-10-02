<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesBusinessStats;
use Filament\Widgets\Widget;

class ApiUsageBreakdown extends Widget
{
    use UsesBusinessStats;

    protected static ?int $sort = -15;

    protected static string $view = 'filament.widgets.api-usage-breakdown';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $s = $this->stats();
        $api = $s['api_usage'] ?? [];

        return [
            'months' => $s['meta']['months'] ?? 12,
            'byModel30' => $api['by_model_30d'] ?? [],
            'byModelPeriod' => $api['by_model_period'] ?? [],
            'totalsPeriod' => $api['totals_period'] ?? [],
            'charsByPlan' => $api['chars_by_plan_30d'] ?? [],
            'quotaByMonth' => $api['quota_chars_by_month'] ?? [],
            'monthly' => $api['monthly'] ?? [],
            'geminiFreeTier' => (bool) config('analytics.gemini_tts.free_tier'),
            'percent' => fn (?float $ratio) => $ratio === null ? '—' : static::percent($ratio),
            'usd' => fn (float $v, int $d = 2) => static::usd($v, $d),
            'compact' => fn ($n) => static::compact($n),
            'monthLabel' => fn (string $ym) => static::monthLabel($ym),
        ];
    }
}
