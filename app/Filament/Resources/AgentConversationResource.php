<?php

namespace App\Filament\Resources;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use App\Support\Agents\CallRecordings;
use App\Filament\Resources\AgentConversationResource\Pages\ListAgentConversations;
use App\Filament\Resources\AgentConversationResource\Pages\ViewAgentConversation;
use Filament\Tables\Filters\BaseFilter;
use App\Filament\Resources\AgentConversationResource\Pages;
use App\Filament\Resources\Concerns\ReadOnlyResource;
use App\Models\Agent;
use App\Models\AgentConversation;
use App\Services\Agents\AgentCalls;
use App\Support\Countries;
use Filament\Forms;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Calls (read only): every call with an agent, from an owner testing it, a
 * website's call button or a link page, with the whole conversation, the
 * summary, the customer's details, how it ended, and what it took: the plan
 * credits held when it started and given back when it ended, and the tokens
 * the voice model counted (App\Services\Agents\AgentCalls).
 */
class AgentConversationResource extends Resource
{
    use ReadOnlyResource;

    protected static ?string $model = AgentConversation::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-phone';

    protected static string | \UnitEnum | null $navigationGroup = 'Agents';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Calls';

    protected static ?string $modelLabel = 'call';

    protected static ?string $pluralModelLabel = 'calls';

    protected static ?string $slug = 'agent-calls';

    public const CHANNELS = [
        'widget' => 'Website button',
        'link' => 'Link page',
        'test' => 'Owner\'s test',
        // A written chat (AgentChats), from a website's button or the link page.
        'chat' => 'Chat (website)',
        'chat_link' => 'Chat (link page)',
    ];

    public const STATUSES = [
        \App\Services\Agents\AgentChats::OPEN => 'Chat open',
        AgentConversation::LIVE => 'Live',
        AgentConversation::ENDED => 'Ended',
        AgentConversation::FAILED => 'Failed',
    ];

    /** The Status badges: a live call whose browser stopped reporting is "gone quiet" until it is settled. */
    public const STATUS_BADGES = [
        'live' => 'Live now',
        'quiet' => 'Live, gone quiet',
        \App\Services\Agents\AgentChats::OPEN => 'Chat open',
        AgentConversation::ENDED => 'Ended',
        AgentConversation::FAILED => 'Failed',
    ];

    /** The outcomes App\Jobs\SummarizeAgentCall picks after a call. */
    public const OUTCOMES = [
        'answered' => 'Answered',
        'callback' => 'Wants a call back',
        'booked' => 'Booked',
        'complaint' => 'Complaint',
        'other' => 'Other',
    ];

    /** Why calls end: the call screen, the agent's end_call tool, and AgentCalls::settleStale(). */
    public const END_REASONS = [
        'done' => 'Finished',
        'caller_left' => 'Caller hung up',
        'left' => 'Caller left',
        'limit' => 'Time limit reached',
        'error' => 'Dropped (error)',
        'failed' => 'Could not start',
        'out_of_scope' => 'Off topic',
        'handoff' => 'Passed to a person',
        // A written chat (AgentChats::close).
        'idle' => 'Nobody wrote for a while',
        'empty' => 'Opened, never written in',
        'cost' => 'Closed: cost more than it earned',
    ];

    /** The same, said in full on a call's page. */
    public const END_REASON_HELP = [
        'done' => 'The conversation ended normally.',
        'caller_left' => 'The caller ended the call.',
        'left' => 'The caller\'s page stopped reporting (closed, or the connection dropped); the call was charged up to its last report.',
        'limit' => 'The call reached the longest it may last (the agent\'s limit, or what the owner\'s credits allowed).',
        'error' => 'The call screen hit an error and ended the call.',
        'failed' => 'The live voice could not be set up, so the call never started; nothing was charged.',
        'out_of_scope' => 'The agent ended the call: the caller wanted something outside the business.',
        'handoff' => 'The agent ended the call to pass the caller to a person at the business.',
        'idle' => 'Nobody wrote in the chat for a while, so it was closed. Its replies stay charged; nothing else was taken.',
        'empty' => 'The chat was opened and no message was ever answered. Nothing was taken from the owner\'s plan.',
        'cost' => 'The conversation had cost Listen more than its credits earn (look-ups and long messages every time), so it was closed. The visitor\'s next message starts a new chat.',
    ];

