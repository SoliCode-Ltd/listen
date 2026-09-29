<?php

namespace App\Filament\Resources\BillingTrialResource\Pages;

use App\Filament\Resources\BillingTrialResource;
use App\Filament\Widgets\TrialOutcomeStats;
use Filament\Resources\Pages\ListRecords;

class ListBillingTrials extends ListRecords
{
    protected static string $resource = BillingTrialResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            TrialOutcomeStats::class,
        ];
    }
}
