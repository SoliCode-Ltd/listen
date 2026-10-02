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

    protected static function monthLabel(string $ym): string
    {
        return \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $ym.'-01')->format('M Y');
    }
}
