<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesBusinessStats;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BusinessProductKpis extends BaseWidget
{
    use UsesBusinessStats;

    protected static ?int $sort = -28;

    protected static ?string $pollingInterval = null;

    protected ?string $heading = 'Product usage & growth channels (last 30 days)';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $s = $this->stats();
        $g = $s['generations'] ?? [];
        $channels = $s['growth_channels'] ?? [];
        $monthly = collect($s['monthly'] ?? []);
        $failure = (float) ($g['failure_rate_30d'] ?? 0);

        return [
            Stat::make('Generations', number_format($g['last_30d'] ?? 0))
                ->description(number_format($g['total'] ?? 0).' all time')
                ->descriptionIcon('heroicon-m-musical-note')
                ->chart($monthly->pluck('generations')->all())
                ->color('primary'),
            Stat::make('Failure rate', static::percent($failure))
                ->description(number_format($g['failed_30d'] ?? 0).' failed generations')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($failure > 0.05 ? 'danger' : ($failure > 0.01 ? 'warning' : 'success')),
            Stat::make('Audio minutes', number_format($g['audio_minutes_30d'] ?? 0, 1))
                ->description(number_format($g['billable_chars_30d'] ?? 0).' billable characters')
                ->descriptionIcon('heroicon-m-speaker-wave')
                ->chart($monthly->pluck('audio_minutes')->all()),
            Stat::make('Blocked generations', number_format($g['blocked_generations_30d'] ?? 0))
                ->description('Requests stopped by the content filter')
                ->descriptionIcon('heroicon-m-shield-exclamation')
                ->color(($g['blocked_generations_30d'] ?? 0) > 0 ? 'warning' : 'gray'),
            Stat::make('Demo generations', number_format($channels['demo_generations_30d'] ?? 0))
                ->description(number_format($channels['demo_visitors'] ?? 0).' demo visitors all time')
                ->descriptionIcon('heroicon-m-play')
                ->chart($monthly->pluck('demo_generations')->all()),
            Stat::make('Demo → paid', static::percent($channels['demo_paid_rate'] ?? 0))
                ->description(number_format($channels['demo_members_paid'] ?? 0).' of '.number_format($channels['demo_members'] ?? 0).' members who tried the demo have paid')
                ->descriptionIcon('heroicon-m-user-plus'),
            Stat::make('Translated requests', static::percent($g['translate_share_30d'] ?? 0))
                ->description(number_format($g['cloned_voices'] ?? 0).' cloned voices in total')
                ->descriptionIcon('heroicon-m-language'),
            Stat::make('Referral visits', number_format($channels['referral_visits'] ?? 0))
                ->description(collect($channels['referral_conversions'] ?? [])->map(fn ($n, $k) => "{$n} {$k}")->implode(' · ') ?: 'No conversions yet')
                ->descriptionIcon('heroicon-m-share'),
        ];
    }
}
