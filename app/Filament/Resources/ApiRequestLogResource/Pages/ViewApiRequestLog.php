<?php

namespace App\Filament\Resources\ApiRequestLogResource\Pages;

use App\Filament\Resources\ApiRequestLogResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/** One API request, with everything kept about it. */
class ViewApiRequestLog extends ViewRecord
{
    protected static string $resource = ApiRequestLogResource::class;

    public function getTitle(): string|Htmlable
    {
        $r = $this->getRecord();

        return $r->method.' '.$r->path;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $r = $this->getRecord();

        return $r->status.' · '.$r->created_at?->toDayDateTimeString().' · '.$r->request_id;
    }
}
