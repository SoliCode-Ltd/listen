<?php

namespace App\Filament\Resources\ManualPaymentRequestResource\Pages;

use App\Filament\Resources\ManualPaymentRequestResource;
use App\Services\Billing\PurchaseAdsConversionExport;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListManualPaymentRequests extends ListRecords
{
    protected static string $resource = ManualPaymentRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportGoogleAdsPurchases')
                ->label('Export purchases for Google Ads')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->tooltip('Approved InstaPay / Vodafone Cash payments and paid card plans from the last 90 days that came from a Google Ads click. Upload in Google Ads → Goals → Uploads.')
                ->action(fn () => response()->streamDownload(
                    fn () => print (app(PurchaseAdsConversionExport::class)->csv()),
                    'google-ads-purchases-'.now()->format('Y-m-d').'.csv',
                    ['Content-Type' => 'text/csv']
                )),
        ];
    }
}
