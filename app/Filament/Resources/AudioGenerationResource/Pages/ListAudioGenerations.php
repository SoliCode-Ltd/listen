<?php

namespace App\Filament\Resources\AudioGenerationResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\AudioGenerationResource;
use Filament\Actions;
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
