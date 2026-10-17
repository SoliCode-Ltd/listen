<?php

namespace App\Filament\Resources\SubscriptionResource\Pages;

use App\Filament\Resources\SubscriptionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSubscription extends CreateRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! isset($data['billing_payment_state']) || trim((string) $data['billing_payment_state']) === '') {
            $data['billing_payment_state'] = 'manual_override';
        }

        return SubscriptionResource::keepHandGivenPlan($data, (int) ($data['user_id'] ?? 0));
    }
}
