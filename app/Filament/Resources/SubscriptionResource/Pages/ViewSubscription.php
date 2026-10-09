<?php

namespace App\Filament\Resources\SubscriptionResource\Pages;

use App\Filament\Resources\SubscriptionResource;
use App\Models\CharGift;
use App\Models\Subscription;
use App\Services\Billing\CharGiftService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewSubscription extends ViewRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('giftChars')
                ->label('Gift characters')
                ->icon('heroicon-o-gift')
                ->color('success')
                ->modalHeading(fn (Subscription $record): string => 'Gift characters to '.($record->user?->email ?? 'this member'))
                ->modalDescription(fn (Subscription $record): string => SubscriptionResource::giftModalDescription($record))
                ->form(SubscriptionResource::giftFormSchema())
                ->action(fn (Subscription $record, array $data) => SubscriptionResource::grantGift($record, $data)),
            Actions\Action::make('revokeGift')
                ->label('Take back a gift')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (Subscription $record): bool => $this->revokableGifts($record) !== [])
                ->form(fn (Subscription $record): array => [
                    Forms\Components\Select::make('gift_id')
                        ->label('Gift to take back')
                        ->options($this->revokableGifts($record))
                        ->required(),
                ])
                ->requiresConfirmation()
                ->modalDescription('The characters come off this month\'s allowance. Characters the member already used stay used.')
                ->action(function (Subscription $record, array $data): void {
                    $gift = CharGift::query()->where('user_id', $record->user_id)->find((int) $data['gift_id']);
                    if ($gift) {
                        app(CharGiftService::class)->revoke($gift, auth()->user());
                        Notification::make()->title(number_format($gift->chars).' gift characters taken back')->success()->send();
                    }
                }),
            Actions\EditAction::make(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function revokableGifts(Subscription $record): array
    {
        return rescue(fn () => CharGift::query()
            ->where('user_id', $record->user_id)
            ->where('period_ym', now()->format('Y-m'))
            ->whereNull('revoked_at')
            ->latest('id')
            ->get()
            ->mapWithKeys(fn (CharGift $gift): array => [
                $gift->id => '+'.number_format($gift->chars).' on '.$gift->created_at?->format('Y-m-d H:i').($gift->note ? ' — '.$gift->note : ''),
            ])
            ->all(), [], false);
    }
}