    public const PAID_BY = [
        'plan' => 'The owner\'s plan',
        'pack' => 'The owner\'s credit pack',
        'house' => 'Listen (its own agent)',
        'free_test' => 'Free test seconds',
        'free' => 'Free',
    ];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'agent:id,public_id,user_id,name,business_name,language,settings,deleted_at',
            'user:id,name,email',
            'widget:id,agent_id,slug,public_key',
        ]);
    }

    /** Calls are under each agent (Agents → an agent → Calls); their own pages stay. */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = rescue(fn (): int => self::whereLiveNow(AgentConversation::query())->count(), 0, false);

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Calls live right now';
    }

    /** Calls still on: live, and the caller's browser said so within the grace AgentCalls allows. */
    public static function whereLiveNow(Builder $query): Builder
    {
        return $query->where('agent_conversations.status', AgentConversation::LIVE)
            ->where('agent_conversations.last_seen_at', '>=', now()->subSeconds(AgentCalls::PING_GRACE_SECONDS));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(self::tableColumns())
            ->defaultSort('started_at', 'desc')
            ->filters(self::tableFilters())
            ->recordActions([
                ViewAction::make()->iconButton(),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('No calls yet')
            ->emptyStateDescription('Every call with an agent shows here: owners testing their agent, website call buttons and link pages.');
    }

    /**
     * The calls table, also under each agent (without the agent and owner columns there).
     *
     * @return array<int, Tables\Columns\Column>
     */
    public static function tableColumns(bool $withAgent = true): array
    {
        return array_values(array_filter([
            TextColumn::make('started_at')
                ->label('Started')
                ->dateTime('j M, H:i')
                ->description(fn (AgentConversation $record): ?string => $record->started_at?->diffForHumans())
                ->tooltip(fn (AgentConversation $record): ?string => $record->started_at?->toDayDateTimeString())
                ->sortable(),
            $withAgent ? AgentResource::agentColumn() : null,
            $withAgent ? TextColumn::make('user.email')
                ->label('Owner')
                ->url(fn (AgentConversation $record): ?string => AgentResource::ownerUrl($record->user_id))
                ->limit(28)
                ->tooltip(fn (AgentConversation $record): ?string => $record->user?->email)
                ->searchable()
                ->placeholder('Account deleted')
                ->toggleable(isToggledHiddenByDefault: true) : null,
            TextColumn::make('channel')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => self::CHANNELS[$state] ?? Str::headline((string) $state))
                ->color(fn (?string $state): string => self::channelColor($state))
                ->description(fn (AgentConversation $record): ?string => self::whereFrom($record))
                ->tooltip(fn (AgentConversation $record): ?string => implode(' · ', array_filter([
                    $record->country ? Countries::name($record->country) : null,
                    $record->page_url,
                ])) ?: null)
                ->searchable(['origin', 'page_url']),
            IconColumn::make('recording_bytes')
                ->label('Recording')
                ->state(fn (AgentConversation $record): bool => filled($record->recording_path))
                ->boolean()
                ->trueIcon('heroicon-o-speaker-wave')
                ->falseIcon('heroicon-o-minus')
                ->trueColor('primary')
                ->falseColor('gray')
                ->tooltip(fn (AgentConversation $record): ?string => filled($record->recording_path) ? 'Recorded: play it on the call\'s page' : null),
            TextColumn::make('country')
                ->formatStateUsing(fn (?string $state): string => Countries::label($state))
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('status')
                ->state(fn (AgentConversation $record): string => self::statusKey($record))
                ->badge()
                ->formatStateUsing(fn (string $state): string => self::STATUS_BADGES[$state] ?? Str::headline($state))
                ->color(fn (string $state): string => self::statusColor($state))
                ->description(fn (AgentConversation $record): ?string => self::endReasonLabel($record->end_reason))
                ->tooltip(fn (AgentConversation $record): ?string => self::END_REASON_HELP[$record->end_reason] ?? null)
                ->sortable(),
            TextColumn::make('duration_seconds')
                ->label('Length')
                // A written chat has replies, not minutes.
                ->formatStateUsing(fn ($state, AgentConversation $record): string => self::isChat($record)
                    ? AgentResource::plural(self::replies($record), 'reply', 'replies')
                    : AgentResource::clock($record->isLive() ? self::secondsSoFar($record) : (int) $state))
                ->description(fn (AgentConversation $record): string => match (true) {
                    $record->isLive() => 'so far · '.($record->free ? 'free' : number_format((int) ($record->reservation['credits'] ?? 0)).' held'),
                    $record->free => 'free',
                    default => number_format((int) $record->credits).' credits',
                })
                ->tooltip(fn (AgentConversation $record): string => self::isChat($record)
                    ? ($record->free ? 'Free ('.(self::PAID_BY[self::paidBy($record)] ?? 'free').')' : number_format((int) ($record->reservation['rate'] ?? 0)).' credits a reply')
                        .($record->cost_usd !== null ? ' · cost us $'.number_format((float) $record->cost_usd, 4) : '')
                    : 'Billed '.number_format((int) $record->billed_seconds).' s'
                        .($record->free ? ', free ('.(self::PAID_BY[self::paidBy($record)] ?? 'free').')' : ', '.number_format((int) $record->credits).' credits'))
                ->sortable(),
            TextColumn::make('credits')
                ->formatStateUsing(fn ($state): string => number_format((int) $state))
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('outcome')
                // A word rather than a placeholder, so a customer's details still show under it.
                ->state(fn (AgentConversation $record): string => $record->outcome ?: ($record->isLive() ? 'pending' : 'none'))
                ->badge()
                ->formatStateUsing(fn (?string $state): string => match ($state) {
                    'none' => 'No summary',
                    'pending' => 'After the call',
                    default => self::OUTCOMES[$state] ?? Str::headline((string) $state),
                })
                ->color(fn (?string $state): string => self::outcomeColor($state))
                ->description(fn (AgentConversation $record): ?HtmlString => self::leadLine($record, short: true))
                ->tooltip(fn (AgentConversation $record): ?string => self::leadTooltip($record))
                ->searchable(['summary'])
                ->toggleable(),
            TextColumn::make('page_url')
                ->label('Page')
                ->limit(40)
                ->url(fn (AgentConversation $record): ?string => $record->page_url ?: null, true)
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('billed_seconds')
                ->label('Billed seconds')
                ->numeric()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            IconColumn::make('free')
                ->label('Free')
                ->boolean()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('cost_usd')
                ->label('Cost to us')
                ->formatStateUsing(fn ($state): string => '$'.number_format((float) $state, 4))
                ->placeholder('—')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('turns')
                ->label('Turns')
                ->state(fn (AgentConversation $record): int => count((array) $record->transcript))
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('public_id')
                ->label('Call id')
                ->fontFamily('mono')
                ->copyable()
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
        ]));
    }

    /**
     * The calls filters, also under each agent (without the agent filter there).
     *
     * @return array<int, BaseFilter>
     */
    public static function tableFilters(bool $withAgent = true): array
    {
        return array_values(array_filter([
            SelectFilter::make('channel')
                ->options(self::CHANNELS),
            SelectFilter::make('status')
                ->options(self::STATUSES),
            TernaryFilter::make('free')
                ->label('Free calls')
                ->placeholder('Free or charged')
                ->trueLabel('Only free calls')
                ->falseLabel('Only charged calls'),
            $withAgent ? SelectFilter::make('agent_id')
                ->label('Agent')
                ->options(fn (): array => self::agentOptions())
                ->searchable() : null,
            SelectFilter::make('outcome')
                ->options(self::OUTCOMES),
            TernaryFilter::make('lead')
                ->label('Customer details')
                ->placeholder('With or without')
                ->trueLabel('Left their details')
                ->falseLabel('Left no details')
                ->queries(
                    true: fn (Builder $query): Builder => $query->whereNotNull('agent_conversations.lead'),
                    false: fn (Builder $query): Builder => $query->whereNull('agent_conversations.lead'),
                    blank: fn (Builder $query): Builder => $query,
                ),
            Filter::make('started_at')
                ->form([
                    DatePicker::make('from')->label('Started from'),
                    DatePicker::make('until')->label('Started until'),
                ])
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('agent_conversations.started_at', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('agent_conversations.started_at', '<=', $date)))
                ->indicateUsing(fn (array $data): array => array_values(array_filter([
                    ($data['from'] ?? null) ? 'Started from '.Carbon::parse($data['from'])->toFormattedDateString() : null,
                    ($data['until'] ?? null) ? 'Started until '.Carbon::parse($data['until'])->toFormattedDateString() : null,
                ]))),
        ]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (AgentConversation $record): string => self::isChat($record) ? 'Chat' : 'Call')
                ->columns(4)
                ->schema([
                    TextEntry::make('agent.name')->label('Agent')
                        ->weight(FontWeight::SemiBold)
                        ->formatStateUsing(fn (?string $state): ?HtmlString => AgentResource::isolate($state))
                        ->url(fn (AgentConversation $record): ?string => $record->agent ? AgentResource::getUrl('view', ['record' => $record->agent]) : null)
                        ->helperText(fn (AgentConversation $record): ?HtmlString => $record->agent ? new HtmlString(implode(' · ', array_filter([
                            filled($record->agent->business_name) ? '<bdi>'.e((string) $record->agent->business_name).'</bdi>' : null,
                            AgentResource::isHouse($record->agent) ? 'Listen\'s own agent' : null,
                            $record->agent->trashed() ? 'deleted by the owner' : null,
                        ]))) : null)
                        ->placeholder('Agent removed'),
                    TextEntry::make('user.email')->label('Owner')
                        ->url(fn (AgentConversation $record): ?string => AgentResource::ownerUrl($record->user_id))
                        ->helperText(fn (AgentConversation $record): ?string => $record->user?->name)
                        ->placeholder('Account deleted'),
                    TextEntry::make('channel')->label('Where')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => self::CHANNELS[$state] ?? Str::headline((string) $state))
                        ->color(fn (?string $state): string => self::channelColor($state)),
                    TextEntry::make('status')
                        ->state(fn (AgentConversation $record): string => self::statusKey($record))
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => self::STATUS_BADGES[$state] ?? Str::headline($state))
                        ->color(fn (string $state): string => self::statusColor($state))
                        ->helperText(fn (AgentConversation $record): ?string => self::statusKey($record) === 'quiet'
                            ? 'The caller\'s browser stopped reporting. It is settled as "left" at the time it last reported.'
                            : null),
                    TextEntry::make('end_reason')->label('How it ended')
                        ->formatStateUsing(fn (?string $state): ?string => self::endReasonLabel($state))
                        ->helperText(fn (AgentConversation $record): ?string => self::END_REASON_HELP[$record->end_reason] ?? null)
                        ->placeholder(fn (AgentConversation $record): string => $record->isLive() ? 'Still on' : '—'),
                    TextEntry::make('outcome')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => self::OUTCOMES[$state] ?? Str::headline((string) $state))
                        ->color(fn (?string $state): string => self::outcomeColor($state))
                        ->placeholder('Not summarised (yet)'),
                    TextEntry::make('free')->label('Paid by')
                        ->state(fn (AgentConversation $record): string => self::paidBy($record))
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => self::PAID_BY[$state] ?? Str::headline($state))
                        ->color(fn (string $state): string => in_array($state, ['plan', 'pack'], true) ? 'primary' : 'gray'),
                    TextEntry::make('country')
                        ->formatStateUsing(fn (?string $state): string => Countries::label($state))
                        ->placeholder('Not known'),
                    TextEntry::make('started_at')->label('Started')->dateTime(),
                    TextEntry::make('ended_at')->label('Ended')->dateTime()
                        ->placeholder(fn (AgentConversation $record): string => $record->isLive() ? 'Still on' : '—'),
                    TextEntry::make('last_seen_at')->label('Browser last reported')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('duration_seconds')->label('Length')
                        ->visible(fn (AgentConversation $record): bool => ! self::isChat($record))
                        ->formatStateUsing(fn ($state): string => AgentResource::clock((int) $state).' ('.number_format((int) $state).' s)')
                        ->helperText(fn (AgentConversation $record): string => 'Billed: '.number_format((int) $record->billed_seconds).' s'),
                    TextEntry::make('replies')->label('Replies')
                        ->visible(fn (AgentConversation $record): bool => self::isChat($record))
                        ->state(fn (AgentConversation $record): string => number_format(self::replies($record)).' of '.number_format((int) ($record->reservation['max'] ?? $record->reservation['replies'] ?? 0)).' it may have')
                        ->helperText(fn (AgentConversation $record): ?string => ($span = (int) ($record->usage['seconds'] ?? 0)) > 0 ? 'First message to last: '.AgentResource::clock($span) : null),
                    TextEntry::make('origin')->label('Website')
                        ->url(fn (AgentConversation $record): ?string => self::safeUrl($record->origin), true)
                        ->placeholder('—'),
                    TextEntry::make('page_url')->label('Page')
                        ->url(fn (AgentConversation $record): ?string => self::safeUrl($record->page_url), true)
                        ->placeholder('—')
                        ->columnSpan(2),
                    TextEntry::make('visitor_hash')->label(fn (AgentConversation $record): string => self::isChat($record) ? 'Visitor (anonymous id)' : 'Caller (anonymous id)')
                        ->formatStateUsing(fn (?string $state): string => Str::limit((string) $state, 12, '…'))
                        ->helperText(fn (AgentConversation $record): ?string => self::callsFromCaller($record))
                        ->fontFamily('mono')
                        ->placeholder('—'),
                    // Who called, when the website said who is signed in (CallerIdentity).
                    TextEntry::make('caller_email')->label('Signed in as')
                        ->state(fn (AgentConversation $record): ?string => is_array($record->caller) ? ($record->caller['email'] ?? null) : null)
                        ->helperText(fn (AgentConversation $record): ?string => is_array($record->caller) ? ($record->caller['name'] ?? null) : null)
                        ->copyable()
                        ->placeholder('Not signed in, or the website did not say'),
                    TextEntry::make('widget.slug')->label('Call button / link page')
                        ->state(fn (AgentConversation $record): ?string => $record->widget?->slug)
                        ->helperText(fn (AgentConversation $record): ?string => $record->widget ? 'Key '.$record->widget->public_key : null)
                        ->placeholder(fn (AgentConversation $record): string => $record->channel === 'test' ? 'The owner\'s test screen' : '—')
                        ->columnSpan(2),
                    TextEntry::make('public_id')->label(fn (AgentConversation $record): string => self::isChat($record) ? 'Chat id' : 'Call id')->copyable()->fontFamily('mono'),
                    TextEntry::make('id')->label('Database id'),
                ]),

            Section::make('Summary')
                ->description(fn (AgentConversation $record): string => self::isChat($record) ? 'Written after the chat closed, for the owner\'s list.' : 'Written after the call, for the owner\'s list of calls.')
                ->visible(fn (AgentConversation $record): bool => filled($record->summary))
                ->schema([
                    ViewEntry::make('summary')->hiddenLabel()
                        ->view('filament.agents.paragraph'),
                ]),

            Section::make('Customer details')
                ->description(fn (AgentConversation $record): string => 'What the '.(self::isChat($record) ? 'customer' : 'caller').' left so the business can call or write back. They are also in Customers.')
                ->visible(fn (AgentConversation $record): bool => ! empty($record->lead))
                ->columns(4)
                ->schema([
                    TextEntry::make('lead.name')->label('Name')->placeholder('—')
                        ->formatStateUsing(fn ($state): ?HtmlString => AgentResource::isolate(is_scalar($state) ? (string) $state : null)),
                    TextEntry::make('lead.phone')->label('Phone')->copyable()->placeholder('—'),
                    TextEntry::make('lead.email')->label('Email')->copyable()->placeholder('—'),
                    TextEntry::make('lead.note')->label('What they want')->placeholder('—')
                        ->formatStateUsing(fn ($state): ?HtmlString => AgentResource::isolate(is_scalar($state) ? (string) $state : null)),
                ]),

            Section::make('Recording')
                ->description('The call as the caller\'s browser heard it: the caller and the agent together. Kept '.CallRecordings::KEEP_DAYS.' days.')
                ->visible(fn (AgentConversation $record): bool => ! self::isChat($record))
                ->schema([
                    ViewEntry::make('recording_path')->hiddenLabel()
                        ->view('filament.agents.recording'),
                ]),

            Section::make('Conversation')
                ->description(fn (AgentConversation $record): string => self::turnsLine($record))
                ->schema([
                    ViewEntry::make('transcript')->hiddenLabel()
                        ->view('filament.agents.transcript'),
                ]),

            Section::make('Credits: held and settled')
                ->visible(fn (AgentConversation $record): bool => ! self::isChat($record))
                ->description(fn (): string => 'When a call starts, the owner\'s plan holds credits for its longest length; when it ends, the real length is charged at '
                    .number_format(AgentCalls::creditsPerMinute()).' credits a minute and the rest is given back.')
                ->columns(4)
                ->schema([
                    TextEntry::make('hold')->label('Held when it started')
                        ->state(fn (AgentConversation $record): ?string => self::holdLine($record)[0] ?? null)
                        ->helperText(fn (AgentConversation $record): ?string => self::holdLine($record)[1] ?? null)
                        ->placeholder('Nothing recorded'),
                    TextEntry::make('hold_from')->label('Held from')
                        ->state(fn (AgentConversation $record): string => self::PAID_BY[self::paidBy($record)] ?? '—'),
                    TextEntry::make('period_ym')->label('Plan month')
                        ->state(fn (AgentConversation $record): ?string => $record->period_ym ?: ($record->reservation['period'] ?? null))
                        ->placeholder('—'),
                    TextEntry::make('settled_at')->label('Settled')
                        ->dateTime()
                        ->placeholder('Not yet: the call is still on'),
                    TextEntry::make('credits')->label('Charged')
                        ->formatStateUsing(fn ($state): string => number_format((int) $state).' credits')
                        ->helperText(fn (AgentConversation $record): string => 'For '.number_format((int) $record->billed_seconds).' billed seconds'),
                    TextEntry::make('given_back')->label('Given back')
                        ->state(fn (AgentConversation $record): ?string => self::givenBackLine($record))
                        ->placeholder('—'),
                ]),

            Section::make('Credits: what the chat used')
                ->visible(fn (AgentConversation $record): bool => self::isChat($record))
                ->description('Nothing is taken when a chat opens. Each reply\'s credits are taken from the owner\'s plan just before the model is asked, and given back if no answer comes. Under each reply above: what it used.')
                ->columns(4)
                ->schema([
                    TextEntry::make('chat_rate')->label('Credits a reply')
                        ->state(fn (AgentConversation $record): string => $record->free ? 'Free' : number_format((int) ($record->reservation['rate'] ?? 0)).' credits')
                        ->helperText(fn (AgentConversation $record): ?string => $record->free ? (self::PAID_BY[self::paidBy($record)] ?? null) : 'Fixed for this conversation when it opened'),
                    TextEntry::make('chat_charged')->label('Charged')
                        ->state(fn (AgentConversation $record): string => number_format((int) $record->credits).' credits')
                        ->helperText(fn (AgentConversation $record): string => AgentResource::plural(self::replies($record), 'reply', 'replies').($record->free ? '' : ' × '.number_format((int) ($record->reservation['rate'] ?? 0)))),
                    TextEntry::make('chat_taken')->label('Taken from the plan')
                        ->state(fn (AgentConversation $record): string => $record->free ? 'Nothing' : number_format((int) ($record->reservation['credits'] ?? 0)).' credits')
                        ->helperText(fn (AgentConversation $record): ?string => self::chatBackLine($record)),
                    TextEntry::make('chat_from')->label('Taken from')
                        ->state(fn (AgentConversation $record): string => self::PAID_BY[self::paidBy($record)] ?? '—')
                        ->helperText(fn (AgentConversation $record): ?string => ($month = $record->period_ym ?: ($record->reservation['period'] ?? null)) ? 'Plan month '.$month : null),
                    TextEntry::make('chat_settled')->label('Settled')
                        ->state(fn (AgentConversation $record): ?string => $record->settled_at?->toDayDateTimeString())
                        ->placeholder('Not yet: the chat is still open'),
                    TextEntry::make('chat_worth')->label('Earned, at the least')
                        ->state(fn (AgentConversation $record): ?string => ! $record->free && ($worth = \App\Support\Agents\AgentCost::dollarsPerCredit()) !== null
                            ? '$'.number_format((int) $record->credits * $worth, 5) : null)
                        ->helperText('What its credits are worth on the plan whose credits cost least')
                        ->placeholder('—'),
                    TextEntry::make('chat_margin')->label('Kept')
                        ->state(fn (AgentConversation $record): ?string => self::chatMargin($record))
                        ->helperText('Earned less what it cost us')
                        ->placeholder('—'),
                ]),

            Section::make('Tokens and cost')
                ->description(fn (AgentConversation $record): string => self::isChat($record)
                    ? 'What the text model counted, added up by our server from each of its answers (every round, its thinking, the answers that never came, the summary), and what that cost us at the model\'s prices.'
                    : 'What the voice model counted on this call, as the caller\'s browser reported it at the end, and what that cost us at the configured token prices.')
                ->columns(4)
                ->schema([
                    TextEntry::make('tokens_in')->label('Tokens in')
                        ->state(fn (AgentConversation $record): ?string => self::tokens($record, 'in')[0] ?? null)
                        ->helperText(fn (AgentConversation $record): ?string => self::tokens($record, 'in')[1] ?? null)
                        ->placeholder('Not reported'),
                    TextEntry::make('tokens_out')->label('Tokens out')
                        ->state(fn (AgentConversation $record): ?string => self::tokens($record, 'out')[0] ?? null)
                        ->helperText(fn (AgentConversation $record): ?string => self::tokens($record, 'out')[1] ?? null)
                        ->placeholder('Not reported'),
                    TextEntry::make('tokens_thinking')->label('Thinking tokens')
                        ->state(fn (AgentConversation $record): ?string => is_array($record->usage) ? number_format((int) ($record->usage['thoughtsTokenCount'] ?? $record->usage['thoughts'] ?? 0)) : null)
                        ->helperText(fn (AgentConversation $record): ?string => self::isChat($record) ? 'Counted in tokens out (billed as written)' : null)
                        ->placeholder('Not reported'),
                    TextEntry::make('cost_usd')->label('Cost to us')
                        ->formatStateUsing(fn ($state): string => '$'.number_format((float) $state, 5))
                        ->helperText(fn (AgentConversation $record): ?string => match (true) {
                            $record->cost_usd === null => null,
                            ! empty($record->usage['chat']) => (int) ($record->usage['replies'] ?? 0) > 0
                                ? '$'.number_format((float) $record->cost_usd / (int) $record->usage['replies'], 5).' a reply'
                                : null,
                            (int) $record->duration_seconds > 0 => '$'.number_format((float) $record->cost_usd / ((int) $record->duration_seconds / 60), 4).' a minute',
                            default => null,
                        })
                        ->placeholder(fn (AgentConversation $record): string => self::isChat($record) ? 'Nothing yet' : 'Not reported'),
                    TextEntry::make('chat_rounds')->label('Model calls')
                        ->visible(fn (AgentConversation $record): bool => self::isChat($record))
                        ->state(fn (AgentConversation $record): string => number_format((int) ($record->usage['rounds'] ?? 0)))
                        ->helperText('One a reply, more when it used its tools'),
                    TextEntry::make('chat_cached')->label('Read from the cache')
                        ->visible(fn (AgentConversation $record): bool => self::isChat($record))
                        ->state(fn (AgentConversation $record): string => number_format((int) ($record->usage['cached'] ?? 0)).' tokens')
                        ->helperText('Part of tokens in, at a tenth of the price'),
                    TextEntry::make('chat_wasted')->label('Answers that never came')
                        ->visible(fn (AgentConversation $record): bool => self::isChat($record))
                        ->state(fn (AgentConversation $record): string => number_format((int) ($record->usage['wasted']['answers'] ?? 0)))
                        ->helperText(fn (AgentConversation $record): string => (int) ($record->usage['wasted']['answers'] ?? 0) > 0
                            ? number_format((int) ($record->usage['wasted']['in'] ?? 0) + (int) ($record->usage['wasted']['out'] ?? 0)).' tokens, $'.number_format((float) ($record->usage['wasted']['cost'] ?? 0), 5).' (in the cost, never charged)'
                            : 'Not charged; their cost is in the total'),
                    TextEntry::make('chat_summary')->label('The summary')
                        ->visible(fn (AgentConversation $record): bool => self::isChat($record))
                        ->state(fn (AgentConversation $record): ?string => isset($record->usage['summary']) ? '$'.number_format((float) ($record->usage['summary']['cost'] ?? 0), 5) : null)
                        ->helperText(fn (AgentConversation $record): ?string => isset($record->usage['summary'])
                            ? number_format((int) ($record->usage['summary']['in'] ?? 0)).' tokens in, '.number_format((int) ($record->usage['summary']['out'] ?? 0)).' out (in the cost)' : null)
                        ->placeholder('Written once the chat has closed'),
                ]),

            Section::make('Everything stored')
                ->description('The row as it is saved, apart from the conversation (above) and the secret key of the caller\'s browser.')
                ->collapsed()
                ->schema([
                    ViewEntry::make('raw')->hiddenLabel()
                        ->view('filament.agents.raw-json'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentConversations::route('/'),
            'view' => ViewAgentConversation::route('/{record}'),
        ];
    }

    /** How long a call still on has lasted: up to now, or up to its last report once it went quiet; never past its hold. */
    public static function secondsSoFar(AgentConversation $call): int
    {
        $until = self::statusKey($call) === 'live' ? now() : ($call->last_seen_at ?? $call->started_at);
        $seconds = $call->started_at ? max(0, (int) $call->started_at->diffInSeconds($until)) : 0;
        $held = (int) ($call->reservation['seconds'] ?? 0);

        return $held > 0 ? min($seconds, $held) : $seconds;
    }

    /** live, quiet (live, but the browser stopped reporting), ended or failed. */
    public static function statusKey(AgentConversation $call): string
    {
        if (! $call->isLive()) {
            return (string) $call->status;
        }

        return $call->last_seen_at !== null && $call->last_seen_at->gte(now()->subSeconds(AgentCalls::PING_GRACE_SECONDS)) ? 'live' : 'quiet';
    }

    public static function statusColor(?string $state): string
    {
        return match ($state) {
            'live' => 'success',
            'quiet' => 'warning',
            AgentConversation::FAILED => 'danger',
            default => 'gray',
        };
    }

    public static function channelColor(?string $state): string
    {
        return match ($state) {
            'widget' => 'info',
            'link' => 'primary',
            'chat', 'chat_link' => 'success',
            default => 'gray',
        };
    }

    public static function outcomeColor(?string $state): string
    {
        return match ($state) {
            'answered' => 'success',
            'booked' => 'info',
            'callback' => 'warning',
            'complaint' => 'danger',
            default => 'gray',
        };
    }

    public static function endReasonLabel(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : (self::END_REASONS[$reason] ?? Str::ucfirst(str_replace('_', ' ', $reason)));
    }

    /** Who paid: plan, pack, house (Listen's own agent), free_test or free. */
    public static function paidBy(AgentConversation $call): string
    {
        if (! $call->free) {
            return ! empty($call->reservation['billed_to_pack']) ? 'pack' : 'plan';
        }
        if ($call->agent && AgentResource::isHouse($call->agent)) {
            return 'house';
        }

        return $call->channel === 'test' ? 'free_test' : 'free';
    }

    /** "🇪🇬 cairosmiles.example": the caller's country, and the website a call button was on. */
    public static function whereFrom(AgentConversation $call): ?string
    {
        $host = $call->channel === 'widget' && filled($call->origin) ? (parse_url((string) $call->origin, PHP_URL_HOST) ?: $call->origin) : null;
        $place = $host ?? ($call->country ? Countries::name($call->country) : null);
        $line = trim(implode(' ', array_filter([self::flag($call->country), $place ? Str::limit((string) $place, 26) : null])));

        return $line !== '' ? $line : null;
    }

    /** The flag of a two-letter country code, as the admin's country columns show it. */
    public static function flag(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));

        return preg_match('/^[A-Z]{2}$/', $code) === 1
            ? mb_chr(0x1F1E6 + ord($code[0]) - 65).mb_chr(0x1F1E6 + ord($code[1]) - 65)
            : null;
    }

    /**
     * "Omar · +201001234567": who left their details on the call. The name is
     * kept apart from the number, so an Arabic name never turns the number around.
     */
    public static function leadLine(AgentConversation $call, bool $short = false): ?HtmlString
    {
        $lead = (array) ($call->lead ?? []);
        $name = is_scalar($lead['name'] ?? null) ? trim((string) $lead['name']) : '';
        $reach = is_scalar($lead['phone'] ?? null) && trim((string) $lead['phone']) !== '' ? $lead['phone'] : ($lead['email'] ?? null);
        $reach = is_scalar($reach) ? trim((string) $reach) : '';
        $parts = array_filter([
            $name !== '' ? '<bdi>'.e(Str::limit($name, 24)).'</bdi>' : null,
            // In a list, the name is enough when there is one (the tooltip has the rest).
            $reach !== '' && ! ($short && $name !== '') ? '<span dir="ltr">'.e(Str::limit($reach, 28)).'</span>' : null,
        ]);

        return $parts === [] ? null : new HtmlString(implode(' · ', $parts));
    }

    /** "Omar · +201001234567 · Teeth cleaning": everything the caller left, for a tooltip. */
    public static function leadTooltip(AgentConversation $call): ?string
    {
        $lead = array_filter(array_map(
            fn ($value): string => is_scalar($value) ? trim((string) $value) : '',
            array_intersect_key((array) ($call->lead ?? []), array_flip(['name', 'phone', 'email', 'note'])),
        ), fn (string $value): bool => $value !== '');

        return $lead === [] ? null : 'Left their details: '.implode(' · ', $lead);
    }

    /** "8 turns: 4 from the agent, 4 from the caller. Times are from the start of the call." */
    public static function turnsLine(AgentConversation $call): string
    {
        $turns = array_values(array_filter((array) $call->transcript, 'is_array'));
        if (self::isChat($call)) {
            return $turns === [] ? 'Nothing was written in this chat.'
                : AgentResource::plural(self::replies($call), 'reply', 'replies').' to '.AgentResource::plural(count(array_filter($turns, fn (array $t): bool => ($t['role'] ?? '') !== 'agent')), 'message').', in order. Times are from when the chat opened. Under each reply: its credits, the tokens the model read and wrote, what it cost us, and how long it took.';
        }
        if ($turns === []) {
            return 'Nothing was written down for this call.';
        }
        $agent = count(array_filter($turns, fn (array $t): bool => ($t['role'] ?? '') === 'agent'));

        return count($turns).' turns: '.$agent.' from the agent, '.(count($turns) - $agent).' from the caller, in order. Times are from the start of the call.';
    }

    /**
     * What the call held when it started: ["9,000 credits", "for up to 10:00 (600 s)"],
     * or ["No credits", "a free call of up to 3:00 (180 s)"].
     *
     * @return array{0: string, 1: string}|null
     */
    public static function holdLine(AgentConversation $call): ?array
    {
        $hold = (array) ($call->reservation ?? []);
        if ($hold === []) {
            return null;
        }
        $seconds = (int) ($hold['seconds'] ?? 0);
        $length = AgentResource::clock($seconds).' ('.number_format($seconds).' s)';

        return $call->free || ! empty($hold['free'])
            ? ['No credits', 'a free call of up to '.$length]
            : [number_format((int) ($hold['credits'] ?? 0)).' credits', 'for up to '.$length];
    }

    /** What the end of the call gave back to the owner's plan. */
    public static function givenBackLine(AgentConversation $call): ?string
    {
        $hold = (array) ($call->reservation ?? []);
        if ($call->settled_at === null || $hold === []) {
            return null;
        }
        if ($call->free || ! empty($hold['free'])) {
            return 'Nothing was held';
        }
        $credits = max(0, (int) ($hold['credits'] ?? 0) - (int) $call->credits);
        $seconds = max(0, (int) ($hold['seconds'] ?? 0) - (int) $call->billed_seconds);

        return number_format($credits).' credits ('.number_format($seconds).' s unused)';
    }

    /**
     * ["12,345", "12,000 sound · 345 text"], from the usage the browser reported.
     *
     * @param  'in'|'out'  $direction
     * @return array{0: string, 1: ?string}|null
     */
    public static function tokens(AgentConversation $call, string $direction): ?array
    {
        $usage = $call->usage;
        if (! is_array($usage)) {
            return null;
        }
        // A written chat keeps its own counts (AgentChats): the text model's, summed over its replies.
        if (! empty($usage['chat'])) {
            $replies = (int) ($usage['replies'] ?? 0);

            return [number_format((int) ($usage[$direction] ?? 0)), $replies > 0 ? 'text · '.AgentResource::plural($replies, 'reply', 'replies') : null];
        }
        $total = (int) ($usage[$direction === 'in' ? 'promptTokenCount' : 'responseTokenCount'] ?? 0);
        $parts = [];
        foreach ((array) ($usage[$direction === 'in' ? 'promptTokensDetails' : 'responseTokensDetails'] ?? []) as $key => $detail) {
            // A list of {modality, tokenCount}; an object of modality => count is read too.
            $modality = strtoupper((string) (is_array($detail) ? ($detail['modality'] ?? 'TEXT') : $key));
            $count = (int) (is_array($detail) ? ($detail['tokenCount'] ?? 0) : $detail);
            $parts[$modality] = ($parts[$modality] ?? 0) + $count;
        }
        $names = ['AUDIO' => 'sound', 'TEXT' => 'text', 'IMAGE' => 'image', 'VIDEO' => 'video'];
        $detail = implode(' · ', array_map(
            fn (string $modality, int $count): string => number_format($count).' '.($names[$modality] ?? strtolower($modality)),
            array_keys($parts),
            $parts,
        ));

        return [number_format($total), $detail !== '' ? $detail : null];
    }

    /** "3 calls from this caller in all (2 with this agent)", for spotting the same caller again. */
    public static function callsFromCaller(AgentConversation $call): ?string
    {
        if (blank($call->visitor_hash)) {
            return null;
        }
        $all = AgentConversation::query()->where('visitor_hash', $call->visitor_hash)->count();
        $here = AgentConversation::query()->where('visitor_hash', $call->visitor_hash)->where('agent_id', $call->agent_id)->count();

        return $all <= 1 ? 'Their first call' : number_format($all).' calls from this caller ('.number_format($here).' with this agent)';
    }

    /** @return array<int, string> every agent, deleted ones too, as "Sara · Cairo Smiles" */
    public static function agentOptions(): array
    {
        return Agent::withTrashed()
            ->orderBy('name')
            ->get(['id', 'name', 'business_name'])
            ->mapWithKeys(fn (Agent $agent): array => [$agent->id => $agent->name.' · '.$agent->business_name])
            ->all();
    }

    /** "3 on websites · 1 on link pages · 2 owner tests", for a channel => count list. */
    public static function channelBreakdown(array $byChannel): ?string
    {
        $parts = [];
        foreach (['widget' => 'on websites', 'link' => 'on link pages', 'test' => null] as $channel => $where) {
            $count = (int) ($byChannel[$channel] ?? 0);
            if ($count > 0) {
                $parts[] = $where !== null ? number_format($count).' '.$where : AgentResource::plural($count, 'owner test');
            }
        }
        $chats = (int) ($byChannel['chat'] ?? 0) + (int) ($byChannel['chat_link'] ?? 0);
        if ($chats > 0) {
            $parts[] = AgentResource::plural($chats, 'written chat');
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    public static function isChat(AgentConversation $call): bool
    {
        return \App\Services\Agents\AgentChats::isChat($call);
    }

    /** Replies a written chat has had. */
    public static function replies(AgentConversation $call): int
    {
        return (int) ($call->usage['replies'] ?? 0);
    }

    /** Under "Taken from the plan": what went back, if anything did. */
    public static function chatBackLine(AgentConversation $call): ?string
    {
        if ($call->free) {
            return null;
        }
        $taken = (int) ($call->reservation['credits'] ?? 0);
        $back = max(0, $taken - (int) $call->credits);

        return match (true) {
            $call->settled_at === null => $back > 0 ? number_format($back).' for a reply being written now' : 'All of it used by its replies',
            $back > 0 => number_format($back).' given back when it closed',
            default => 'All of it used by its replies',
        };
    }

    /** "$0.0123 (62%)": what a paid chat earned at the least, less what it cost us. */
    public static function chatMargin(AgentConversation $call): ?string
    {
        $worth = \App\Support\Agents\AgentCost::dollarsPerCredit();
        if ($call->free || $worth === null || (int) $call->credits < 1) {
            return null;
        }
        $earned = (int) $call->credits * $worth;
        $kept = $earned - (float) $call->cost_usd;

        return ($kept < 0 ? '-$' : '$').number_format(abs($kept), 5).' ('.(int) round($kept / $earned * 100).'%)';
    }

    /** Only web addresses become links. */
    public static function safeUrl(?string $url): ?string
    {
        return is_string($url) && preg_match('#^https?://#i', $url) ? $url : null;
    }
}
