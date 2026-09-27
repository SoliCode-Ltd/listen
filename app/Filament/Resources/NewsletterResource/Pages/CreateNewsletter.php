<?php

namespace App\Filament\Resources\NewsletterResource\Pages;

use App\Filament\Resources\NewsletterResource;
use App\Filament\Resources\NewsletterResource\Pages\Concerns\PreviewsNewsletter;
use App\Models\Newsletter;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateNewsletter extends CreateRecord
{
    use PreviewsNewsletter;

    protected static string $resource = NewsletterResource::class;

    public function mount(): void
    {
        parent::mount();

        $this->form->fill(array_merge(Newsletter::defaultAttributes(), [
            'preview_locale' => Newsletter::LANGUAGE_BOTH,
        ]));
        $this->refreshPreview();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->refreshPreviewAction(),
        ];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return NewsletterResource::cleanAttributes($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function afterCreate(): void
    {
        Notification::make()
            ->title('Draft saved')
            ->body('Nothing was sent. Send an example to your own email, then send to the audience when you are ready.')
            ->success()
            ->send();
    }
}
