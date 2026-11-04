<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AgentConversationResource\Pages;
use App\Filament\Resources\Concerns\ReadOnlyResource;
use App\Models\Agent;
use App\Models\AgentConversation;
use App\Services\Agents\AgentCalls;
use App\Support\Countries;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
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

    protected static ?string $navigationIcon = 'heroicon-o-phone';

    protected static ?string $navigationGroup = 'Agents';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Calls';

    protected static ?string $modelLabel = 'call';

    protected static ?string $pluralModelLabel = 'calls';

    protected static ?string $slug = 'agent-calls';

    public const CHANNELS = [
        'widget' => 'Website button',
        'link' => 'Link page',
        'test' => 'Owner\'s test',
    ];

    public const STATUSES = [
        AgentConversation::LIVE => 'Live',
        AgentConversation::ENDED => 'Ended',
        AgentConversation::FAILED => 'Failed',
    ];

    /** The Status badges: a live call whose browser stopped reporting is "gone quiet" until it is settled. */
    public const STATUS_BADGES = [
        'live' => 'Live now',
        'quiet' => 'Live, gone quiet',
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
            ->actions([
                Tables\Actions\ViewAction::make()->iconButton(),
            ])
            ->bulkActions([])
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
            Tables\Columns\TextColumn::make('started_at')
                ->label('Started')
                ->dateTime('j M, H:i')
                ->description(fn (AgentConversation $record): ?string => $record->started_at?->diffForHumans())
                ->tooltip(fn (AgentConversation $record): ?string => $record->started_at?->toDayDateTimeString())
                ->sortable(),
            $withAgent ? AgentResource::agentColumn() : null,
            $withAgent ? Tables\Columns\TextColumn::make('user.email')
                ->label('Owner')
                ->url(fn (AgentConversation $record): ?string => AgentResource::ownerUrl($record->user_id))
                ->limit(28)
                ->tooltip(fn (AgentConversation $record): ?string => $record->user?->email)
                ->searchable()
                ->placeholder('Account deleted')
                ->toggleable(isToggledHiddenByDefault: true) : null,
            Tables\Columns\TextColumn::make('channel')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => self::CHANNELS[$state] ?? Str::headline((string) $state))
                ->color(fn (?string $state): string => self::channelColor($state))
                ->description(fn (AgentConversation $record): ?string => self::whereFrom($record))
                ->tooltip(fn (AgentConversation $record): ?string => implode(' · ', array_filter([
                    $record->country ? Countries::name($record->country) : null,
                    $record->page_url,
                ])) ?: null)
                ->searchable(['origin', 'page_url']),
            Tables\Columns\TextColumn::make('country')
                ->formatStateUsing(fn (?string $state): string => Countries::label($state))
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('status')
                ->state(fn (AgentConversation $record): string => self::statusKey($record))
                ->badge()
                ->formatStateUsing(fn (string $state): string => self::STATUS_BADGES[$state] ?? Str::headline($state))
                ->color(fn (string $state): string => self::statusColor($state))
                ->description(fn (AgentConversation $record): ?string => self::endReasonLabel($record->end_reason))
                ->tooltip(fn (AgentConversation $record): ?string => self::END_REASON_HELP[$record->end_reason] ?? null)
                ->sortable(),
            Tables\Columns\TextColumn::make('duration_seconds')
                ->label('Length')
                ->formatStateUsing(fn ($state, AgentConversation $record): string => AgentResource::clock($record->isLive() ? self::secondsSoFar($record) : (int) $state))
                ->description(fn (AgentConversation $record): string => match (true) {
                    $record->isLive() => 'so far · '.($record->free ? 'free' : number_format((int) ($record->reservation['credits'] ?? 0)).' held'),
                    $record->free => 'free',
                    default => number_format((int) $record->credits).' credits',
                })
                ->tooltip(fn (AgentConversation $record): string => 'Billed '.number_format((int) $record->billed_seconds).' s'
                    .($record->free ? ', free ('.(self::PAID_BY[self::paidBy($record)] ?? 'free').')' : ', '.number_format((int) $record->credits).' credits'))
                ->sortable(),
            Tables\Columns\TextColumn::make('credits')
                ->formatStateUsing(fn ($state): string => number_format((int) $state))
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('outcome')
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
            Tables\Columns\TextColumn::make('page_url')
                ->label('Page')
                ->limit(40)
                ->url(fn (AgentConversation $record): ?string => $record->page_url ?: null, true)
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('billed_seconds')
                ->label('Billed seconds')
                ->numeric()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\IconColumn::make('free')
                ->label('Free')
                ->boolean()
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('cost_usd')
                ->label('Cost to us')
                ->formatStateUsing(fn ($state): string => '$'.number_format((float) $state, 4))
                ->placeholder('—')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('turns')
                ->label('Turns')
                ->state(fn (AgentConversation $record): int => count((array) $record->transcript))
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('public_id')
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
     * @return array<int, Tables\Filters\BaseFilter>
     */
    public static function tableFilters(bool $withAgent = true): array
    {
        return array_values(array_filter([
            Tables\Filters\SelectFilter::make('channel')
                ->options(self::CHANNELS),
            Tables\Filters\SelectFilter::make('status')
                ->options(self::STATUSES),
            Tables\Filters\TernaryFilter::make('free')
                ->label('Free calls')
                ->placeholder('Free or charged')
                ->trueLabel('Only free calls')
                ->falseLabel('Only charged calls'),
            $withAgent ? Tables\Filters\SelectFilter::make('agent_id')
                ->label('Agent')
                ->options(fn (): array => self::agentOptions())
                ->searchable() : null,
            Tables\Filters\SelectFilter::make('outcome')
                ->options(self::OUTCOMES),
            Tables\Filters\TernaryFilter::make('lead')
                ->label('Customer details')
                ->placeholder('With or without')
                ->trueLabel('Left their details')
                ->falseLabel('Left no details')
                ->queries(
                    true: fn (Builder $query): Builder => $query->whereNotNull('agent_conversations.lead'),
                    false: fn (Builder $query): Builder => $query->whereNull('agent_conversations.lead'),
                    blank: fn (Builder $query): Builder => $query,
                ),
            Tables\Filters\Filter::make('started_at')
                ->form([
                    Forms\Components\DatePicker::make('from')->label('Started from'),
                    Forms\Components\DatePicker::make('until')->label('Started until'),
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

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Call')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('agent.name')->label('Agent')
                        ->weight(FontWeight::SemiBold)
                        ->formatStateUsing(fn (?string $state): ?HtmlString => AgentResource::isolate($state))
                        ->url(fn (AgentConversation $record): ?string => $record->agent ? AgentResource::getUrl('view', ['record' => $record->agent]) : null)
                        ->helperText(fn (AgentConversation $record): ?HtmlString => $record->agent ? new HtmlString(implode(' · ', array_filter([
                            filled($record->agent->business_name) ? '<bdi>'.e((string) $record->agent->business_name).'</bdi>' : null,
                            AgentResource::isHouse($record->agent) ? 'Listen\'s own agent' : null,
                            $record->agent->trashed() ? 'deleted by the owner' : null,
                        ]))) : null)
                        ->placeholder('Agent removed'),
                    Infolists\Components\TextEntry::make('user.email')->label('Owner')
                        ->url(fn (AgentConversation $record): ?string => AgentResource::ownerUrl($record->user_id))
                        ->helperText(fn (AgentConversation $record): ?string => $record->user?->name)
                        ->placeholder('Account deleted'),
                    Infolists\Components\TextEntry::make('channel')->label('Where')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => self::CHANNELS[$state] ?? Str::headline((string) $state))
                        ->color(fn (?string $state): string => self::channelColor($state)),
                    Infolists\Components\TextEntry::make('status')
                        ->state(fn (AgentConversation $record): string => self::statusKey($record))
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => self::STATUS_BADGES[$state] ?? Str::headline($state))
                        ->color(fn (string $state): string => self::statusColor($state))
                        ->helperText(fn (AgentConversation $record): ?string => self::statusKey($record) === 'quiet'
                            ? 'The caller\'s browser stopped reporting. It is settled as "left" at the time it last reported.'
                            : null),
                    Infolists\Components\TextEntry::make('end_reason')->label('How it ended')
                        ->formatStateUsing(fn (?string $state): ?string => self::endReasonLabel($state))
                        ->helperText(fn (AgentConversation $record): ?string => self::END_REASON_HELP[$record->end_reason] ?? null)
                        ->placeholder(fn (AgentConversation $record): string => $record->isLive() ? 'Still on' : '—'),
                    Infolists\Components\TextEntry::make('outcome')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => self::OUTCOMES[$state] ?? Str::headline((string) $state))
                        ->color(fn (?string $state): string => self::outcomeColor($state))
                        ->placeholder('Not summarised (yet)'),
                    Infolists\Components\TextEntry::make('free')->label('Paid by')
                        ->state(fn (AgentConversation $record): string => self::paidBy($record))
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => self::PAID_BY[$state] ?? Str::headline($state))
                        ->color(fn (string $state): string => in_array($state, ['plan', 'pack'], true) ? 'primary' : 'gray'),
                    Infolists\Components\TextEntry::make('country')
                        ->formatStateUsing(fn (?string $state): string => Countries::label($state))
                        ->placeholder('Not known'),
                    Infolists\Components\TextEntry::make('started_at')->label('Started')->dateTime(),
                    Infolists\Components\TextEntry::make('ended_at')->label('Ended')->dateTime()
                        ->placeholder(fn (AgentConversation $record): string => $record->isLive() ? 'Still on' : '—'),
                    Infolists\Components\TextEntry::make('last_seen_at')->label('Browser last reported')
                        ->dateTime()
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('duration_seconds')->label('Length')
                        ->formatStateUsing(fn ($state): string => AgentResource::clock((int) $state).' ('.number_format((int) $state).' s)')
                        ->helperText(fn (AgentConversation $record): string => 'Billed: '.number_format((int) $record->billed_seconds).' s'),
                    Infolists\Components\TextEntry::make('origin')->label('Website')
                        ->url(fn (AgentConversation $record): ?string => self::safeUrl($record->origin), true)
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('page_url')->label('Page')
                        ->url(fn (AgentConversation $record): ?string => self::safeUrl($record->page_url), true)
                        ->placeholder('—')
                        ->columnSpan(2),
                    Infolists\Components\TextEntry::make('visitor_hash')->label('Caller (anonymous id)')
                        ->formatStateUsing(fn (?string $state): string => Str::limit((string) $state, 12, '…'))
                        ->helperText(fn (AgentConversation $record): ?string => self::callsFromCaller($record))
                        ->fontFamily('mono')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('widget.slug')->label('Call button / link page')
                        ->state(fn (AgentConversation $record): ?string => $record->widget?->slug)
                        ->helperText(fn (AgentConversation $record): ?string => $record->widget ? 'Key '.$record->widget->public_key : null)
                        ->placeholder(fn (AgentConversation $record): string => $record->channel === 'test' ? 'The owner\'s test screen' : '—')
                        ->columnSpan(2),
                    Infolists\Components\TextEntry::make('public_id')->label('Call id')->copyable()->fontFamily('mono'),
                    Infolists\Components\TextEntry::make('id')->label('Database id'),
                ]),

            Infolists\Components\Section::make('Summary')
                ->description('Written after the call, for the owner\'s list of calls.')
                ->visible(fn (AgentConversation $record): bool => filled($record->summary))
                ->schema([
                    Infolists\Components\ViewEntry::make('summary')->hiddenLabel()
                        ->view('filament.agents.paragraph'),
                ]),

            Infolists\Components\Section::make('Customer details')
                ->description('What the caller left so the business can call or write back. They are also in Customers.')
                ->visible(fn (AgentConversation $record): bool => ! empty($record->lead))
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('lead.name')->label('Name')->placeholder('—')
                        ->formatStateUsing(fn ($state): ?HtmlString => AgentResource::isolate(is_scalar($state) ? (string) $state : null)),
                    Infolists\Components\TextEntry::make('lead.phone')->label('Phone')->copyable()->placeholder('—'),
                    Infolists\Components\TextEntry::make('lead.email')->label('Email')->copyable()->placeholder('—'),
                    Infolists\Components\TextEntry::make('lead.note')->label('What they want')->placeholder('—')
                        ->formatStateUsing(fn ($state): ?HtmlString => AgentResource::isolate(is_scalar($state) ? (string) $state : null)),
                ]),

            Infolists\Components\Section::make('Conversation')
                ->description(fn (AgentConversation $record): string => self::turnsLine($record))
                ->schema([
                    Infolists\Components\ViewEntry::make('transcript')->hiddenLabel()
                        ->view('filament.agents.transcript'),
                ]),

            Infolists\Components\Section::make('Credits: held and settled')
                ->description(fn (): string => 'When a call starts, the owner\'s plan holds credits for its longest length; when it ends, the real length is charged at '
                    .number_format(AgentCalls::creditsPerMinute()).' credits a minute and the rest is given back.')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('hold')->label('Held when it started')
                        ->state(fn (AgentConversation $record): ?string => self::holdLine($record)[0] ?? null)
                        ->helperText(fn (AgentConversation $record): ?string => self::holdLine($record)[1] ?? null)
                        ->placeholder('Nothing recorded'),
                    Infolists\Components\TextEntry::make('hold_from')->label('Held from')
                        ->state(fn (AgentConversation $record): string => self::PAID_BY[self::paidBy($record)] ?? '—'),
                    Infolists\Components\TextEntry::make('period_ym')->label('Plan month')
                        ->state(fn (AgentConversation $record): ?string => $record->period_ym ?: ($record->reservation['period'] ?? null))
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('settled_at')->label('Settled')
                        ->dateTime()
                        ->placeholder('Not yet: the call is still on'),
                    Infolists\Components\TextEntry::make('credits')->label('Charged')
                        ->formatStateUsing(fn ($state): string => number_format((int) $state).' credits')
                        ->helperText(fn (AgentConversation $record): string => 'For '.number_format((int) $record->billed_seconds).' billed seconds'),
                    Infolists\Components\TextEntry::make('given_back')->label('Given back')
                        ->state(fn (AgentConversation $record): ?string => self::givenBackLine($record))
                        ->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Tokens and cost')
                ->description('What the voice model counted on this call, as the caller\'s browser reported it at the end, and what that cost us at the configured token prices.')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('tokens_in')->label('Tokens in')
                        ->state(fn (AgentConversation $record): ?string => self::tokens($record, 'in')[0] ?? null)
                        ->helperText(fn (AgentConversation $record): ?string => self::tokens($record, 'in')[1] ?? null)
                        ->placeholder('Not reported'),
                    Infolists\Components\TextEntry::make('tokens_out')->label('Tokens out')
                        ->state(fn (AgentConversation $record): ?string => self::tokens($record, 'out')[0] ?? null)
                        ->helperText(fn (AgentConversation $record): ?string => self::tokens($record, 'out')[1] ?? null)
                        ->placeholder('Not reported'),
                    Infolists\Components\TextEntry::make('tokens_thinking')->label('Thinking tokens')
                        ->state(fn (AgentConversation $record): ?string => is_array($record->usage) ? number_format((int) ($record->usage['thoughtsTokenCount'] ?? 0)) : null)
                        ->placeholder('Not reported'),
                    Infolists\Components\TextEntry::make('cost_usd')->label('Cost to us (estimate)')
                        ->formatStateUsing(fn ($state): string => '$'.number_format((float) $state, 5))
                        ->helperText(fn (AgentConversation $record): ?string => $record->cost_usd !== null && (int) $record->duration_seconds > 0
                            ? '$'.number_format((float) $record->cost_usd / ((int) $record->duration_seconds / 60), 4).' a minute'
                            : null)
                        ->placeholder('Not reported'),
                ]),

            Infolists\Components\Section::make('Everything stored')
                ->description('The call\'s row as it is saved, apart from the conversation (above) and the caller\'s secret call key.')
                ->collapsed()
                ->schema([
                    Infolists\Components\ViewEntry::make('raw')->hiddenLabel()
                        ->view('filament.agents.raw-json'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAgentConversations::route('/'),
            'view' => Pages\ViewAgentConversation::route('/{record}'),
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

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** Only web addresses become links. */
    public static function safeUrl(?string $url): ?string
    {
        return is_string($url) && preg_match('#^https?://#i', $url) ? $url : null;
    }
}
