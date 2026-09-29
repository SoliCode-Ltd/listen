<?php

namespace App\Filament\Resources\BillingTrialResource\Pages;

use App\Filament\Resources\BillingTrialResource;
use App\Filament\Widgets\TrialOutcomeStats;
use App\Services\Billing\TrialAdsConversionExport;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListBillingTrials extends ListRecords
{
    protected static string $resource = BillingTrialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportGoogleAds')
                ->label('Export paid trials for Google Ads')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->tooltip('Paid trials from the last 90 days that came from a Google Ads click. Upload in Google Ads → Goals → Uploads.')
                ->action(fn () => response()->streamDownload(
                    fn () => print(app(TrialAdsConversionExport::class)->csv()),
                    'google-ads-trial-conversions-'.now()->format('Y-m-d').'.csv',
                    ['Content-Type' => 'text/csv']
                )),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            TrialOutcomeStats::class,
        ];
    }
}
