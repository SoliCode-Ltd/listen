<?php

namespace App\Filament\Resources\UsageCounterResource\Pages;

use App\Filament\Resources\UsageCounterResource;
use App\Filament\Widgets\MonthlyUsageStats;
use Filament\Resources\Pages\ListRecords;

class ListUsageCounters extends ListRecords
{
    protected static string $resource = UsageCounterResource::class;

    public function getSubheading(): ?string
    {
        return 'One row per member per month: what they generated in the dashboard and the browser extension from their monthly allowance. '
            .'Demo generations and premium pack characters are not counted here. Click a member to open their account, or Details for the month.';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            MonthlyUsageStats::class,
        ];
    }
}
