<?php

namespace App\Filament\Resources\PlanResource\Pages;

use App\Filament\Resources\PlanResource;
use App\Services\StripeProductSyncService;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPlans extends ListRecords
{
    protected static string $resource = PlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('syncAllStripe')
                ->label('Sync All to Stripe')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Sync All Plans to Stripe')
                ->modalDescription('This will create or update Stripe products, prices, and coupons for all active paid plans. Continue?')
                ->action(function () {
                    $syncService = app(StripeProductSyncService::class);
                    $result = $syncService->syncAllPlans();

                    PlanResource::stripeSyncCountsNotice($result['synced'], $result['failed'], $result['skipped'])->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
