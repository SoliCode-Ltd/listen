<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesBusinessStats;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ApiUsageKpis extends BaseWidget
{
    use UsesBusinessStats;

    protected static ?int $sort = -27;

    protected static ?string $pollingInterval = null;

    protected ?string $heading = 'API usage & cost (last 30 days)';

    protected ?string $description = 'Every text-to-speech call: members, admins and the public demo. Each voice costs what the provider counted for it: every request, retries and the dialect rewrite included, at that day\'s price. Voices made before that was counted are estimated from its list prices.';

    protected function getColumns(): int
    {
        return 4;
    }

    /**
     * How much of the cost is exact (the provider's counts), logged before that, or estimated.
     *
     * @param  array<string, mixed>  $t
     */
    private static function costSources(array $t): string
    {
        $exact = (float) ($t['exact_share'] ?? 0);
        $estimated = (float) ($t['estimated_share'] ?? 0);
        $parts = array_filter([
            'exact' => $exact,
            'logged' => ($t['cost'] ?? 0) > 0 ? max(0.0, 1 - $exact - $estimated) : 0.0,
            'estimated' => $estimated,
        ], fn (float $share): bool => $share >= 0.0005);

        return $parts === [] ? '' : ' · '.implode(', ', array_map(fn (string $k, float $v): string => static::percent($v).' '.$k, array_keys($parts), $parts));
    }

    protected function getStats(): array
    {
        $s = $this->stats();
        $t = $s['api_usage']['totals_30d'] ?? [];
        $all = $s['api_usage']['totals_all_time'] ?? [];
        $unit = $s['unit_economics'] ?? [];
        $monthly = collect($s['api_usage']['monthly'] ?? []);
        $margin = $unit['gross_margin_rate'] ?? null;

        return [
            Stat::make('API cost', static::usd($t['cost'] ?? 0))
                ->description(static::usd($all['cost'] ?? 0).' all time'.static::costSources($t))
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->chart($monthly->pluck('cost_total')->all())
                ->color('danger'),
            Stat::make('Characters processed', static::compact($t['chars'] ?? 0))
                ->description(static::compact($t['api_chars'] ?? 0).' sent to providers · '.static::compact($all['chars'] ?? 0).' all time')
                ->descriptionIcon('heroicon-m-document-text')
                ->chart($monthly->pluck('chars')->all())
                ->color('primary'),
            Stat::make('Gross margin', $margin === null ? '—' : static::percent($margin))
                ->description('MRR '.static::usd($unit['mrr_usd'] ?? 0).' − API '.static::usd($unit['api_cost_30d'] ?? 0).' = '.static::usd($unit['gross_margin_usd'] ?? 0))
                ->descriptionIcon('heroicon-m-scale')
                ->color($margin === null ? 'gray' : ($margin >= 0.5 ? 'success' : 'danger')),
            // A voice can take several requests (long texts go in pieces, retries, the dialect rewrite).
            Stat::make('Voices sent to providers', number_format($t['api_requests'] ?? 0))
                ->description(number_format($t['cache_hits'] ?? 0).' served from cache ('.static::percent($t['cache_hit_rate'] ?? 0).') · '.number_format($t['failed'] ?? 0).' failed'
                    .(($t['api_calls'] ?? 0) > 0 ? ' · '.number_format($t['api_calls']).' provider requests counted' : ''))
                ->descriptionIcon('heroicon-m-signal'),
            Stat::make('Cost per 1,000 characters', static::usd($t['cost_per_1k_chars'] ?? 0, 4))
                ->description(static::usd($t['cost_per_audio_minute'] ?? 0, 4).' per audio minute')
                ->descriptionIcon('heroicon-m-calculator'),
            Stat::make('Cost by who used it', static::usd($t['member_cost'] ?? 0))
                ->description('members · demo '.static::usd($t['demo_cost'] ?? 0).' · admins '.static::usd($t['admin_cost'] ?? 0))
                ->descriptionIcon('heroicon-m-user-group'),
            Stat::make('API cost per active member', static::usd($unit['api_cost_per_active_member'] ?? 0, 3))
                ->description('Revenue per paying member '.static::usd($unit['revenue_per_paying_member'] ?? 0).' / month')
                ->descriptionIcon('heroicon-m-user'),
            Stat::make('Demo share of cost', static::percent($unit['demo_cost_share'] ?? 0))
                ->description(static::compact($t['demo_chars'] ?? 0).' demo characters · '.number_format($t['translated'] ?? 0).' translated requests')
                ->descriptionIcon('heroicon-m-play')
                ->color(($unit['demo_cost_share'] ?? 0) > 0.5 ? 'warning' : 'gray'),
        ];
    }
}
