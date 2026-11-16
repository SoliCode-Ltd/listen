<?php

namespace App\Filament\Resources\BillingTrialResource\Pages;

use Filament\Actions\Action;
use App\Filament\Resources\BillingTrialResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewBillingTrial extends ViewRecord
{
    protected static string $resource = BillingTrialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            BillingTrialResource::configureCancelAction(Action::make('cancelTrial'))
                ->after(fn () => $this->record->refresh()),
        ];
    }
}
