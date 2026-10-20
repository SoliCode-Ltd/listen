<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use App\Models\VideoDub;
use App\Support\VideoDubbing;
use App\Support\VoiceCloningAccess;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Who sees "Dub my video" (App\Support\VideoDubbing). Which plans include it
 * is set on each plan.
 */
class VideoDubbingSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Video Dubbing';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Video Dubbing Settings';

    protected static string $view = 'filament.pages.video-dubbing-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['video_dubbing_audience' => VideoDubbing::audience()]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Dub my video')
                    ->description('A member uploads a video of up to a minute and gets it back in the Arabic dialect they choose.')
                    ->schema([
                        Forms\Components\Radio::make('video_dubbing_audience')
                            ->label('Who can see the page')
                            ->options([
                                VideoDubbing::AUDIENCE_PREVIEW => 'Admin email only',
                                VideoDubbing::AUDIENCE_EVERYONE => 'Everyone',
                            ])
                            ->descriptions([
                                VideoDubbing::AUDIENCE_PREVIEW => 'Only '.VoiceCloningAccess::PREVIEW_EMAIL.' can open it, to try it before launch. Everyone else gets "page not found".',
                                VideoDubbing::AUDIENCE_EVERYONE => 'Anyone signed in gets one free dub with the Listen mark. Plans with "Include video dubbing" switched on pay from their credits and download without the mark.',
                            ])
                            ->required(),
                    ]),
                Forms\Components\Section::make('Statistics')
                    ->schema([
                        Forms\Components\Placeholder::make('stats')
                            ->label('')
                            ->content(function (): string {
                                $all = VideoDub::query();
                                $today = VideoDub::query()->where('created_at', '>=', now()->startOfDay());

                                return sprintf('All time: %d dubs (%d free, %d failed). Today: %d (%d free, the most a day is %d).',
                                    (clone $all)->count(), (clone $all)->where('is_free', true)->count(), (clone $all)->where('status', VideoDub::FAILED)->count(),
                                    (clone $today)->count(), (clone $today)->where('is_free', true)->count(), (int) config('listen.dubbing.free_per_day', 50));
                            }),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $audience = (string) ($this->form->getState()['video_dubbing_audience'] ?? VideoDubbing::AUDIENCE_PREVIEW);
        SiteSetting::set('video_dubbing_audience', $audience === VideoDubbing::AUDIENCE_EVERYONE ? $audience : VideoDubbing::AUDIENCE_PREVIEW, 'video_dubbing');
        SiteSetting::flushGroupCache('video_dubbing');

        Notification::make()->title('Video dubbing settings saved.')->success()->send();
    }
}
