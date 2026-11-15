<?php

namespace App\Filament\Resources\PurchaseFollowUpResource\Pages;

use App\Filament\Concerns\SimplePages;
use App\Filament\Resources\PurchaseFollowUpResource;
use App\Models\PurchaseFollowUp;
use App\Services\Billing\PurchaseFollowUps;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseFollowUps extends ListRecords
{
    use SimplePages;

    protected static string $resource = PurchaseFollowUpResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('emails')
                ->label('Emails and settings')
                ->icon('heroicon-o-envelope')
                ->url(PurchaseFollowUpResource::getUrl('emails')),
        ];
    }

    /** The last 30 days in one line, and whether the emails are on. */
    public function getSubheading(): ?string
    {
        $since = now()->subDays(30);
        $count = fn (string $kind, ?string $status = null, bool $paid = false): int => PurchaseFollowUp::query()
            ->where('kind', $kind)
            ->where('created_at', '>=', $since)
            ->when($status, fn ($q) => $q->where('email_status', $status))
            ->when($paid, fn ($q) => $q->whereNotNull('paid_at'))
            ->count();
        $followUps = app(PurchaseFollowUps::class);
        $state = fn (string $kind): string => $followUps->enabled($kind) ? 'on' : 'OFF';
        $failed = PurchaseFollowUp::KIND_PAYMENT_FAILED;
        $left = PurchaseFollowUp::KIND_CHECKOUT_LEFT;
        $renewal = PurchaseFollowUp::KIND_RENEWAL_FAILED;

        return 'Last 30 days. Renewals declined: '.$count($renewal).' ('.$count($renewal, PurchaseFollowUp::SENT).' emailed, '.$count($renewal, null, true).' paid after). '
            .'First payments declined: '.$count($failed).' ('.$count($failed, PurchaseFollowUp::SENT).' emailed, '.$count($failed, null, true).' bought after; email '.$state($failed).'). '
            .'Checkouts left: '.$count($left).' ('.$count($left, PurchaseFollowUp::SENT).' emailed, '.$count($left, null, true).' bought after; email '.$state($left).').';
    }
}
