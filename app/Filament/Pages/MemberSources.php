<?php

namespace App\Filament\Pages;

use App\Services\Acquisition\SourceReport;
use App\Support\Acquisition\Channels;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

/**
 * Where members come from: how the members of a period found the site (App\Services\Acquisition\SourceReport),
 * how many of them paid and what they paid, and the period's payments by how each payer found it.
 */
class MemberSources extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-funnel';

    protected static ?string $navigationLabel = 'Where members come from';

    protected static ?int $navigationSort = -1;

    protected static ?string $title = 'Where members come from';

    protected static ?string $slug = 'member-sources';

    protected string $view = 'filament.pages.member-sources';

    /** @var array<string, string> */
    public const PERIODS = [
        '7' => 'Last 7 days',
        '30' => 'Last 30 days',
        '90' => 'Last 90 days',
        'month' => 'This month',
        'last_month' => 'Last month',
        'recorded' => 'Since this was first recorded',
        'custom' => 'Choose the dates',
    ];

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'period' => '30',
            'count' => SourceReport::COUNT_LAST,
            'signed_up_in' => 'all',
            'from' => now()->subDays(30)->toDateString(),
            'until' => now()->toDateString(),
        ]);
    }

    public function getSubheading(): ?string
    {
        $since = SourceReport::recordedSince();

        return $since !== null
            ? 'How each new member found the site, and what they have paid. Kept for every member who signed up since '.$since->format('j M Y').'; older members show only what the site kept then (a Google or ChatGPT ad click, an invite link).'
            : 'How each new member found the site, and what they have paid. Nothing has been recorded yet: it starts with the next member who signs up. Older members show only what the site kept then (a Google or ChatGPT ad click, an invite link).';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                    ->schema([
                        Select::make('period')
                            ->label('Members who signed up')
                            ->options(self::PERIODS)
                            ->selectablePlaceholder(false)
                            ->live(),
                        DatePicker::make('from')
                            ->label('From')
                            ->native(false)
                            ->maxDate(now())
                            ->visible(fn (Get $get) => $get('period') === 'custom')
                            ->live(),
                        DatePicker::make('until')
                            ->label('To (that day included)')
                            ->native(false)
                            ->maxDate(now())
                            ->visible(fn (Get $get) => $get('period') === 'custom')
                            ->live(),
                        Select::make('count')
                            ->label('Count each member by')
                            ->options([
                                SourceReport::COUNT_LAST => 'Last source before signing up',
                                SourceReport::COUNT_FIRST => 'First visit',
                            ])
                            ->selectablePlaceholder(false)
                            ->live(),
                        Select::make('signed_up_in')
                            ->label('Signed up in')
                            ->options(['all' => 'The website and the apps', 'web' => 'The website', 'android' => 'The Android app', 'ios' => 'The iPhone app'])
                            ->helperText('Choosing one leaves out the members who joined before this was recorded.')
                            ->selectablePlaceholder(false)
                            ->live(),
                    ]),
            ])
            ->statePath('data');
    }

    /** @return array{0: Carbon, 1: Carbon} the start and the end (not included) of the chosen period */
    public function range(): array
    {
        $now = now();
        $period = (string) ($this->data['period'] ?? '30');

        return match ($period) {
            '7', '30', '90' => [$now->copy()->subDays((int) $period), $now->copy()->addSecond()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->addSecond()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->startOfMonth()],
            'recorded' => [SourceReport::recordedSince() ?? $now->copy(), $now->copy()->addSecond()],
            default => $this->customRange($now),
        };
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        [$from, $to] = $this->range();
        $count = ($this->data['count'] ?? '') === SourceReport::COUNT_FIRST ? SourceReport::COUNT_FIRST : SourceReport::COUNT_LAST;
        $signedUpIn = in_array($this->data['signed_up_in'] ?? 'all', ['web', 'android', 'ios'], true) ? (string) $this->data['signed_up_in'] : 'all';

        return app(SourceReport::class)->build($from, $to, $count, $signedUpIn);
    }

    /** @return array<string, string> what each group holds */
    public function channelKey(): array
    {
        return collect(Channels::HELP)
            ->mapWithKeys(fn (string $help, string $channel) => [Channels::label($channel) => $help])
            ->all();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function customRange(Carbon $now): array
    {
        try {
            $from = Carbon::parse((string) ($this->data['from'] ?? ''))->startOfDay();
        } catch (\Throwable) {
            $from = $now->copy()->subDays(30)->startOfDay();
        }
        try {
            $until = Carbon::parse((string) ($this->data['until'] ?? ''))->startOfDay()->addDay();
        } catch (\Throwable) {
            $until = $now->copy()->startOfDay()->addDay();
        }

        return $from->lessThan($until) ? [$from, $until] : [$until->copy()->subDay(), $from->copy()->addDay()];
    }
}
