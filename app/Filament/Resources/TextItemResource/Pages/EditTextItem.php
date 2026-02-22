<?php

namespace App\Filament\Resources\TextItemResource\Pages;

use App\Filament\Resources\TextItemResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTextItem extends EditRecord
{
    protected static string $resource = TextItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
