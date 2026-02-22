<?php

namespace App\Filament\Resources\TextItemResource\Pages;

use App\Filament\Resources\TextItemResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewTextItem extends ViewRecord
{
    protected static string $resource = TextItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
