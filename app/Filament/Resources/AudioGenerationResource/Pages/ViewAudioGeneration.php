<?php

namespace App\Filament\Resources\AudioGenerationResource\Pages;

use App\Filament\Resources\AudioGenerationResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewAudioGeneration extends ViewRecord
{
    protected static string $resource = AudioGenerationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
