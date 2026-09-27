<?php

namespace App\Filament\Resources\NewsletterResource\Pages\Concerns;

use App\Filament\Resources\NewsletterResource;
use App\Models\Newsletter;
use App\Services\Newsletter\NewsletterRenderer;
use Filament\Actions\Action;

trait PreviewsNewsletter
{
    public string $previewHtml = '';

    public function refreshPreview(): void
    {
        $data = is_array($this->data ?? null) ? $this->data : $this->form->getRawState();
        $base = [];
        if (property_exists($this, 'record') && $this->record instanceof Newsletter) {
            $base = $this->record->attributesToArray();
        }

        $newsletter = new Newsletter;
        $newsletter->forceFill(NewsletterResource::cleanAttributes(array_merge($base, $data)));

        $locale = (string) ($data['preview_locale'] ?? $newsletter->language ?? Newsletter::LANGUAGE_BOTH);
        $this->previewHtml = app(NewsletterRenderer::class)->html($newsletter, $locale, [
            'preview' => true,
            'name_en' => 'Alex',
            'name_ar' => 'أحمد',
            'email' => 'preview@example.com',
        ], true);
    }

    protected function refreshPreviewAction(): Action
    {
        return Action::make('refreshPreview')
            ->label('Refresh preview')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->action(fn () => $this->refreshPreview());
    }
}
