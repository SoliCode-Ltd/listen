<?php

namespace App\Filament\Resources\NewsletterResource\Pages;

use App\Filament\Resources\NewsletterResource;
use App\Filament\Resources\NewsletterResource\Pages\Concerns\PreviewsNewsletter;
use App\Models\Newsletter;
use App\Services\Newsletter\NewsletterAudience;
use App\Services\Newsletter\NewsletterSender;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditNewsletter extends EditRecord
{
    use PreviewsNewsletter;

    protected static string $resource = NewsletterResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $state = is_array($this->data ?? null) ? $this->data : $this->form->getRawState();
        $state['preview_locale'] = $this->record->language ?: Newsletter::LANGUAGE_BOTH;
        $this->form->fill($state);
        $this->refreshPreview();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->refreshPreviewAction(),
            Actions\Action::make('countAudience')
                ->label('Count audience')
                ->icon('heroicon-o-users')
                ->color('gray')
                ->action(function (): void {
                    $count = app(NewsletterAudience::class)->count($this->draftFromForm());
                    Notification::make()
                        ->title($count === 1 ? '1 person matches' : $count.' people match')
                        ->body('No email was sent.')
                        ->success()
                        ->send();
                }),
            Actions\Action::make('sendExample')
                ->label('Send example to my email')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->modalHeading('Send an example')
                ->modalDescription('This emails only the address you type. It does not email the audience.')
                ->modalSubmitActionLabel('Send example only')
                ->form([
                    Forms\Components\TextInput::make('email')
                        ->label('Your email')
                        ->email()
                        ->required()
                        ->helperText('One address. The audience is not included.'),
                    Forms\Components\Select::make('language')
                        ->label('Language of this example')
                        ->options([
                            Newsletter::LANGUAGE_EN => 'English',
                            Newsletter::LANGUAGE_AR => 'Arabic',
                            Newsletter::LANGUAGE_BOTH => 'English and Arabic in one email',
                        ])
                        ->default(fn (): string => (string) ($this->record->language ?: Newsletter::LANGUAGE_BOTH))
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data): void {
                    if ($this->record->status === Newsletter::STATUS_SENDING) {
                        Notification::make()->title('A send is already running')->warning()->send();

                        return;
                    }

                    try {
                        $newsletter = $this->persistDraft();
                        app(NewsletterSender::class)->sendExample(
                            $newsletter,
                            (string) $data['email'],
                            (string) $data['language'],
                        );
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title('Example was not sent')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Example sent')
                        ->body('Only '.$data['email'].' was emailed.')
                        ->success()
                        ->send();

                    $this->redirect(NewsletterResource::getUrl('edit', ['record' => $this->record]));
                }),
            Actions\Action::make('sendToAudience')
                ->label('Send to audience')
                ->icon('heroicon-o-paper-airplane')
                ->color('danger')
                ->modalHeading('Send this newsletter')
                ->modalSubmitActionLabel('Send to audience')
                ->form(function (): array {
                    $count = app(NewsletterAudience::class)->count($this->draftFromForm());
                    $line = $count === 1
                        ? '1 person will receive this email.'
                        : $count.' people will receive this email.';

                    return [
                        Forms\Components\Placeholder::make('audience_count')
                            ->label('Audience')
                            ->content($line.' Nothing is sent until you confirm. Your example address is included only if that person is in the audience.'),
                        Forms\Components\Checkbox::make('confirm')
                            ->label('I checked the preview and I want to send it now')
                            ->accepted(),
                    ];
                })
                ->action(function (): void {
                    if ($this->record->status === Newsletter::STATUS_SENDING) {
                        Notification::make()->title('A send is already running')->warning()->send();

                        return;
                    }

                    try {
                        $newsletter = $this->persistDraft();
                        $count = app(NewsletterAudience::class)->count($newsletter);
                        if ($count === 0) {
                            Notification::make()
                                ->title('No one to email')
                                ->body('The audience is empty, so nothing was sent.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $result = app(NewsletterSender::class)->sendToAudience($newsletter, true);
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title('Newsletter was not sent')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Newsletter sent')
                        ->body('Sent '.$result['sent'].'. Failed '.$result['failed'].'.')
                        ->success()
                        ->send();

                    $this->redirect(NewsletterResource::getUrl('edit', ['record' => $this->record]));
                }),
            Actions\Action::make('unlockDraft')
                ->label('Unlock draft')
                ->icon('heroicon-o-lock-open')
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === Newsletter::STATUS_SENDING)
                ->requiresConfirmation()
                ->modalDescription('Use this only if a send was interrupted. It does not email anyone.')
                ->action(function (): void {
                    $this->record->update([
                        'status' => $this->record->sent_count > 0
                            ? Newsletter::STATUS_SENT
                            : Newsletter::STATUS_DRAFT,
                    ]);
                    Notification::make()->title('Newsletter unlocked')->success()->send();
                    $this->redirect(NewsletterResource::getUrl('edit', ['record' => $this->record]));
                }),
            Actions\DeleteAction::make()
                ->modalDescription('Deletes this newsletter and its send log. Mail that already went out stays sent.'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return NewsletterResource::cleanAttributes($data);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Draft saved. Nothing was sent.';
    }

    private function persistDraft(): Newsletter
    {
        $this->record->update(NewsletterResource::cleanAttributes($this->form->getState()));

        return $this->record->refresh();
    }

    private function draftFromForm(): Newsletter
    {
        $newsletter = new Newsletter;
        $state = is_array($this->data ?? null) ? $this->data : [];
        $newsletter->forceFill(NewsletterResource::cleanAttributes(array_merge(
            $this->record->attributesToArray(),
            $state,
        )));
        $newsletter->exists = true;
        $newsletter->id = $this->record->id;

        return $newsletter;
    }
}
