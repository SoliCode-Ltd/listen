<?php

namespace App\Filament\Resources\PlanResource\Pages;

use App\Filament\Resources\PlanResource;
use App\Models\Plan;
use Filament\Resources\Pages\CreateRecord;

class CreatePlan extends CreateRecord
{
    protected static string $resource = PlanResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $rawState = $this->form->getRawState();

        foreach (['features_en', 'features_ar'] as $field) {
            $data[$field] = PlanResource::normalizeRepeaterItems($data[$field] ?? []);
        }

        $priceDollars = (float) ($rawState['price_dollars'] ?? 0);
        $data['price_cents'] = (int) round($priceDollars * 100);

        if (! empty($rawState['overage_price_dollars'])) {
            $data['overage_price_per_million_cents'] = (int) round(((float) $rawState['overage_price_dollars']) * 100);
        }

        if (! empty($rawState['topup_char_price_dollars'])) {
            $data['topup_char_price_cents'] = (int) round(((float) $rawState['topup_char_price_dollars']) * 100);
        }

        if (! empty($rawState['topup_minute_price_dollars'])) {
            $data['topup_minute_price_cents'] = (int) round(((float) $rawState['topup_minute_price_dollars']) * 100);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        if (! $this->record || ! (bool) $this->record->is_featured) {
            return;
        }

        Plan::query()
            ->whereKeyNot($this->record->getKey())
            ->update(['is_featured' => false]);
    }
}
