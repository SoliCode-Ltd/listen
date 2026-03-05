<?php

namespace App\Filament\Pages;

use App\Models\Plan;
use App\Models\SiteSetting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class TtsSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-speaker-wave';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'TTS Settings';

    protected static string $view = 'filament.pages.tts-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $plans = Plan::where('is_active', true)->pluck('name', 'slug')->toArray();
        $planOverrides = [];
        foreach ($plans as $slug => $name) {
            $planOverrides[$slug] = SiteSetting::get("tts_plan_provider_{$slug}", '');
        }

        $this->form->fill([
            // Global mode
            'tts_mode' => SiteSetting::get('tts_mode', config('listen.tts.mode', 'google')),
            'tts_fallback_enabled' => (string) SiteSetting::get('tts_fallback_enabled', '1') === '1',

            // Gemini config
            'gemini_api_key_set' => count(app(\App\Services\Tts\GeminiKeyManager::class)->getAllKeys()) > 0,
            'gemini_default_voice' => SiteSetting::get('gemini_default_voice', config('services.gemini_tts.default_voice', 'Kore')),
            'gemini_timeout_seconds' => (int) SiteSetting::get('gemini_timeout_seconds', config('listen.tts.gemini_timeout_seconds', 30)),

            // Per-plan overrides
            'plan_overrides' => $planOverrides,
        ]);
    }

    public function form(Form $form): Form
    {
        $plans = Plan::where('is_active', true)->pluck('name', 'slug')->toArray();

        return $form
            ->schema([
                Forms\Components\Section::make('Global TTS Mode')
                    ->description('Controls which TTS engine is used for audio generation across the platform.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('tts_mode')
                            ->label('TTS Routing Mode')
                            ->options([
                                'google' => 'Google Cloud TTS (WaveNet & Chirp)',
                                'hybrid' => 'Hybrid (Free → Google WaveNet, Paid → Gemini)',
                            ])
                            ->required()
                            ->helperText('Google: all users get Google Cloud TTS (WaveNet + Chirp). Hybrid: free plans get Google WaveNet, paid plans get Gemini 2.5 Flash TTS with automatic fallback.')
                            ->reactive(),
                        Forms\Components\Toggle::make('tts_fallback_enabled')
                            ->label('Enable Fallback')
                            ->helperText('If Gemini fails, automatically fall back to Google Cloud TTS.')
                            ->default(true)
                            ->visible(fn (Forms\Get $get): bool => $get('tts_mode') === 'hybrid'),
                    ]),

                Forms\Components\Section::make('Gemini TTS Configuration')
                    ->description('Settings for the Gemini 2.5 Flash Preview TTS provider.')
                    ->columns(2)
                    ->visible(fn (Forms\Get $get): bool => $get('tts_mode') === 'hybrid')
                    ->schema([
                        Forms\Components\Placeholder::make('gemini_api_key_status')
                            ->label('Gemini API Key')
                            ->content(fn (Forms\Get $get): string => $get('gemini_api_key_set')
                                ? '✅ Configured (set via GEMINI_TTS_API_KEY env variable)'
                                : '❌ Not configured — set GEMINI_TTS_API_KEY in your .env file'),
                        Forms\Components\Select::make('gemini_default_voice')
                            ->label('Default Gemini Voice')
                            ->options([
                                'Zephyr' => 'Zephyr (Bright)',
                                'Puck' => 'Puck (Upbeat)',
                                'Charon' => 'Charon (Informative)',
                                'Kore' => 'Kore (Firm)',
                                'Fenrir' => 'Fenrir (Excitable)',
                                'Leda' => 'Leda (Youthful)',
                                'Orus' => 'Orus (Firm)',
                                'Aoede' => 'Aoede (Breezy)',
                                'Callirrhoe' => 'Callirrhoe (Easy-going)',
                                'Autonoe' => 'Autonoe (Bright)',
                            ])
                            ->required()
                            ->helperText('Used when no specific voice is requested by the user.'),
                        Forms\Components\TextInput::make('gemini_timeout_seconds')
                            ->label('Gemini Timeout (seconds)')
                            ->numeric()
                            ->minValue(5)
                            ->maxValue(120)
                            ->default(30)
                            ->helperText('Maximum time to wait for Gemini API response before falling back.'),
                    ]),

                Forms\Components\Section::make('Per-Plan Provider Overrides')
                    ->description('Override the TTS provider for specific plans. Leave empty to use the global mode.')
                    ->visible(fn (Forms\Get $get): bool => $get('tts_mode') === 'hybrid')
                    ->schema(
                        collect($plans)->map(fn (string $name, string $slug) => Forms\Components\Select::make("plan_overrides.{$slug}")
                            ->label("{$name} Plan")
                            ->options([
                                '' => 'Use Global Mode',
                                'google_cloud' => 'Force Google Cloud TTS',
                                'gemini' => 'Force Gemini TTS',
                            ])
                            ->default('')
                            ->helperText("Override provider for the {$name} plan.")
                        )->values()->toArray()
                    ),

                Forms\Components\Section::make('API Keys Status')
                    ->description('Manage API keys in the dedicated Gemini API Keys page.')
                    ->schema([
                        Forms\Components\Placeholder::make('keys_info')
                            ->content(function () {
                                $stats = app(\App\Services\Tts\GeminiKeyManager::class)->getAggregateStats();
                                return "🔑 {$stats['total_keys']} API keys configured, {$stats['available_keys']} available now";
                            }),
                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('manage_keys')
                                ->label('Manage Gemini API Keys')
                                ->icon('heroicon-o-key')
                                ->url(fn () => route('filament.admin.pages.gemini-keys'))
                                ->openUrlInNewTab(),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $group = 'tts';

        // Save scalar settings
        $scalarKeys = [
            'tts_mode',
            'tts_fallback_enabled',
            'gemini_default_voice',
            'gemini_timeout_seconds',
        ];

        foreach ($scalarKeys as $key) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                SiteSetting::set($key, is_bool($value) ? ($value ? '1' : '0') : (string) $value, $group);
            }
        }

        // Save per-plan overrides
        if (isset($data['plan_overrides']) && is_array($data['plan_overrides'])) {
            foreach ($data['plan_overrides'] as $slug => $provider) {
                SiteSetting::set("tts_plan_provider_{$slug}", (string) $provider, $group);
            }
        }

        SiteSetting::flushGroupCache($group);

        Notification::make()
            ->title('TTS settings saved')
            ->success()
            ->send();
    }

    /**
     * Get real-time usage statistics for the dashboard.
     */
    public function getUsageStats(): array
    {
        return app(\App\Services\Tts\GeminiKeyManager::class)->getAggregateStats();
    }

    /**
     * Reset today's usage counters (admin action).
     */
    public function resetCounters(): void
    {
        app(\App\Services\Tts\GeminiKeyManager::class)->resetAllCounters();

        Notification::make()
            ->title('Usage counters reset')
            ->body('All Gemini API key counters have been reset.')
            ->success()
            ->send();
    }
}
