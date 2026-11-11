<?php

namespace App\Filament\Resources\VoiceResource\Pages;

use Filament\Actions\EditAction;
use App\Filament\Resources\VoiceResource;
use Filament\Actions;
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
