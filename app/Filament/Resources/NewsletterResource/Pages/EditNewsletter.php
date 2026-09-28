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
                    $next = min($pace->dailyLimit(), $pace->dailyRemaining(), $waiting);

                    $summary = 'Already sent: '.$progress['sent'].'.'
                        .' Failed, still waiting: '.$progress['failed'].'.'
                        .' In the current batch: '.$progress['queued'].'.'
                        .' Still waiting: '.$waiting.'.'
                        .' Mailbox today: '.$pace->sentToday().' of '.$pace->dailyLimit().'.';

                    if ($hold !== null) {
                        $summary .= ' Paused: '.$hold;
                    }

                    return [
                        Forms\Components\Placeholder::make('audience_count')
                            ->label('This newsletter')
                            ->content(new HtmlString(e($summary).'<br><br>The next click continues with people who have not received this newsletter. Failed addresses are tried again. Messages leave this page slowly, about '.$pace->hourlyLimit().' per hour, so a full batch keeps going after you close the admin. Nothing is sent until you confirm.')),
                        Forms\Components\TextInput::make('batch_size')
                            ->label('How many to send this time')
                            ->numeric()
                            ->required()
                            ->default($next > 0 ? $next : $pace->dailyLimit())
                            ->minValue(1)
                            ->maxValue($pace->dailyLimit())
                            ->helperText('Titan allows about '.$pace->dailyLimit().' messages a day from this mailbox, shared by every newsletter. This click lines up at most that many, then the next click starts where this one stopped.'),
                        Forms\Components\Checkbox::make('confirm')
                            ->label('I checked the preview and I want to send this batch now')
                            ->accepted(),
                    ];
                })
                ->action(function (array $data): void {
                    try {
                        $newsletter = $this->persistDraft();
                        $result = app(NewsletterSender::class)->queueNextBatch(
                            $newsletter,
                            (int) ($data['batch_size'] ?? app(NewsletterPace::class)->dailyLimit()),
                            true,
                        );
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title('Batch was not started')
                            ->body(TitanMailbox::explainFailure($exception->getMessage()))
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    if ($result['queued'] === 0) {
                        Notification::make()
                            ->title('No one to email')
                            ->body('Everyone in this audience has already received this newsletter, or the list is empty.')
                            ->warning()
                            ->send();

                        return;
                    }

                    if (app()->environment('testing')) {
                        $sent = app(NewsletterSender::class)->drain(app(NewsletterPace::class)->dailyLimit(), false);
                        Notification::make()
                            ->title('Newsletter sent')
                            ->body('Sent '.$sent.'. Still waiting: '.$result['waiting'].'.')
                            ->success()
                            ->send();
                        $this->redirect(NewsletterResource::getUrl('edit', ['record' => $this->record]));

                        return;
                    }

                    $hold = app(NewsletterPace::class)->holdReason();
                    Notification::make()
                        ->title($result['queued'].' lined up')
                        ->body('Already sent: '.$result['sent'].'. Still waiting after this batch: '.$result['waiting'].'. They go out at about '.app(NewsletterPace::class)->hourlyLimit().' per hour. You can leave this page. The next click continues with whoever is still waiting.'.($hold !== null ? ' Paused: '.$hold : ''))
                        ->success()
                        ->persistent()
                        ->send();

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
                        ->body('Queued messages continue on the next minute.')
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
