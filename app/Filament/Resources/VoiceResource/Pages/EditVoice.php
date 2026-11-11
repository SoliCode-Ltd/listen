<?php

namespace App\Filament\Resources\VoiceResource\Pages;

use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use App\Filament\Resources\VoiceResource;
use Filament\Actions;
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
