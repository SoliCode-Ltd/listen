<?php

namespace App\Filament\Resources\SourceAccountResource\Pages;

use App\Filament\Resources\SourceAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSourceAccount extends EditRecord
{
    protected static string $resource = SourceAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
