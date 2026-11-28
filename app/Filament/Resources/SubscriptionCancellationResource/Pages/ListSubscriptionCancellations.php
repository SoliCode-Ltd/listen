<?php

namespace App\Filament\Resources\SubscriptionCancellationResource\Pages;

use App\Filament\Resources\SubscriptionCancellationResource;
use App\Filament\Widgets\CancellationReasonStats;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptionCancellations extends ListRecords
{
    protected static string $resource = SubscriptionCancellationResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            CancellationReasonStats::class,
        ];
    }
}
