<?php

namespace App\Filament\Resources\SubscriptionResource\Pages;

use App\Filament\Resources\SubscriptionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSubscription extends EditRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $originalPlanId = (int) ($this->record->plan_id ?? 0);
        $nextPlanId = (int) ($data['plan_id'] ?? 0);

        if ($nextPlanId > 0 && $nextPlanId !== $originalPlanId) {
            $data['billing_payment_state'] = 'manual_override';
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
