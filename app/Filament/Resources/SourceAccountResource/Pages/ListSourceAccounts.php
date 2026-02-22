<?php

namespace App\Filament\Resources\SourceAccountResource\Pages;

use App\Filament\Resources\SourceAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSourceAccounts extends ListRecords
{
    protected static string $resource = SourceAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
