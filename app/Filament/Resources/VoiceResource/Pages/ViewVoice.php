<?php

namespace App\Filament\Resources\VoiceResource\Pages;

use App\Filament\Resources\VoiceResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewVoice extends ViewRecord
{
    protected static string $resource = VoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
