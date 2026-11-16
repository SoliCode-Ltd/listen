<?php

namespace App\Filament\Resources\DemoGuestUsageResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\DemoGuestUsageResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDemoGuestUsages extends ListRecords
{
    protected static string $resource = DemoGuestUsageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
