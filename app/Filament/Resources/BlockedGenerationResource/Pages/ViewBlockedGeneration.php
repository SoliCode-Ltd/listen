<?php

namespace App\Filament\Resources\BlockedGenerationResource\Pages;

use App\Filament\Resources\BlockedGenerationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewBlockedGeneration extends ViewRecord
{
    protected static string $resource = BlockedGenerationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
