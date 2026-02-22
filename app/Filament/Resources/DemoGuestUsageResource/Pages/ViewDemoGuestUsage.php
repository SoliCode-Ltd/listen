<?php

namespace App\Filament\Resources\DemoGuestUsageResource\Pages;

use App\Filament\Resources\DemoGuestUsageResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewDemoGuestUsage extends ViewRecord
{
    protected static string $resource = DemoGuestUsageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
