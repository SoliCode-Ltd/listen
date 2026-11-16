<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use App\Support\Transcription\TranscriptionSettings;
use App\Support\VoiceCloningAccess;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The site's own settings for speech to text (
 * App\Support\Transcription\TranscriptionSettings): who sees it, the free try,
 * the daily ceiling for the whole site, uploads and how long their audio is
 * kept, the languages members can choose, the second listening, and Listen's
 * cost figures. What each plan includes (minutes a month, longest file,
 * timings, speakers, translation) is set on each plan (Plans → Speech to text).
 */
class TranscriptionSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|\UnitEnum|null $navigationGroup = 'Speech to text';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Speech to text settings';

    protected static ?string $slug = 'transcription-settings';

    protected string $view = 'filament.pages.transcription-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'transcription_audience' => TranscriptionSettings::audience(),
            'transcription_free_try_seconds' => TranscriptionSettings::freeTrySeconds(),
            'transcription_free_per_day' => TranscriptionSettings::freePerDay(),
            'transcription_daily_minutes' => TranscriptionSettings::dailyMinutes(),
            'transcription_max_upload_mb' => TranscriptionSettings::maxUploadMb(),
            'transcription_keep_audio_days' => TranscriptionSettings::keepAudioDays(),
            'transcription_stuck_minutes' => TranscriptionSettings::stuckMinutes(),
            'transcription_languages' => TranscriptionSettings::languages(),
            'transcription_review' => TranscriptionSettings::review(),
            'transcription_cost_per_minute' => TranscriptionSettings::costPerMinute(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Speech to text')
                    ->description('Members upload, record or link audio and get its text: paragraphs with who said them, edited in place, played back sentence by sentence, downloaded as text, Word or subtitles, and translated. Which plans include it, and their minutes a month, are set on each plan (Plans → Speech to text).')
                    ->schema([
                        Radio::make('transcription_audience')
                            ->label('Who can see it')
                            ->options([
                                TranscriptionSettings::AUDIENCE_OFF => 'Off for everyone',
                                TranscriptionSettings::AUDIENCE_PREVIEW => 'Admin email only',
                                TranscriptionSettings::AUDIENCE_EVERYONE => 'Everyone',
                            ])
                            ->descriptions([
                                TranscriptionSettings::AUDIENCE_OFF => 'Nobody can open it: not on the page, the API or MCP. Jobs already working finish; nothing is deleted.',
                                TranscriptionSettings::AUDIENCE_PREVIEW => 'Only '.VoiceCloningAccess::PREVIEW_EMAIL.' can open it, to try it before launch.',
                                TranscriptionSettings::AUDIENCE_EVERYONE => 'Members whose plan includes it use their minutes; everyone else gets the free try and sees the plan that includes it.',
                            ])
                            ->required(),
                    ]),
                Section::make('Free try and the whole site')
                    ->columns(2)
                    ->schema([
                        TextInput::make('transcription_free_try_seconds')
                            ->label('Free try: longest recording')
                            ->numeric()->integer()->minValue(0)->maxValue(300)->suffix('seconds')->required()
                            ->helperText('One recording, for members whose plan has no speech to text. 0: no free try.'),
                        TextInput::make('transcription_free_per_day')
                            ->label('Free tries a day, for the whole site')
                            ->numeric()->integer()->minValue(0)->maxValue(100000)->suffix('tries')->required()
                            ->helperText('So a rush of sign-ups cannot run up the bill.'),
                        TextInput::make('transcription_daily_minutes')
                            ->label('Minutes a day, for the whole site')
                            ->numeric()->integer()->minValue(0)->maxValue(1000000)->suffix('minutes')->required()
                            ->helperText('Members, the API and MCP together. Past it, new files wait for the next day (nothing is taken from members). 0: no ceiling.'),
                        TextInput::make('transcription_stuck_minutes')
                            ->label('A file still working after')
                            ->numeric()->integer()->minValue(5)->maxValue(240)->suffix('minutes')->required()
                            ->helperText('is stopped, and its minutes given back.'),
                    ]),
                Section::make('Uploads')
                    ->columns(2)
                    ->schema([
                        TextInput::make('transcription_max_upload_mb')
                            ->label('Largest file')
                            ->numeric()->integer()->minValue(10)->maxValue(2000)->suffix('MB')->required()
                            ->helperText('A plan\'s longest file (in minutes) is set on the plan.'),
                        TextInput::make('transcription_keep_audio_days')
                            ->label('Uploaded audio is deleted after')
                            ->numeric()->integer()->minValue(1)->maxValue(90)->suffix('days')->required()
                            ->helperText('The text stays until the member deletes it.'),
                    ]),
                Section::make('Languages')
                    ->description('"Detect automatically" is always there. Arabic is written in the speaker\'s own dialect, never rewritten into Standard Arabic.')
                    ->schema([
                        CheckboxList::make('transcription_languages')
                            ->label('Languages members can choose')
                            ->options(collect(TranscriptionSettings::LANGUAGES)->mapWithKeys(fn (array $l, string $code) => [$code => $l[0].' — '.$l[1].($l[2] ? ' (measured)' : '')])->all())
                            ->helperText('All are offered unless you untick some. "Measured": its accuracy was checked on real speech.')
                            ->searchable()
                            ->bulkToggleable()
                            ->columns(3)
                            ->required(),
                    ]),
                Section::make('Accuracy and cost')
                    ->columns(2)
                    ->schema([
                        Toggle::make('transcription_review')
                            ->label('Check every transcript a second time')
                            ->helperText('Each part is heard again with its first transcript, to correct it. More accurate on hard dialects, and about twice the cost a minute. Members are charged the same minutes either way.')
                            ->columnSpan(2),
                        TextInput::make('transcription_cost_per_minute')
                            ->label('Cost to Listen a minute of audio')
                            ->numeric()->minValue(0.0001)->maxValue(1)->step(0.0001)->prefix('$')->required()
                            ->helperText('About $0.0045: $0.0025 measured on staging for writing out, plus about $0.002 for placing each sentence on the audio (twice the writing-out part with the second check on). Update it from the Google bill. For the admin\'s cost and margin figures only.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $s = $this->form->getState();
        $group = TranscriptionSettings::GROUP;
        $audience = (string) ($s['transcription_audience'] ?? TranscriptionSettings::AUDIENCE_PREVIEW);
        SiteSetting::set('transcription_audience', in_array($audience, [TranscriptionSettings::AUDIENCE_OFF, TranscriptionSettings::AUDIENCE_EVERYONE], true) ? $audience : TranscriptionSettings::AUDIENCE_PREVIEW, $group);
        foreach ([
            'transcription_free_try_seconds' => [0, 300], 'transcription_free_per_day' => [0, 100000], 'transcription_daily_minutes' => [0, 1000000],
            'transcription_stuck_minutes' => [5, 240], 'transcription_max_upload_mb' => [10, 2000], 'transcription_keep_audio_days' => [1, 90],
        ] as $key => [$min, $max]) {
            SiteSetting::set($key, max($min, min($max, (int) ($s[$key] ?? $min))), $group);
        }
        $languages = array_values(array_intersect(array_keys(TranscriptionSettings::LANGUAGES), (array) ($s['transcription_languages'] ?? [])));
        SiteSetting::set('transcription_languages', json_encode($languages !== [] ? $languages : ['ar', 'en']), $group);
        SiteSetting::set('transcription_review', ! empty($s['transcription_review']) ? '1' : '0', $group);
        SiteSetting::set('transcription_cost_per_minute', (string) max(0.0001, min(1.0, (float) ($s['transcription_cost_per_minute'] ?? 0))), $group);
        SiteSetting::flushGroupCache($group);

        Notification::make()->title('Speech to text settings saved.')->success()->send();
    }
}
