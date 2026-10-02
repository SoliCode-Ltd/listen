<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesBusinessStats;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BusinessGrowthKpis extends BaseWidget
{
    use UsesBusinessStats;

    protected static ?int $sort = -30;

    protected static ?string $pollingInterval = null;

    protected ?string $heading = 'Members & engagement';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $s = $this->stats();
        $members = $s['members'] ?? [];
        $engagement = $s['engagement'] ?? [];
        $monthly = collect($s['monthly'] ?? []);

        $new30 = (int) ($members['new_30d'] ?? 0);
        $prev30 = (int) ($members['new_prev_30d'] ?? 0);
        $growth = $prev30 > 0 ? ($new30 - $prev30) / $prev30 : 0;

        return [
            Stat::make('Members', number_format($members['total'] ?? 0))
                ->description(number_format($members['new_today'] ?? 0).' joined today · admins excluded')
                ->descriptionIcon('heroicon-m-users')
                ->chart($monthly->pluck('signups')->all())
                ->color('primary'),
            Stat::make('New members (30 days)', number_format($new30))
                ->description(($growth >= 0 ? '+' : '').static::percent($growth).' vs previous 30 days · '.number_format($members['new_7d'] ?? 0).' in 7 days')
                ->descriptionIcon($growth >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($growth >= 0 ? 'success' : 'danger'),
            Stat::make('Activation rate', static::percent($engagement['activation_rate'] ?? 0))
                ->description(number_format($engagement['activated'] ?? 0).' members ever generated audio')
                ->descriptionIcon('heroicon-m-bolt')
                ->color(($engagement['activation_rate'] ?? 0) >= 0.4 ? 'success' : 'warning'),
            Stat::make('Active members (30 days)', number_format($engagement['active_30d'] ?? 0))
                ->description(number_format($engagement['active_7d'] ?? 0).' in 7 days · '.number_format($engagement['active_today'] ?? 0).' today')
                ->descriptionIcon('heroicon-m-fire')
                ->chart($monthly->pluck('active_members')->all())
                ->color('info'),
            Stat::make('Generations per active member', (string) ($engagement['generations_per_active_member_30d'] ?? 0))
                ->description('Average over the last 30 days')
                ->descriptionIcon('heroicon-m-musical-note'),
            Stat::make('Top 10% members share', static::percent($engagement['top_10pct_share'] ?? 0))
                ->description('of all generations come from the top 10% of users')
                ->descriptionIcon('heroicon-m-chart-pie'),
            Stat::make('Signed up with Google', number_format($members['signup_method']['Google'] ?? 0))
                ->description(number_format($members['email_verified'] ?? 0).' verified emails')
                ->descriptionIcon('heroicon-m-check-badge'),
            Stat::make('Blocked members', number_format($members['blocked'] ?? 0))
                ->description('Abuse / multi-account blocks')
                ->descriptionIcon('heroicon-m-no-symbol')
                ->color(($members['blocked'] ?? 0) > 0 ? 'danger' : 'gray'),
        ];
    }
}
