<?php

namespace App\Filament\Resources\VoiceSampleResource\Pages;

use App\Filament\Resources\VoiceSampleResource;
use App\Models\SiteSetting;
use App\Models\VoiceSample;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListVoiceSamples extends ListRecords
{
    protected static string $resource = VoiceSampleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('homepageSettings')
                ->label('Homepage Settings')
                ->icon('heroicon-o-cog-6-tooth')
                ->modalHeading('Homepage Voice Samples')
                ->fillForm(fn (): array => [
                    'landing_voice_samples_limit' => (int) SiteSetting::get('landing_voice_samples_limit', '12'),
                ])
                ->schema([
                    TextInput::make('landing_voice_samples_limit')
                        ->label('Voices shown on homepage')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->maxValue(60)
                        ->helperText('Applies to both Arabic homepage and /en homepage.'),
                ])
                ->action(function (array $data): void {
                    $limit = max(1, min(60, (int) ($data['landing_voice_samples_limit'] ?? 12)));
                    SiteSetting::set('landing_voice_samples_limit', (string) $limit, 'content');
                    VoiceSample::flushLandingCache();

                    Notification::make()
                        ->title('Homepage voice sample settings saved')
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
