<?php

namespace App\Filament\Resources\PaymentResource\Pages;

use App\Filament\Resources\PaymentResource;
use App\Services\Billing\PaymentLedger;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importPayments')
                ->label('Fetch past payments')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->tooltip('Adds the payments made before this list began: every paid card, Apple Pay and Google Pay charge of our members in Stripe, and every approved InstaPay / Vodafone Cash payment. New payments are added by themselves; running it again adds nothing twice.')
                ->action(function (): void {
                    try {
                        $added = app(PaymentLedger::class)->importAll();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Stripe could not be read')->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }
                    Notification::make()
                        ->title(($added['stripe'] + $added['manual']).' payments added')
                        ->body($added['stripe'].' from Stripe, '.$added['manual'].' paid by hand.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
