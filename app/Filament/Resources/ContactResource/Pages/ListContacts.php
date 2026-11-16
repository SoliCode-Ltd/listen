<?php

namespace App\Filament\Resources\ContactResource\Pages;

use App\Filament\Resources\ContactResource;
use App\Models\SiteSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;

class ListContacts extends ListRecords
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('contactSettings')
                ->label('Contact Settings')
                ->icon('heroicon-o-cog-6-tooth')
                ->modalHeading('Contact Settings')
                ->modalWidth('3xl')
                ->fillForm(fn (): array => [
                    'contact_form_enabled' => (bool) SiteSetting::get('contact_form_enabled', true),
                    'contact_submission_email' => (string) SiteSetting::get(
                        'contact_submission_email',
                        SiteSetting::get('contact_email', config('mail.from.address', ''))
                    ),
                    'contact_submission_name' => (string) SiteSetting::get(
                        'contact_submission_name',
                        config('app.name', 'Listen').' Contact'
                    ),
                ])
                ->schema([
                    Tabs::make('contact_settings_tabs')
                        ->tabs([
                            Tab::make('Controls')
                                ->icon('heroicon-o-adjustments-horizontal')
                                ->schema([
                                    Toggle::make('contact_form_enabled')
                                        ->label('Enable Contact Form')
                                        ->helperText('Turn this off to disable the contact form on the frontend.'),
                                ]),
                            Tab::make('Email Delivery')
                                ->icon('heroicon-o-envelope')
                                ->schema([
                                    TextInput::make('contact_submission_email')
                                        ->label('Submission Recipient Email')
                                        ->email()
                                        ->maxLength(255)
                                        ->helperText('All new contact submissions will be sent to this email address.'),
                                    TextInput::make('contact_submission_name')
                                        ->label('Recipient Name')
                                        ->maxLength(255)
                                        ->helperText('Optional display name used for delivered contact emails.'),
                                ]),
                        ]),
                ])
                ->action(function (array $data): void {
                    SiteSetting::set(
                        'contact_form_enabled',
                        ! empty($data['contact_form_enabled']),
                        'contact'
                    );
                    SiteSetting::set(
                        'contact_submission_email',
                        trim((string) ($data['contact_submission_email'] ?? '')),
                        'contact'
                    );
                    SiteSetting::set(
                        'contact_submission_name',
                        trim((string) ($data['contact_submission_name'] ?? '')),
                        'contact'
                    );

                    Notification::make()
                        ->title('Contact settings updated')
                        ->success()
                        ->send();
                }),
        ];
    }
}
