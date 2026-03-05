<?php

namespace App\Filament\Resources\VoiceSampleResource\Pages;

use App\Filament\Resources\VoiceSampleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListVoiceSamples extends ListRecords
{
    protected static string $resource = VoiceSampleResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
