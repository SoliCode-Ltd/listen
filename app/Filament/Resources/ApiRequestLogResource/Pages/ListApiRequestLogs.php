<?php

namespace App\Filament\Resources\ApiRequestLogResource\Pages;

use App\Filament\Resources\ApiRequestLogResource;
use Filament\Resources\Pages\ListRecords;

class ListApiRequestLogs extends ListRecords
{
    use \App\Filament\Concerns\SimplePages;

    protected static string $resource = ApiRequestLogResource::class;
}
