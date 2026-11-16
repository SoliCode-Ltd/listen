<?php

namespace App\Filament\Resources\UsageCounterResource\Pages;

use App\Filament\Resources\AudioGenerationResource;
use App\Filament\Resources\SubscriptionResource;
use App\Filament\Resources\UsageCounterResource;
use App\Filament\Resources\UserResource;
use App\Models\Subscription;
use Filament\Actions\Action;
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
            Action::make('openMember')
                ->label('Open member')
                ->icon('heroicon-o-user')
                ->color('gray')
                ->visible(fn (): bool => $this->record->user !== null)
                ->url(fn (): string => UserResource::getUrl('edit', ['record' => $this->record->user])),
            Action::make('openGenerations')
                ->label('Their generations')
                ->icon('heroicon-o-musical-note')
                ->color('gray')
                ->visible(fn (): bool => filled($this->record->user?->email))
                ->url(fn (): string => AudioGenerationResource::getUrl('index', ['tableSearch' => $this->record->user->email])),
            Action::make('giftChars')
                ->label('Gift characters')
                ->icon('heroicon-o-gift')
                ->color('success')
                ->visible(fn (): bool => $this->record->isCurrentMonth() && $subscription() !== null)
                ->modalHeading(fn (): string => 'Gift characters to '.($this->record->user?->email ?? 'this member'))
                ->modalDescription(fn (): string => SubscriptionResource::giftModalDescription($subscription()))
                ->schema(SubscriptionResource::giftFormSchema())
                ->action(function (array $data) use ($subscription): void {
                    SubscriptionResource::grantGift($subscription(), $data);
                    $this->record->refresh();
                }),
        ];
    }
}
