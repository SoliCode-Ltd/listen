<?php

namespace App\Filament\Resources\NewsletterSuppressionResource\Pages;

use App\Filament\Resources\NewsletterSuppressionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListNewsletterSuppressions extends ListRecords
{
    protected static string $resource = NewsletterSuppressionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Add address'),
        ];
    }
}
