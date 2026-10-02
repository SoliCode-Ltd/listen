<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesBusinessStats;
use Filament\Widgets\Widget;

class BusinessBreakdowns extends Widget
{
    use UsesBusinessStats;

    protected static ?int $sort = -10;

    protected static string $view = 'filament.widgets.business-breakdowns';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $s = $this->stats();
        $g = $s['generations'] ?? [];
        $revenue = $s['revenue'] ?? [];
        $trials = $s['trials'] ?? [];
        $manual = $s['manual_payments'] ?? [];
        $members = $s['members'] ?? [];
        $engagement = $s['engagement'] ?? [];

        return [
            'meta' => $s['meta'] ?? [],
            'statErrors' => $s['errors'] ?? [],
            'cohorts' => $s['cohorts'] ?? [],
            'plans' => $s['plans'] ?? [],
            'providers' => $s['providers'] ?? [],
            'breakdowns' => array_filter([
                'Members by plan' => $revenue['members_by_plan'] ?? [],
                'Paying Stripe subscriptions' => $revenue['paying_by_plan'] ?? [],
                'Generations per member' => $engagement['generations_per_member'] ?? [],
                'Signup method' => $members['signup_method'] ?? [],
                'Target language (30 days)' => $g['by_language_30d'] ?? [],
                'Voice tier (30 days)' => $g['by_voice_tier_30d'] ?? [],
                'Performance style (30 days)' => $g['by_style_30d'] ?? [],
                'Trials by status' => $trials['by_status'] ?? [],
                'Trials by plan' => $trials['by_plan'] ?? [],
                'Manual payments by status' => $manual['by_status'] ?? [],
                'Manual payments by method' => $manual['by_method'] ?? [],
                'Generations by status (all time)' => $g['by_status'] ?? [],
            ]),
            'percent' => fn (?float $ratio) => $ratio === null ? '—' : static::percent($ratio),
            'monthLabel' => fn (string $ym) => static::monthLabel($ym),
        ];
    }
}
