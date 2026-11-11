<?php

namespace App\Filament\Resources\TextItemResource\Pages;

use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use App\Filament\Resources\TextItemResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTextItem extends EditRecord
{
    protected static string $resource = TextItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
