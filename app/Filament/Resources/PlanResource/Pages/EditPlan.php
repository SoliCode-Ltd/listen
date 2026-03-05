<?php

namespace App\Filament\Resources\PlanResource\Pages;

use App\Filament\Resources\PlanResource;
use App\Models\Plan;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Read the virtual dollar fields from the raw form input
        $rawState = $this->form->getRawState();

        // Convert price_dollars → price_cents
        $priceDollars = (float) ($rawState['price_dollars'] ?? 0);
        $data['price_cents'] = (int) round($priceDollars * 100);

        // Convert overage_price_dollars → overage_price_per_million_cents
        if (!empty($rawState['overage_price_dollars'])) {
            $data['overage_price_per_million_cents'] = (int) round(((float) $rawState['overage_price_dollars']) * 100);
        } elseif (empty($data['allow_overage'])) {
            $data['overage_price_per_million_cents'] = null;
        }

        // Convert topup_char_price_dollars → topup_char_price_cents
        if (!empty($rawState['topup_char_price_dollars'])) {
            $data['topup_char_price_cents'] = (int) round(((float) $rawState['topup_char_price_dollars']) * 100);
        } else {
            $data['topup_char_price_cents'] = null;
        }

        // Convert topup_minute_price_dollars → topup_minute_price_cents
        if (!empty($rawState['topup_minute_price_dollars'])) {
            $data['topup_minute_price_cents'] = (int) round(((float) $rawState['topup_minute_price_dollars']) * 100);
        } else {
            $data['topup_minute_price_cents'] = null;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        if (! $this->record || ! (bool) $this->record->is_featured) {
            return;
        }

        Plan::query()
            ->whereKeyNot($this->record->getKey())
            ->update(['is_featured' => false]);
    }
}
