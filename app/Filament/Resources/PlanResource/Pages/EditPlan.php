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
        $rawState = $this->form->getRawState();

        foreach (['features_en', 'features_ar'] as $field) {
            $data[$field] = PlanResource::normalizeRepeaterItems($data[$field] ?? []);
        }

        $priceDollars = (float) ($rawState['price_dollars'] ?? 0);
        $data['price_cents'] = (int) round($priceDollars * 100);

        if (! empty($rawState['overage_price_dollars'])) {
            $data['overage_price_per_million_cents'] = (int) round(((float) $rawState['overage_price_dollars']) * 100);
        } elseif (empty($data['allow_overage'])) {
            $data['overage_price_per_million_cents'] = null;
        }

        if (! empty($rawState['topup_char_price_dollars'])) {
            $data['topup_char_price_cents'] = (int) round(((float) $rawState['topup_char_price_dollars']) * 100);
        } else {
            $data['topup_char_price_cents'] = null;
        }

        if (! empty($rawState['topup_minute_price_dollars'])) {
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
