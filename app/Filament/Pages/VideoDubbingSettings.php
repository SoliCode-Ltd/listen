<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use App\Models\VideoDub;
use App\Services\Dubbing\DriveArchive;
use App\Support\VideoDubbing;
use App\Support\VoiceCloningAccess;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\HtmlString;

/**
 * Who sees "Dub my video" (App\Support\VideoDubbing), the free dub's limits,
 * the largest upload, and where the videos are kept: the site's Google Drive
 * (App\Services\Dubbing\DriveArchive) or the server. Which plans include it,
 * their videos a month and their longest video are set on each plan.
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

    private const GROUP = 'video_dubbing';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'video_dubbing_audience' => VideoDubbing::audience(),
            'video_dubbing_max_upload_mb' => VideoDubbing::uploadSettingMb(),
            'video_dubbing_free_max_upload_mb' => VideoDubbing::uploadSettingMb(true),
            'video_dubbing_free_max_seconds' => VideoDubbing::freeMaxSeconds(),
            'video_dubbing_free_keep_hours' => VideoDubbing::freeKeepHours(),
            'video_dubbing_drive_enabled' => DriveArchive::enabled(),
            'video_dubbing_server_keep_days' => VideoDubbing::serverKeepDays(),
            'video_dubbing_drive_client_id' => (string) SiteSetting::get('video_dubbing_drive_client_id', ''),
            // Never shown back: left empty keeps the saved one.
            'video_dubbing_drive_client_secret' => '',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Dub my video')
                    ->description('A member uploads a short video and gets it back in the Arabic dialect or English accent they choose.')
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
                Forms\Components\Section::make('Uploads and the free dub')
                    ->description('What the upload box shows: the largest file, and the longest video. A plan\'s longest video is set on the plan (Plans → Dub my video).')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('video_dubbing_free_max_upload_mb')
                            ->label('Largest video for the free dub (demo)')
                            ->numeric()
                            ->integer()
                            ->minValue(10)
                            ->maxValue(2000)
                            ->suffix('MB')
                            ->required()
                            ->helperText('Members without a plan with dubbing.'),
                        Forms\Components\TextInput::make('video_dubbing_max_upload_mb')
                            ->label('Largest video for plans with dubbing')
                            ->numeric()
                            ->integer()
                            ->minValue(10)
                            ->maxValue(2000)
                            ->suffix('MB')
                            ->required()
                            ->helperText(fn (): string => 'Members whose plan includes dubbing. Videos are sent in pieces of '.intdiv(VideoDubbing::chunkBytes(), 1024 * 1024).' MB, so the server\'s own upload limit does not cap this.'),
                        Forms\Components\TextInput::make('video_dubbing_free_max_seconds')
                            ->label('Longest free video')
                            ->numeric()
                            ->integer()
                            ->minValue(5)
                            ->maxValue(180)
                            ->suffix('seconds')
                            ->required()
                            ->helperText('Also a plan\'s longest video when the plan sets none.'),
                        Forms\Components\TextInput::make('video_dubbing_free_keep_hours')
                            ->label('Free video stays on the member\'s dashboard for')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(168)
                            ->suffix('hours')
                            ->required()
                            ->helperText('Then the member no longer sees it. A plan\'s videos stay as long as the plan keeps voices (Plans → "Recent Voices Retention").'),
                    ]),
                Forms\Components\Section::make('Where the videos are kept')
                    ->description('Members see their videos only while their time lasts. You keep every video and all the figures here, in Video Dubbing → Dubs.')
                    ->schema([
                        Forms\Components\Placeholder::make('drive_status')
                            ->label('Google Drive')
                            ->content(fn (): HtmlString => self::driveStatus()),
                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('connect_drive')
                                ->label(fn (): string => DriveArchive::connected() ? 'Connect another Google account' : 'Connect Google Drive')
                                ->icon('heroicon-o-link')
                                ->color(fn (): string => DriveArchive::connected() ? 'gray' : 'primary')
                                ->url(fn (): string => route('admin.drive.connect')),
                            Forms\Components\Actions\Action::make('open_drive')
                                ->label('Open the Listen Studio folder')
                                ->icon('heroicon-o-folder-open')
                                ->color('gray')
                                ->visible(fn (): bool => DriveArchive::connected())
                                ->url(fn (): ?string => DriveArchive::folderUrl())
                                ->openUrlInNewTab(),
                            Forms\Components\Actions\Action::make('disconnect_drive')
                                ->label('Disconnect')
                                ->icon('heroicon-o-x-mark')
                                ->color('danger')
                                ->visible(fn (): bool => DriveArchive::connected())
                                ->requiresConfirmation()
                                ->modalHeading('Disconnect Google Drive?')
                                ->modalDescription('New videos stay on the server. The videos already in Drive stay there, but members and this admin cannot play them here until Drive is connected again.')
                                ->action(function (): void {
                                    app(DriveArchive::class)->disconnect();
                                    Notification::make()->title('Google Drive disconnected')->success()->send();
                                }),
                        ]),
                        Forms\Components\Toggle::make('video_dubbing_drive_enabled')
                            ->label('Keep the videos in Google Drive')
                            ->helperText('On: each video goes to Listen Studio → Paid or Free → the member\'s folder (a paid one as soon as it is ready, a free one when the member\'s time is over) and is deleted from the server. Off: new videos stay on the server.'),
                        Forms\Components\TextInput::make('video_dubbing_server_keep_days')
                            ->label('Videos on the server are kept for')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(3650)
                            ->suffix('days')
                            ->required()
                            ->helperText('When Google Drive is off or not connected. Never before the member\'s time is over. 0 keeps them on the server.'),
                        Forms\Components\Fieldset::make('Google client (only if the Connect button says it is missing)')
                            ->columns(2)
                            ->schema([
                                Forms\Components\TextInput::make('video_dubbing_drive_client_id')
                                    ->label('Client ID')
                                    ->maxLength(255)
                                    ->placeholder(fn (): string => filled(config('services.google.client_id')) ? 'The site\'s Google sign-in client is used' : 'From Google Cloud → APIs & Services → Credentials'),
                                Forms\Components\TextInput::make('video_dubbing_drive_client_secret')
                                    ->label('Client secret')
                                    ->password()
                                    ->maxLength(255)
                                    ->placeholder(fn (): string => filled(SiteSetting::get('video_dubbing_drive_client_secret', '')) ? 'Saved (leave empty to keep it)' : 'Leave empty to use the site\'s Google sign-in client'),
                            ]),
                    ]),
                Forms\Components\Section::make('Statistics')
                    ->schema([
                        Forms\Components\Placeholder::make('stats')
                            ->label('')
                            ->content(function (): string {
                                $all = VideoDub::query();
                                $today = VideoDub::query()->where('created_at', '>=', now()->startOfDay());

                                return sprintf('All time: %d dubs (%d free, %d failed). Today: %d (%d free, the most a day is %d). Videos in Google Drive: %d; on the server: %d.',
                                    (clone $all)->count(), (clone $all)->where('is_free', true)->count(), (clone $all)->where('status', VideoDub::FAILED)->count(),
                                    (clone $today)->count(), (clone $today)->where('is_free', true)->count(), (int) config('listen.dubbing.free_per_day', 50),
                                    (clone $all)->whereNotNull('drive_file_id')->count(),
                                    (clone $all)->where('status', VideoDub::READY)->whereNull('drive_file_id')->whereNull('files_deleted_at')->count());
                            }),
                    ]),
            ])
            ->statePath('data');
    }

    /** Connected to which account, or what to set up first. */
    private static function driveStatus(): HtmlString
    {
        if (DriveArchive::connected()) {
            return new HtmlString(sprintf('Connected to <strong>%s</strong>. The site sees only the folders and videos it made itself, not the rest of the Drive.',
                e(DriveArchive::account() ?: 'a Google account')));
        }
        $steps = [
            'In Google Cloud (the project of the site\'s Google sign-in), open APIs &amp; Services → Library and enable <strong>Google Drive API</strong>.',
            'In APIs &amp; Services → Credentials, open the OAuth client and add this to "Authorized redirect URIs": <code>'.e(DriveArchive::redirectUrl()).'</code>',
            'Press <strong>Connect Google Drive</strong> below and allow access. The site makes its own Listen Studio folder in that Drive.',
        ];

        return new HtmlString('Not connected: the videos stay on the server. To connect, once:<ol style="list-style: decimal; padding-inline-start: 1.25rem; margin-top: .35rem;"><li>'.implode('</li><li>', $steps).'</li></ol>');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $audience = (string) ($state['video_dubbing_audience'] ?? VideoDubbing::AUDIENCE_PREVIEW);
        SiteSetting::set('video_dubbing_audience', $audience === VideoDubbing::AUDIENCE_EVERYONE ? $audience : VideoDubbing::AUDIENCE_PREVIEW, self::GROUP);
        SiteSetting::set('video_dubbing_max_upload_mb', max(10, (int) ($state['video_dubbing_max_upload_mb'] ?? 300)), self::GROUP);
        SiteSetting::set('video_dubbing_free_max_upload_mb', max(10, (int) ($state['video_dubbing_free_max_upload_mb'] ?? 300)), self::GROUP);
        SiteSetting::set('video_dubbing_free_max_seconds', max(5, min(180, (int) ($state['video_dubbing_free_max_seconds'] ?? 60))), self::GROUP);
        SiteSetting::set('video_dubbing_free_keep_hours', max(1, min(168, (int) ($state['video_dubbing_free_keep_hours'] ?? 24))), self::GROUP);
        SiteSetting::set('video_dubbing_drive_enabled', ! empty($state['video_dubbing_drive_enabled']) ? '1' : '0', self::GROUP);
        SiteSetting::set('video_dubbing_server_keep_days', max(0, min(3650, (int) ($state['video_dubbing_server_keep_days'] ?? 30))), self::GROUP);
        SiteSetting::set('video_dubbing_drive_client_id', trim((string) ($state['video_dubbing_drive_client_id'] ?? '')), self::GROUP);
        $secret = trim((string) ($state['video_dubbing_drive_client_secret'] ?? ''));
        if ($secret !== '') {
            SiteSetting::set('video_dubbing_drive_client_secret', Crypt::encryptString($secret), self::GROUP);
        }
        SiteSetting::flushGroupCache(self::GROUP);
        $this->data['video_dubbing_drive_client_secret'] = '';

        Notification::make()->title('Video dubbing settings saved.')->success()->send();
    }
}
