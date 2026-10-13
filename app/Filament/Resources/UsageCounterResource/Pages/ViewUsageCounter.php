<?php

namespace App\Filament\Resources\UsageCounterResource\Pages;

use App\Filament\Resources\SubscriptionResource;
use App\Filament\Resources\UsageCounterResource;
use App\Filament\Resources\UserResource;
use App\Models\Subscription;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewUsageCounter extends ViewRecord
{
    protected static string $resource = UsageCounterResource::class;

    public function getTitle(): string
    {
        return ($this->record->user?->email ?? 'Deleted account').' · '.$this->record->monthLabel();
    }

    protected function getHeaderActions(): array
    {
        $subscription = fn (): ?Subscription => Subscription::query()->where('user_id', $this->record->user_id)->latest('id')->first();

        return [
            Actions\Action::make('openMember')
                ->label('Open member')
                ->icon('heroicon-o-user')
                ->color('gray')
                ->visible(fn (): bool => $this->record->user !== null)
                ->url(fn (): string => UserResource::getUrl('edit', ['record' => $this->record->user])),
            Actions\Action::make('giftChars')
                ->label('Gift characters')
                ->icon('heroicon-o-gift')
                ->color('success')
                ->visible(fn (): bool => $this->record->isCurrentMonth() && $subscription() !== null)
                ->modalHeading(fn (): string => 'Gift characters to '.($this->record->user?->email ?? 'this member'))
                ->modalDescription(fn (): string => SubscriptionResource::giftModalDescription($subscription()))
                ->form(SubscriptionResource::giftFormSchema())
                ->action(function (array $data) use ($subscription): void {
                    SubscriptionResource::grantGift($subscription(), $data);
                    $this->record->refresh();
                }),
        ];
    }
}
