<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class VoiceCloningSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Voice Cloning';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Voice Cloning Settings';

    protected static string $view = 'filament.pages.voice-cloning-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'voice_cloning_enabled' => (string) SiteSetting::get('voice_cloning_enabled', '1') === '1',
            'voice_cloning_max_per_user' => (int) SiteSetting::get('voice_cloning_max_per_user', 5),
            'voice_cloning_min_duration' => (int) SiteSetting::get('voice_cloning_min_duration', 10),
            'voice_cloning_max_upload_mb' => (int) SiteSetting::get('voice_cloning_max_upload_mb', 25),
            'voice_cloning_region' => SiteSetting::get('voice_cloning_region', 'global'),
            'voice_cloning_recording_enabled' => (string) SiteSetting::get('voice_cloning_recording_enabled', '1') === '1',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Voice Cloning Feature')
                    ->description('Control voice cloning availability and limits for Listen Studio.')
                    ->schema([
                        Forms\Components\Toggle::make('voice_cloning_enabled')
                            ->label('Enable Voice Cloning')
                            ->helperText('Globally enable or disable the voice cloning feature. Per-plan access is controlled in each Plan\'s settings.')
                            ->default(true),

                        Forms\Components\Toggle::make('voice_cloning_recording_enabled')
                            ->label('Enable Browser Recording')
                            ->helperText('Allow users to record voice samples directly in the browser.')
                            ->default(true),
                    ]),

                Forms\Components\Section::make('Global Defaults & Validation')
                    ->description('These are global defaults. Per-plan limits (set in Plan settings) take priority when configured.')
                    ->schema([
                        Forms\Components\TextInput::make('voice_cloning_max_per_user')
                            ->label('Default Max Cloned Voices Per User')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(50)
                            ->default(5)
                            ->helperText('Fallback limit when a plan does not specify its own limit.'),

                        Forms\Components\TextInput::make('voice_cloning_min_duration')
                            ->label('Minimum Sample Duration (seconds)')
                            ->numeric()
                            ->minValue(5)
                            ->maxValue(60)
                            ->default(10)
                            ->helperText('Minimum audio sample duration in seconds.'),

                        Forms\Components\TextInput::make('voice_cloning_max_upload_mb')
                            ->label('Maximum Upload Size (MB)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->default(25)
                            ->helperText('Maximum file upload size in megabytes.'),
                    ]),

                Forms\Components\Section::make('Listen voice service')
                    ->description('Region used when Listen creates a cloned voice.')
                    ->schema([
                        Forms\Components\Select::make('voice_cloning_region')
                            ->label('API Region')
                            ->options([
                                'global' => 'Global',
                                'us' => 'United States (us)',
                                'eu' => 'Europe (eu)',
                                'asia-southeast1' => 'Asia Southeast (asia-southeast1)',
                                'asia-northeast1' => 'Asia Northeast (asia-northeast1)',
                                'europe-west2' => 'Europe West (europe-west2)',
                            ])
                            ->default('global')
                            ->helperText('Region Listen uses for voice cloning.'),
                    ]),

                Forms\Components\Section::make('Statistics')
                    ->schema([
                        Forms\Components\Placeholder::make('stats')
                            ->label('')
                            ->content(function () {
                                $total = \App\Models\ClonedVoice::count();
                                $ready = \App\Models\ClonedVoice::where('status', 'ready')->count();
                                $processing = \App\Models\ClonedVoice::whereIn('status', ['pending', 'validating', 'processing'])->count();
                                $failed = \App\Models\ClonedVoice::where('status', 'failed')->count();

                                return "Total: {$total} | Ready: {$ready} | Processing: {$processing} | Failed: {$failed}";
                            }),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        SiteSetting::set('voice_cloning_enabled', ($data['voice_cloning_enabled'] ?? false) ? '1' : '0', 'voice_cloning');
        SiteSetting::set('voice_cloning_recording_enabled', ($data['voice_cloning_recording_enabled'] ?? false) ? '1' : '0', 'voice_cloning');
        SiteSetting::set('voice_cloning_max_per_user', (int) ($data['voice_cloning_max_per_user'] ?? 5), 'voice_cloning');
        SiteSetting::set('voice_cloning_min_duration', (int) ($data['voice_cloning_min_duration'] ?? 10), 'voice_cloning');
        SiteSetting::set('voice_cloning_max_upload_mb', (int) ($data['voice_cloning_max_upload_mb'] ?? 25), 'voice_cloning');
        SiteSetting::set('voice_cloning_region', $data['voice_cloning_region'] ?? 'global', 'voice_cloning');

        SiteSetting::flushGroupCache('voice_cloning');

        Notification::make()
            ->title('Voice cloning settings saved.')
            ->success()
            ->send();
    }
}
