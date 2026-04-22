<?php

namespace App\Filament\Pages;

use App\Models\ReferralCampaign;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ReferralCampaignSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'Referrals';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Referral Campaign';

    protected static ?string $navigationLabel = 'Campaign Settings';

    protected static string $view = 'filament.pages.referral-campaign-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $campaign = ReferralCampaign::query()
            ->where('slug', 'default')
            ->first();

        if (! $campaign) {
            $campaign = ReferralCampaign::query()->orderByDesc('id')->first();
        }

        $state = $campaign
            ? $campaign->only([
                'slug',
                'name',
                'is_active',
                'qualification_step',
                'reward_chars',
                'visit_window_days',
                'max_conversions_per_referrer_per_day',
                'max_conversions_per_referrer_total',
                'notes',
            ])
            : [
                'slug' => 'default',
                'name' => 'Default Referral Campaign',
                'is_active' => true,
                'qualification_step' => ReferralCampaign::QUALIFICATION_PAID_PLAN,
                'reward_chars' => 1000,
                'visit_window_days' => 30,
                'max_conversions_per_referrer_per_day' => 5,
                'max_conversions_per_referrer_total' => 200,
                'notes' => null,
            ];

        $this->form->fill($state);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Campaign')
                    ->description('Controls the single active referral program. Switch off to pause without losing data.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->maxLength(60)
                            ->helperText('Internal identifier. Leave as "default" unless you run multiple campaigns.')
                            ->default('default'),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(120),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Campaign Active')
                            ->helperText('If disabled, new conversions stop being created but existing balances remain.'),
                        Forms\Components\Select::make('qualification_step')
                            ->label('Reward Trigger')
                            ->required()
                            ->options([
                                ReferralCampaign::QUALIFICATION_PAID_PLAN => 'Referred user purchases any paid plan',
                                ReferralCampaign::QUALIFICATION_SIGNUP => 'Referred user completes signup',
                                ReferralCampaign::QUALIFICATION_EMAIL_VERIFIED => 'Referred user verifies email',
                                ReferralCampaign::QUALIFICATION_FIRST_DEMO => 'Referred user runs their first demo',
                            ])
                            ->helperText('The event that promotes a pending conversion to approved. Default: paid plan purchase.'),
                    ]),

                Forms\Components\Section::make('Reward')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('reward_chars')
                            ->label('Reward Characters')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1000000)
                            ->required()
                            ->default(1000)
                            ->suffix('chars')
                            ->helperText('Amount of demo-only bonus characters granted per approved conversion.'),
                        Forms\Components\TextInput::make('visit_window_days')
                            ->label('Visit Attribution Window')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(365)
                            ->required()
                            ->default(30)
                            ->suffix('days')
                            ->helperText('How long a tracked visit remains valid for binding a referrer after a guest lands.'),
                    ]),

                Forms\Components\Section::make('Anti-Abuse Limits')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('max_conversions_per_referrer_per_day')
                            ->label('Daily Cap per Referrer')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1000)
                            ->default(5)
                            ->helperText('Daily ceiling — excess conversions get flagged for review, not rejected outright.'),
                        Forms\Components\TextInput::make('max_conversions_per_referrer_total')
                            ->label('Lifetime Cap per Referrer')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100000)
                            ->default(200)
                            ->helperText('Lifetime ceiling — beyond this, new conversions are flagged for admin review.'),
                    ]),

                Forms\Components\Section::make('Notes')
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->rows(3)
                            ->maxLength(2000)
                            ->helperText('Optional internal notes about the campaign (not shown to users).'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $slug = trim((string) ($data['slug'] ?? 'default')) ?: 'default';

        $campaign = ReferralCampaign::query()
            ->where('slug', $slug)
            ->first();

        $attrs = [
            'slug' => $slug,
            'name' => (string) ($data['name'] ?? 'Default Referral Campaign'),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'qualification_step' => (string) ($data['qualification_step'] ?? ReferralCampaign::QUALIFICATION_PAID_PLAN),
            'reward_chars' => max(0, (int) ($data['reward_chars'] ?? 1000)),
            'visit_window_days' => max(1, (int) ($data['visit_window_days'] ?? 30)),
            'max_conversions_per_referrer_per_day' => max(0, (int) ($data['max_conversions_per_referrer_per_day'] ?? 5)),
            'max_conversions_per_referrer_total' => max(0, (int) ($data['max_conversions_per_referrer_total'] ?? 200)),
            'notes' => $data['notes'] ?? null,
        ];

        if ($campaign) {
            $campaign->fill($attrs)->save();
        } else {
            ReferralCampaign::query()->create($attrs);
        }

        if (! empty($attrs['is_active'])) {
            ReferralCampaign::query()
                ->where('slug', '!=', $slug)
                ->update(['is_active' => false]);
        }

        ReferralCampaign::forgetCache();

        Notification::make()
            ->title('Referral campaign saved')
            ->success()
            ->send();
    }
}
