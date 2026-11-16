<?php

namespace App\Filament\Resources\VoiceSampleResource\Pages;

use App\Filament\Resources\VoiceSampleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVoiceSample extends EditRecord
{
    protected static string $resource = VoiceSampleResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
