<?php

namespace App\Filament\Resources\AudioGenerationResource\Pages;

use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use App\Filament\Resources\AudioGenerationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAudioGeneration extends EditRecord
{
    protected static string $resource = AudioGenerationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
