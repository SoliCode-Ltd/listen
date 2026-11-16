<?php

namespace App\Filament\Resources\AudioGenerationResource\Pages;

use App\Filament\Resources\AudioGenerationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAudioGenerations extends ListRecords
{
    protected static string $resource = AudioGenerationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
