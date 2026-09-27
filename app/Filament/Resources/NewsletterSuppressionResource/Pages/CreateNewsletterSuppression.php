<?php

namespace App\Filament\Resources\NewsletterSuppressionResource\Pages;

use App\Filament\Resources\NewsletterSuppressionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNewsletterSuppression extends CreateRecord
{
    protected static string $resource = NewsletterSuppressionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['email'] = strtolower(trim((string) ($data['email'] ?? '')));
        $data['unsubscribed_at'] = now();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
