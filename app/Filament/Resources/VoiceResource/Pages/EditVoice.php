<?php

namespace App\Filament\Resources\VoiceResource\Pages;

use App\Filament\Resources\VoiceResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditVoice extends EditRecord
{
    protected static string $resource = VoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
