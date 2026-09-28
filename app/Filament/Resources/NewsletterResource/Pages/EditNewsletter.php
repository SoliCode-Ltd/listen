<?php

namespace App\Filament\Resources\NewsletterResource\Pages;

use App\Filament\Resources\NewsletterResource;
use App\Filament\Resources\NewsletterResource\Pages\Concerns\PreviewsNewsletter;
use App\Models\Newsletter;
use App\Services\Newsletter\NewsletterAudience;
use App\Services\Newsletter\NewsletterPace;
use App\Services\Newsletter\NewsletterSender;
use App\Support\TitanMailbox;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\HtmlString;

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
                            ->body(TitanMailbox::explainFailure($exception->getMessage()))
                            ->danger()
                            ->persistent()
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
                ->label('Send next batch')
                ->icon('heroicon-o-paper-airplane')
                ->color('danger')
                ->modalHeading('Send the next batch')
                ->modalSubmitActionLabel('Send next batch')
                ->form(function (): array {
                    $draft = $this->draftFromForm();
                    $progress = app(NewsletterSender::class)->progress($this->record);
                    $waiting = app(NewsletterAudience::class)->waitingCount($draft);
                    $pace = app(NewsletterPace::class);
                    $hold = $pace->holdReason();
                    $click = $pace->perClick();

                    $summary = 'Already sent: '.$progress['sent'].'.'
                        .' Failed, still waiting: '.$progress['failed'].'.'
                        .' Queued: '.$progress['queued'].'.'
                        .' Still waiting: '.$waiting.'.'
                        .' Mailbox today: '.$pace->sentToday().' of '.$pace->dailyLimit().'.';

                    if ($hold !== null) {
                        $summary .= ' Paused: '.$hold;
                    }

                    return [
                        Forms\Components\Placeholder::make('audience_count')
                            ->label('This newsletter')
                            ->content(new HtmlString(e($summary).'<br><br>This click sends up to '.$click.' people before the page finishes, the same way an example email is sent. People who already received this newsletter are skipped. If more are queued or still waiting, press send again for the next '.$click.'. Nothing is sent until you confirm.')),
                        Forms\Components\TextInput::make('batch_size')
                            ->label('How many to send this time')
                            ->numeric()
                            ->required()
                            ->default($click)
                            ->minValue(1)
                            ->maxValue($click)
                            ->helperText('Maximum '.$click.' per click so the page can finish. Titan still allows about '.$pace->dailyLimit().' a day from this mailbox. The billing scheduler is not used.'),
                        Forms\Components\Checkbox::make('confirm')
                            ->label('I checked the preview and I want to send these now')
                            ->accepted(),
                    ];
                })
                ->action(function (array $data): void {
                    try {
                        $newsletter = $this->persistDraft();
                        $result = app(NewsletterSender::class)->sendNow(
                            $newsletter,
                            (int) ($data['batch_size'] ?? app(NewsletterPace::class)->perClick()),
                            true,
                        );
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title('Emails were not sent')
                            ->body(TitanMailbox::explainFailure($exception->getMessage()))
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    if ($result['sent'] === 0 && $result['queued'] === 0) {
                        Notification::make()
                            ->title('No one to email')
                            ->body($result['hold'] !== null
                                ? $result['hold']
                                : 'Everyone in this audience has already received this newsletter, or the list is empty.')
                            ->warning()
                            ->persistent()
                            ->send();

                        return;
                    }

                    $body = 'Sent '.$result['sent'].'. Failed '.$result['failed'].'. Still queued: '.$result['queued'].'. Still waiting: '.$result['waiting'].'.';
                    if ($result['queued'] > 0) {
                        $body .= ' Press send again for the next '.app(NewsletterPace::class)->perClick().'.';
                    }
                    if ($result['hold'] !== null) {
                        $body .= ' Paused: '.$result['hold'];
                    }

                    $notification = Notification::make()
                        ->title($result['sent'] > 0 ? 'Sent '.$result['sent'] : 'Nothing was sent')
                        ->body($body)
                        ->persistent();
                    if ($result['sent'] > 0) {
                        $notification->success();
                    } else {
                        $notification->danger();
                    }
                    $notification->send();

                    $this->redirect(NewsletterResource::getUrl('edit', ['record' => $this->record]));
                }),
            Actions\Action::make('resumeSending')
                ->label('Resume sending')
                ->icon('heroicon-o-play')
                ->color('warning')
                ->visible(fn (): bool => app(NewsletterPace::class)->holdReason() !== null)
                ->requiresConfirmation()
                ->modalDescription(fn (): string => (string) app(NewsletterPace::class)->holdReason())
                ->modalSubmitActionLabel('Resume sending')
                ->action(function (): void {
                    app(NewsletterPace::class)->clearHold();
                    Notification::make()
                        ->title('Sending resumed')
                        ->body('Press Send next batch. The next '.app(NewsletterPace::class)->perClick().' go out before the page finishes.')
                        ->success()
                        ->send();
                }),
            Actions\Action::make('unlockDraft')
                ->label('Stop this batch')
                ->icon('heroicon-o-lock-open')
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === Newsletter::STATUS_SENDING)
                ->requiresConfirmation()
                ->modalHeading('Stop this batch')
                ->modalDescription('People who already received this newsletter stay sent. Everyone still queued goes back to waiting. Nothing new is emailed.')
                ->modalSubmitActionLabel('Stop this batch')
                ->action(function (): void {
                    $stopped = app(NewsletterSender::class)->cancelQueued($this->record);
                    Notification::make()
                        ->title('Batch stopped')
                        ->body($stopped.' queued '.($stopped === 1 ? 'address was' : 'addresses were').' returned to waiting.')
                        ->success()
                        ->send();
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
