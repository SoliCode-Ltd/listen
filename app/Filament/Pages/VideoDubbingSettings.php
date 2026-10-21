<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use App\Models\VideoDub;
use App\Services\Dubbing\DriveArchive;
use App\Services\Dubbing\VoiceSeparator;
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
            'video_dubbing_separation' => VoiceSeparator::enabled(),
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
                Forms\Components\Section::make('Taking the old voices out')
                    ->description('Before the new voices go in, a separation model on this server takes the original voices out of the video\'s sound and keeps its music and effects, and a speech isolation keeps the voices further back than the main speaker (children reciting, a crowd), so only the main speaker is replaced. Without it, simple filters are used, which leave some of the old voice.')
                    ->schema([
                        Forms\Components\Placeholder::make('separator_status')
                            ->label('Status')
                            ->content(fn (): HtmlString => self::separatorStatus()),
                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('install_separator')
                                ->label(fn (): string => VoiceSeparator::installed() ? 'Install again' : 'Install on this server')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->color(fn (): string => VoiceSeparator::installed() ? 'gray' : 'primary')
                                ->requiresConfirmation()
                                ->modalHeading('Install the voice separation?')
                                ->modalDescription('Downloads about 190 MB (the model and what runs it) and tests it, in the background. It takes a minute or two; refresh this page to see how it went. Dubs keep working meanwhile.')
                                ->action(function (): void {
                                    VoiceSeparator::installAfterResponse();
                                    Notification::make()->title('Installing in the background: refresh this page in a minute or two.')->success()->send();
                                }),
                        ]),
                        Forms\Components\Toggle::make('video_dubbing_separation')
                            ->label('Use it for every dub')
                            ->helperText('On: the old voices are taken out of the whole track. Off: the filters only.'),
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

    /** Installed or not, what it needs, and how the last install went. */
    private static function separatorStatus(): HtmlString
    {
        $install = VoiceSeparator::installStatus();
        $last = $install === null ? '' : match ($install['state']) {
            'running' => '<br>Installing now: '.e($install['message']),
            'failed' => '<br><span style="color:#b91c1c;font-weight:600">The last install failed: '.e($install['message']).'</span>',
            default => '',
        };
        if (VoiceSeparator::installed()) {
            $d = VoiceSeparator::details() ?? [];

            $isolation = ($d['isolation'] ?? false) ? ' With the speech isolation (the voices further back are kept).' : ' <span style="color:#b45309">Without the speech isolation: press Install again to add it (the voices further back are then kept too).</span>';
            $isolation .= VoiceSeparator::vadReady() ? ' With the speech detection (each line is placed on the phrase it is spoken in).'
                : ' <span style="color:#b45309">Without the speech detection: press Install again to add it (each line is then placed on the phrase it is spoken in, so the new voice keeps closer to the lips).</span>';

            return new HtmlString(sprintf('<strong>Installed</strong>%s.'.$isolation.' One dub uses it at a time (about 1.4 GB of memory while it runs), and only when %d MB are free; a dub that cannot wait for it, or finds too little memory free, uses the filters. Should the server run short anyway, the system stops the separation first, never the site.%s%s',
                isset($d['installed_at']) ? ' on '.e(\Illuminate\Support\Carbon::parse($d['installed_at'])->toDayDateTimeString()).' (Python '.e(substr((string) ($d['python_version'] ?? ''), 0, 1).'.'.substr((string) ($d['python_version'] ?? ''), 1)).')' : '',
                (int) config('listen.dubbing.separator.min_free_mb', 2048), self::memoryLine(), $last));
        }
        $python = VoiceSeparator::python();

        return new HtmlString(self::memoryLine(false).($python === null
            ? '<strong>Not installed.</strong> This server has no Python 3 in the usual places, which it needs. Ask the host to install python3 (or set LISTEN_DUB_SEPARATOR_PYTHON to where it is), then press Install.'
            : '<strong>Not installed.</strong> Press Install (or run <code>php artisan dubs:separator install</code> on the server). Until then dubs use the filters.').$last);
    }

    /** The server's memory, from the system's own count. */
    private static function memoryLine(bool $leadingBreak = true): string
    {
        $memory = VoiceSeparator::memory();
        if ($memory === null) {
            return '';
        }
        $line = sprintf('This server: %.1f GB of memory, %.1f GB free now.', $memory['total_mb'] / 1024, $memory['free_mb'] / 1024);

        return $leadingBreak ? '<br>'.$line : $line.'<br>';
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
        SiteSetting::set('video_dubbing_separation', ! empty($state['video_dubbing_separation']) ? '1' : '0', self::GROUP);
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
