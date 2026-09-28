<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use App\Support\TitanMailbox;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Mail;

class NewsletterMailbox extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-inbox';

    protected static ?string $navigationGroup = 'Newsletter';

    protected static ?string $navigationLabel = 'Mailbox';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Titan mailbox';

    protected static string $view = 'filament.pages.newsletter-mailbox';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'host' => TitanMailbox::host(),
            'port' => TitanMailbox::port(),
            'username' => TitanMailbox::username(),
            'from_name' => TitanMailbox::fromName(),
            'password' => '',
            'password_saved' => TitanMailbox::hasPassword(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Titan Email')
                    ->description('Newsletters send through the Titan mailbox for listenai.studio. Use the password for help@listenai.studio. Before the first send, sign in at https://app.titan.email/mail/ as that mailbox, open Settings, and turn on Enable Titan on other apps. Two-factor authentication on the mailbox blocks SMTP even when the password is correct.')
                    ->schema([
                        Forms\Components\TextInput::make('host')
                            ->label('SMTP host')
                            ->required()
                            ->default(TitanMailbox::HOST),
                        Forms\Components\TextInput::make('port')
                            ->label('SMTP port')
                            ->numeric()
                            ->required()
                            ->default(TitanMailbox::PORT),
                        Forms\Components\TextInput::make('username')
                            ->label('Mailbox')
                            ->email()
                            ->required()
                            ->default(TitanMailbox::ADDRESS)
                            ->helperText('Titan only lets this address appear in the From line.'),
                        Forms\Components\TextInput::make('from_name')
                            ->label('From name')
                            ->required()
                            ->default('Listen'),
                        Forms\Components\TextInput::make('password')
                            ->label('Mailbox password')
                            ->password()
                            ->revealable()
                            ->helperText(fn (): string => TitanMailbox::hasPassword()
                                ? 'A password is already saved. Leave this blank to keep it, or paste a new one to replace it.'
                                : 'Paste the Titan password for help@listenai.studio. Nothing can be sent until this is saved.'),
                        Forms\Components\Placeholder::make('password_saved')
                            ->label('Status')
                            ->content(fn (): string => TitanMailbox::hasPassword()
                                ? 'Ready to send from '.TitanMailbox::username().'.'
                                : 'Waiting for the Titan password.'),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendMailboxTest')
                ->label('Send a mailbox test')
                ->icon('heroicon-o-paper-airplane')
                ->modalDescription('Sends one plain message through Titan. It does not email your users.')
                ->modalSubmitActionLabel('Send test')
                ->form([
                    Forms\Components\TextInput::make('email')
                        ->label('Send the test to')
                        ->email()
                        ->required()
                        ->default(TitanMailbox::ADDRESS),
                ])
                ->action(function (array $data): void {
                    $this->saveSettings();

                    try {
                        TitanMailbox::apply();
                        TitanMailbox::requirePassword();
                        Mail::mailer('titan')->raw(
                            'This is a Listen mailbox test from '.TitanMailbox::username().'.',
                            function ($message) use ($data): void {
                                $message->to($data['email'])
                                    ->from(TitanMailbox::username(), TitanMailbox::fromName())
                                    ->subject('Listen mailbox test');
                            }
                        );
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title('Mailbox test was not sent')
                            ->body(TitanMailbox::explainFailure($exception->getMessage()))
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Mailbox test sent')
                        ->body('Check '.$data['email'].'.')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function save(): void
    {
        $this->saveSettings();

        Notification::make()
            ->title('Mailbox saved')
            ->body(TitanMailbox::hasPassword()
                ? 'Newsletters will send from '.TitanMailbox::username().'.'
                : 'Add the Titan password before sending.')
            ->success()
            ->send();

        $this->mount();
    }

    private function saveSettings(): void
    {
        $data = $this->form->getState();

        SiteSetting::set('titan_mail_host', (string) $data['host'], 'mail');
        SiteSetting::set('titan_mail_port', (string) $data['port'], 'mail');
        SiteSetting::set('titan_mail_username', strtolower(trim((string) $data['username'])), 'mail');
        SiteSetting::set('titan_mail_from_name', (string) $data['from_name'], 'mail');

        $password = trim((string) ($data['password'] ?? ''));
        if ($password !== '') {
            TitanMailbox::storePassword($password);
        }

        SiteSetting::flushGroupCache('mail');
    }
}
