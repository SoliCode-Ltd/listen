<?php

namespace App\Filament\Pages;

use App\Models\DesignedVoice;
use App\Models\SiteSetting;
use App\Support\VoiceDesign;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Voice Design → Settings: who sees it, how many takes a design makes, the
 * site-wide number of voices a member keeps (a plan can set its own, Admin →
 * Plans → Voice Design), how many designs an hour, and what a design costs.
 */
class VoiceDesignSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Voice Design';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Voice Design Settings';

    protected static string $view = 'filament.pages.voice-design-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'voice_design_audience' => VoiceDesign::audience(),
            'voice_design_takes' => VoiceDesign::takes(),
            'voice_design_max_per_user' => (int) SiteSetting::get('voice_design_max_per_user', 10),
            'voice_design_hourly_limit' => VoiceDesign::hourlyLimit(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Who can use Voice Design')
                    ->description('Which plans include it, how many voices their members keep and how many new ones a month: Admin → Plans → Voice Design.')
                    ->schema([
                        Forms\Components\Radio::make('voice_design_audience')
                            ->label('Open to')
                            ->options([
                                VoiceDesign::AUDIENCE_OFF => 'Nobody (switched off)',
                                VoiceDesign::AUDIENCE_PREVIEW => 'Admin email only',
                                VoiceDesign::AUDIENCE_EVERYONE => 'Everyone',
                            ])
                            ->descriptions([
                                VoiceDesign::AUDIENCE_OFF => 'No section and no demo tab, for anyone. Voices members kept stay saved but leave the studio\'s voice list until it is open again.',
                                VoiceDesign::AUDIENCE_PREVIEW => 'Only the admin email sees the section and the demo tab, and may design on any plan.',
                                VoiceDesign::AUDIENCE_EVERYONE => 'Every signed-in member sees the section: plans with Voice Design design and keep voices, the others see what it does and the way to a plan with it. Free accounts can try one take in the demo, from the demo\'s characters.',
                            ])
                            ->default(VoiceDesign::AUDIENCE_PREVIEW)
                            ->required(),
                    ]),

                Forms\Components\Section::make('Designs')
                    ->columns(3)
                    ->schema([
                        Forms\Components\Select::make('voice_design_takes')
                            ->label('Takes a design makes')
                            ->options([1 => '1 take', 2 => '2 takes', 3 => '3 takes'])
                            ->default(3)
                            ->live()
                            ->required()
                            ->helperText('Each take is a voice of its own speaking the same line, and is charged. The demo always makes one.'),
                        Forms\Components\TextInput::make('voice_design_max_per_user')
                            ->label('Voices a member keeps (site default)')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(100)
                            ->default(10)
                            ->required()
                            ->helperText('Used by plans whose own number is 0.'),
                        Forms\Components\TextInput::make('voice_design_hourly_limit')
                            ->label('Designs a member may start an hour')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(200)
                            ->default(12)
                            ->required(),
                        Forms\Components\Placeholder::make('cost')
                            ->label('What a design costs')
                            ->content(fn (Forms\Get $get): string => VoiceDesign::costSummary((int) ($get('voice_design_takes') ?: 3)))
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Statistics')
                    ->schema([
                        Forms\Components\Placeholder::make('stats')
                            ->label('')
                            ->content(function (): string {
                                $kept = DesignedVoice::query()->saved()->count();
                                $drafts = DesignedVoice::query()->drafts()->count();
                                $showcase = DesignedVoice::query()->saved()->where('is_showcase', true)->count();
                                $designs = DesignedVoice::withTrashed()->where('created_at', '>=', now()->subDays(30))->distinct()->count('batch');

                                return "Kept voices: {$kept} | Takes waiting to be kept: {$drafts} | On the Voice Design card: {$showcase} | Designs in the last 30 days: {$designs}";
                            }),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $audience = (string) ($data['voice_design_audience'] ?? VoiceDesign::AUDIENCE_PREVIEW);
        if (! in_array($audience, [VoiceDesign::AUDIENCE_OFF, VoiceDesign::AUDIENCE_PREVIEW, VoiceDesign::AUDIENCE_EVERYONE], true)) {
            $audience = VoiceDesign::AUDIENCE_PREVIEW;
        }
        SiteSetting::set('voice_design_audience', $audience, VoiceDesign::GROUP);
        SiteSetting::set('voice_design_takes', max(1, min(3, (int) ($data['voice_design_takes'] ?? 3))), VoiceDesign::GROUP);
        SiteSetting::set('voice_design_max_per_user', max(1, (int) ($data['voice_design_max_per_user'] ?? 10)), VoiceDesign::GROUP);
        SiteSetting::set('voice_design_hourly_limit', max(1, (int) ($data['voice_design_hourly_limit'] ?? 12)), VoiceDesign::GROUP);
        SiteSetting::flushGroupCache(VoiceDesign::GROUP);

        Notification::make()->title('Voice Design settings saved.')->success()->send();
    }
}
