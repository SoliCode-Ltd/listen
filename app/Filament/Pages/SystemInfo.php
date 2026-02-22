<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class SystemInfo extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'System Info';

    protected static string $view = 'filament.pages.system-info';

    public array $systemInfo = [];

    public function mount(): void
    {
        $this->systemInfo = [
            'environment' => [
                'PHP Version' => phpversion(),
                'Laravel Version' => app()->version(),
                'Environment' => app()->environment(),
                'Debug Mode' => config('app.debug') ? 'Enabled' : 'Disabled',
                'App URL' => config('app.url'),
                'Timezone' => config('app.timezone'),
            ],
            'database' => [
                'Driver' => config('database.default'),
                'Database' => config('database.connections.' . config('database.default') . '.database'),
                'Host' => config('database.connections.' . config('database.default') . '.host', 'N/A'),
            ],
            'infrastructure' => [
                'Queue Connection' => config('queue.default'),
                'Cache Driver' => config('cache.default'),
                'Session Driver' => config('session.driver'),
                'Mail Driver' => config('mail.default'),
            ],
            'listen_config' => [
                'Audio Processing' => config('listen.audio.processing'),
                'Force Async Chars' => config('listen.audio.force_async_chars'),
                'Default Voice Tier' => config('listen.voice.default_tier'),
                'Billing Grace Days' => config('listen.billing.grace_days'),
                'Subscription Sync TTL (min)' => config('listen.billing.subscription_sync_ttl_minutes'),
                'Demo Guest Char Allowance' => config('listen.demo.guest_char_allowance'),
                'Demo User Char Allowance' => config('listen.demo.user_char_allowance'),
            ],
            'disk' => [
                'Storage Path' => storage_path(),
                'Storage Writable' => is_writable(storage_path()) ? 'Yes' : 'No',
            ],
        ];
    }
}
