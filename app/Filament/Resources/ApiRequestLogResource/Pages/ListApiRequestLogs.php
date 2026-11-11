<?php

namespace App\Filament\Resources\ApiRequestLogResource\Pages;

use App\Filament\Concerns\SimplePages;
use App\Filament\Resources\ApiRequestLogResource;
use Filament\Resources\Pages\ListRecords;

class ListApiRequestLogs extends ListRecords
{
    use SimplePages;

    protected static string $resource = ApiRequestLogResource::class;
}
