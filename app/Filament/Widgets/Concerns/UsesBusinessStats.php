<?php

namespace App\Filament\Widgets\Concerns;

use App\Services\Analytics\BusinessStatsService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Shared access to the cached business statistics snapshot, honouring the
 * dashboard's period filter.
 */
trait UsesBusinessStats
{
    use InteractsWithPageFilters;

    protected function stats(): array
    {
        return app(BusinessStatsService::class)->snapshot(
            BusinessStatsService::normalizeMonths($this->filters['months'] ?? null)
        );
    }

    protected static function percent(float $ratio): string
    {
        return number_format($ratio * 100, 1).'%';
    }

    protected static function money(int|float $cents, string $currency = '$'): string
    {
        return $currency.number_format($cents / 100, 2);
    }

    protected static function stripeStatusLabel(string $status): string
    {
        return match ($status) {
            'incomplete_expired' => 'checkout expired',
            'incomplete' => 'checkout incomplete',
            'past_due' => 'past due',
            'unpaid' => 'unpaid',
            default => str_replace('_', ' ', $status),
        };
    }

    protected static function usd(float $amount, int $decimals = 2): string
    {
        return ($amount < 0 ? '-$' : '$').number_format(abs($amount), $decimals);
    }

    protected static function compact(int|float $n): string
    {
        return match (true) {
            $n >= 1_000_000 => round($n / 1_000_000, 2).'M',
            $n >= 10_000 => round($n / 1_000, 1).'k',
            default => number_format($n),
        };
    }

    protected static function monthLabel(string $ym): string
    {
        return \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $ym.'-01')->format('M Y');
    }
}
