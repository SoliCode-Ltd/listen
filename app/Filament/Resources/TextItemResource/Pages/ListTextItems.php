<?php

namespace App\Filament\Resources\TextItemResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\TextItemResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTextItems extends ListRecords
{
    protected static string $resource = TextItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
