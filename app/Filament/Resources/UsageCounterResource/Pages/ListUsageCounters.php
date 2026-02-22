<?php

namespace App\Filament\Resources\UsageCounterResource\Pages;

use App\Filament\Resources\UsageCounterResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUsageCounters extends ListRecords
{
    protected static string $resource = UsageCounterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
