<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class ApiKeys extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'API Keys & Services';

    protected static string $view = 'filament.pages.api-keys';

    public array $keys = [];

    public function mount(): void
    {
        $this->keys = [
            'Google OAuth' => [
                'Client ID' => $this->mask(config('services.google.client_id')),
                'Client Secret' => $this->mask(config('services.google.client_secret')),
                'Redirect URI' => config('services.google.redirect'),
                'Status' => !empty(config('services.google.client_id')) ? '✅ Configured' : '❌ Not configured',
            ],
            'Speech TTS API' => [
                'API Key' => $this->mask(config('services.speech_tts.api_key')),
                'Status' => !empty(config('services.speech_tts.api_key')) ? '✅ Configured' : '❌ Not configured',
            ],
            'Stripe' => [
                'Publishable Key' => $this->mask(config('cashier.key')),
                'Secret Key' => $this->mask(config('cashier.secret')),
                'Webhook Secret' => $this->mask(config('cashier.webhook.secret')),
                'Status' => !empty(config('cashier.key')) ? '✅ Configured' : '❌ Not configured',
            ],
            'Mail' => [
                'Mailer' => config('mail.default'),
                'Host' => config('mail.mailers.smtp.host', 'N/A'),
                'Port' => config('mail.mailers.smtp.port', 'N/A'),
                'From' => config('mail.from.address', 'N/A'),
                'Status' => config('mail.default') !== 'log' ? '✅ Configured' : '⚠️ Using log driver',
            ],
        ];
    }

    protected function mask(?string $value): string
    {
        if (empty($value)) {
            return '(not set)';
        }

        $length = strlen($value);

        if ($length <= 8) {
            return str_repeat('•', $length);
        }

        return substr($value, 0, 4) . str_repeat('•', min(32, $length - 8)) . substr($value, -4);
    }
}
