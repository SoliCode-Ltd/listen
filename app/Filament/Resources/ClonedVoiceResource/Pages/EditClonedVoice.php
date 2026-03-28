<?php

namespace App\Filament\Resources\ClonedVoiceResource\Pages;

use App\Filament\Resources\ClonedVoiceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditClonedVoice extends EditRecord
{
    protected static string $resource = ClonedVoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
