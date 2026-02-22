<?php

namespace App\Filament\Resources\UsageCounterResource\Pages;

use App\Filament\Resources\UsageCounterResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewUsageCounter extends ViewRecord
{
    protected static string $resource = UsageCounterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
