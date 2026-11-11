<?php

namespace App\Filament\Resources;

use Illuminate\Auth\Access\Response as AccessResponse;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\Filter;
use Filament\Actions\ViewAction;
use Filament\Actions\BulkAction;
use Illuminate\Support\Collection;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use App\Http\Controllers\AgentReportController;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\IconEntry;
use Filament\Actions\Action;
use App\Filament\Resources\AgentResource\RelationManagers\SitesRelationManager;
use App\Filament\Resources\AgentResource\RelationManagers\ConversationsRelationManager;
use App\Filament\Resources\AgentResource\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\AgentResource\Pages\ListAgents;
use App\Filament\Resources\AgentResource\Pages\ViewAgent;
use App\Filament\Resources\AgentResource\Pages;
use App\Filament\Resources\AgentResource\RelationManagers;
use App\Filament\Resources\Concerns\ReadOnlyResource;
use App\Filament\Widgets\AgentStats;
use App\Models\Agent;
use App\Models\AgentContact;
use App\Models\AgentConversation;
use App\Models\AgentWidget;
use App\Models\SiteSetting;
use App\Models\Voice;
use App\Services\Agents\AgentCalls;
use App\Support\Agents\AgentInstructions;
use App\Support\Agents\AgentPurge;
use App\Support\Agents\HouseAgent;
use App\Support\ArabicDialects;
use App\Support\EnglishAccents;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Agents (read only): every voice agent members made, and Listen's own
 * (HouseAgent), with everything it was set up with: its business, its voice,
 * its script and the decisions taken at setup, what it knows and where it
 * learned it, its call button and link page, every website the button runs on,
 * and what its calls used. Its calls (with their recordings) and the customers it
 * saved are under it. The admin can delete any agent for good (AgentPurge), except
 * Listen's own.
 */
class AgentResource extends Resource
{
    use ReadOnlyResource;

    protected static ?string $model = Agent::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string | \UnitEnum | null $navigationGroup = 'Agents';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Agents';

    protected static ?string $modelLabel = 'agent';

    protected static ?string $pluralModelLabel = 'agents';

    protected static ?string $recordTitleAttribute = 'name';

    public const STATUSES = [
        Agent::DRAFT => 'Being set up',
        Agent::READY => 'Ready, not live',
        Agent::LIVE => 'Live',
        Agent::PAUSED => 'Paused',
    ];

    public const LANGUAGES = ['ar' => 'Arabic', 'en' => 'English'];

    /** The badges of the Status column: the status, then Listen's own and deleted. */
    public const BADGES = self::STATUSES + [
        'house' => 'Listen\'s own',
        'deleted' => 'Deleted',
        'suspended' => 'Suspended',
        'flagged' => 'Marked for review',
        'reported' => 'Reported',
    ];

    public const INSTALLED_VIA = [
        'embed' => 'Pasted code',
        'wordpress' => 'WordPress plugin',
        'shopify' => 'Shopify',
        'wix' => 'Wix',
        'laravel' => 'Laravel',
    ];

    /** The admin deletes agents for good (AgentPurge); nothing else is changed here. Filament 4 checks these. */
    public static function getDeleteAuthorizationResponse(Model $record): AccessResponse
    {
        return $record instanceof Agent && AgentPurge::canPurge($record) ? AccessResponse::allow() : AccessResponse::deny();
    }

    public static function getDeleteAnyAuthorizationResponse(): AccessResponse
    {
        return AccessResponse::allow();
    }

    /** Calls on right now, across every agent (the calls are under each agent). */
    public static function getNavigationBadge(): ?string
    {
        return AgentConversationResource::getNavigationBadge();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Calls live right now';
    }

    /** Deleted agents stay in the list, marked: their calls and customers still point to them. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with('user:id,name,email')
            ->when(self::sitesReady(), fn (Builder $q): Builder => $q->withCount('sites'));
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'business_name', 'public_id', 'user.email'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->name.' · '.$record->business_name;
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'Owner' => $record->user?->email,
            'Status' => self::STATUSES[$record->status] ?? $record->status,
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // The calls' minutes and credits, for the Calls column's line and their own
            // columns: loaded here, as Filament 4 skips the sums of hidden columns.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withSum('conversations', 'duration_seconds')
                ->withSum('conversations', 'credits'))
            ->columns([
                TextColumn::make('name')
                    ->label('Agent')
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Agent $record): ?HtmlString => self::isolate(Str::limit((string) $record->business_name, 30)))
                    ->searchable(['name', 'business_name', 'public_id'])
                    ->sortable(),
                TextColumn::make('user.email')
                    ->label('Owner')
                    ->description(fn (Agent $record): ?string => $record->user?->name)
                    ->url(fn (Agent $record): ?string => self::ownerUrl($record->user_id))
                    ->searchable()
                    ->limit(26)
                    ->tooltip(fn (Agent $record): ?string => $record->user?->email)
                    ->placeholder('Account deleted'),
                TextColumn::make('sites_count')
                    ->label('Websites')
                    ->state(fn (Agent $record): ?string => self::sitesLine($record))
                    ->description(fn (Agent $record): ?string => self::sitesMore($record))
                    ->tooltip('Every website its call button has loaded on (the agent\'s page lists them all, allowed or refused).')
                    ->placeholder('Not on a website yet')
                    ->wrap(),
                TextColumn::make('language')
                    ->label('Language and voice')
                    ->formatStateUsing(fn (?string $state, Agent $record): string => implode(' · ', array_filter([
                        self::LANGUAGES[$state] ?? strtoupper((string) $state),
                        self::accentLabel($record),
                    ])))
                    ->description(fn (Agent $record): string => self::voiceLabel($record) ?? 'No voice picked yet')
                    ->sortable(),
                TextColumn::make('status')
                    ->state(fn (Agent $record): array => self::badges($record))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::BADGES[$state] ?? Str::headline($state))
                    ->color(fn (string $state): string => self::badgeColor($state))
                    ->sortable(),
                TextColumn::make('conversations_count')
                    ->label('Calls')
                    ->counts('conversations')
                    ->numeric()
                    ->description(fn (Agent $record): string => self::minutes((int) $record->conversations_sum_duration_seconds)
                        .' · '.number_format((int) $record->conversations_sum_credits).' credits')
                    ->tooltip('Calls, their minutes, and the credits they took from the owner\'s plan. Sort by minutes or credits from their own columns (column menu).')
                    ->sortable(),
                TextColumn::make('conversations_sum_duration_seconds')
                    ->label('Minutes')
                    ->formatStateUsing(fn ($state): string => self::minutes((int) $state))
                    ->tooltip(fn (Agent $record): string => number_format((int) $record->conversations_sum_duration_seconds).' seconds talked')
                    ->placeholder('0 min')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('conversations_sum_credits')
                    ->label('Credits used')
                    ->formatStateUsing(fn ($state): string => number_format((int) $state))
                    ->placeholder('0')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('conversations_max_started_at')
                    ->label('Last call')
                    ->max('conversations', 'started_at')
                    // "none" rather than a placeholder, so the created date still shows under it.
                    ->default('none')
                    ->formatStateUsing(fn ($state): string => filled($state) && $state !== 'none' ? Carbon::parse($state)->diffForHumans() : 'No calls yet')
                    ->color(fn ($state): ?string => $state === 'none' ? 'gray' : null)
                    ->tooltip(fn (Agent $record): ?string => filled($record->conversations_max_started_at) ? Carbon::parse($record->conversations_max_started_at)->toDayDateTimeString() : null)
                    ->description(fn (Agent $record): string => 'created '.$record->created_at?->format('j M Y'))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('business_type')
                    ->label('Kind of business')
                    ->formatStateUsing(fn (?string $state): string => self::businessTypeLabel($state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('website_url')
                    ->label('Website')
                    ->limit(40)
                    ->url(fn (Agent $record): ?string => AgentConversationResource::safeUrl($record->website_url), true)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('public_id')
                    ->label('Agent id')
                    ->fontFamily('mono')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(self::STATUSES),
                SelectFilter::make('language')
                    ->options(self::LANGUAGES),
                TernaryFilter::make('house')
                    ->label('Listen\'s own agents')
                    ->placeholder('All agents')
                    ->trueLabel('Only Listen\'s own')
                    ->falseLabel('Only members\' agents')
                    ->queries(
                        true: fn (Builder $query): Builder => self::whereHouse($query),
                        false: fn (Builder $query): Builder => self::whereHouse($query, false),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                TernaryFilter::make('has_calls')
                    ->label('Calls')
                    ->placeholder('With or without calls')
                    ->trueLabel('Has had calls')
                    ->falseLabel('No calls yet')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('conversations'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('conversations'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                SelectFilter::make('business_type')
                    ->label('Kind of business')
                    ->options(fn (): array => collect(Agent::BUSINESS_TYPES)->mapWithKeys(fn (string $type): array => [$type => self::businessTypeLabel($type)])->all()),
                Filter::make('attention')
                    ->label('Marked for review, reported or suspended')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q
                        ->whereIn('agents.settings->review->verdict', ['review', 'block'])
                        ->orWhereNotNull('agents.settings->suspended')
                        ->orWhereNotNull('agents.settings->reports'))),
                TernaryFilter::make('deleted')
                    ->label('Deleted by the owner')
                    ->placeholder('Deleted or not')
                    ->trueLabel('Only deleted agents')
                    ->falseLabel('Hide deleted agents')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('agents.deleted_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('agents.deleted_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                ViewAction::make()->iconButton(),
                self::deleteAction()->iconButton(),
            ])
            ->toolbarActions([
                BulkAction::make('delete')
                    ->label('Delete for good')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete these agents for good?')
                    ->modalDescription(self::DELETE_NOTE.' Listen\'s own agents in the selection are kept.')
                    ->modalSubmitActionLabel('Delete for good')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $gone = $records->filter(fn (Agent $agent): bool => AgentPurge::purge($agent))->count();
                        Notification::make()->title(self::plural($gone, 'agent').' deleted')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No agents yet')
            ->emptyStateDescription('Agents members make on the Agents page show here, with Listen\'s own.');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Agent')
                ->columns(4)
                ->schema([
                    TextEntry::make('name')->label('Name')->weight(FontWeight::SemiBold)
                        ->formatStateUsing(fn (?string $state): ?HtmlString => self::isolate($state)),
                    TextEntry::make('business_name')->label('Business')
                        ->formatStateUsing(fn (?string $state): ?HtmlString => self::isolate($state)),
                    TextEntry::make('business_type')->label('Kind of business')
                        ->formatStateUsing(fn (?string $state): string => self::businessTypeLabel($state)),
                    TextEntry::make('jobs')->label('What it does')
                        ->badge()->color('gray')
                        ->formatStateUsing(fn (?string $state): string => self::jobLabel($state))
                        ->placeholder('—'),
                    TextEntry::make('user.email')->label('Owner')
                        ->url(fn (Agent $record): ?string => self::ownerUrl($record->user_id))
                        ->helperText(fn (Agent $record): ?string => $record->user?->name)
                        ->copyable()
                        ->placeholder('Account deleted'),
                    TextEntry::make('status')
                        ->state(fn (Agent $record): array => self::badges($record))
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => self::BADGES[$state] ?? Str::headline($state))
                        ->color(fn (string $state): string => self::badgeColor($state)),
                    TextEntry::make('language')
                        ->formatStateUsing(fn (?string $state): string => self::LANGUAGES[$state] ?? strtoupper((string) $state)),
                    TextEntry::make('dialect')->label('Dialect or accent')
                        ->state(fn (Agent $record): ?string => self::accentLabel($record))
                        ->placeholder('—'),
                    TextEntry::make('website_url')->label('Website')
                        ->url(fn (Agent $record): ?string => AgentConversationResource::safeUrl($record->website_url), true)
                        ->placeholder('None given'),
                    TextEntry::make('max_call_seconds')->label('Longest call')
                        ->formatStateUsing(fn ($state): string => self::clock((int) $state).' ('.number_format((int) $state).' s)')
                        ->helperText(fn (Agent $record): ?string => self::isHouse($record) ? 'Listen\'s own agents stop at '.self::clock(HouseAgent::MAX_SECONDS).'.' : null),
                    TextEntry::make('public_id')->label('Agent id')->copyable()->fontFamily('mono'),
                    TextEntry::make('id')->label('Database id'),
                    TextEntry::make('created_at')->label('Created')->dateTime(),
                    TextEntry::make('updated_at')->label('Last changed')->dateTime(),
                    TextEntry::make('tested_at')->label('Last tested by the owner')->dateTime()->placeholder('Never'),
                    TextEntry::make('went_live_at')->label('Went live')->dateTime()->placeholder('Not yet'),
                    TextEntry::make('deleted_at')->label('Deleted by the owner')->dateTime()->color('danger')
                        ->visible(fn (Agent $record): bool => $record->trashed()),
                ]),

            Section::make('Calls and credits')
                ->description(fn (): string => 'Every call it has had. Calls take '.number_format(AgentCalls::creditsPerMinute()).' credits a minute from the owner\'s plan; Listen\'s own agents and the owner\'s free test seconds take none.')
                ->columns(5)
                ->schema([
                    TextEntry::make('totals_calls')->label('Calls')
                        ->state(fn (Agent $record): string => number_format(self::totals($record)['calls']))
                        ->helperText(fn (Agent $record): ?string => AgentConversationResource::channelBreakdown(self::totals($record)['by_channel'])),
                    TextEntry::make('totals_minutes')->label('Talk time')
                        ->state(fn (Agent $record): string => self::minutes(self::totals($record)['seconds']))
                        ->helperText(fn (Agent $record): string => number_format(self::totals($record)['seconds']).' seconds'),
                    TextEntry::make('totals_billed')->label('Billed time')
                        ->state(fn (Agent $record): string => self::minutes(self::totals($record)['billed']))
                        ->helperText(fn (Agent $record): string => number_format(self::totals($record)['billed']).' seconds'),
                    TextEntry::make('totals_credits')->label('Credits used')
                        ->state(fn (Agent $record): string => number_format(self::totals($record)['credits'])),
                    TextEntry::make('totals_free')->label('Free calls')
                        ->state(fn (Agent $record): string => number_format(self::totals($record)['free']))
                        ->helperText(fn (Agent $record): ?string => self::totals($record)['free'] > 0 ? self::minutes(self::totals($record)['free_seconds']).' at no charge' : null),
                    TextEntry::make('totals_live')->label('Live right now')
                        ->state(fn (Agent $record): string => number_format(self::totals($record)['live']))
                        ->color(fn (Agent $record): ?string => self::totals($record)['live'] > 0 ? 'success' : null),
                    TextEntry::make('totals_failed')->label('Failed calls')
                        ->state(fn (Agent $record): string => number_format(self::totals($record)['failed']))
                        ->color(fn (Agent $record): ?string => self::totals($record)['failed'] > 0 ? 'danger' : null),
                    TextEntry::make('totals_last')->label('Last call')
                        ->state(fn (Agent $record): ?string => self::totals($record)['last'])
                        ->since()
                        ->dateTimeTooltip()
                        ->placeholder('No calls yet'),
                    TextEntry::make('totals_leads')->label('Customers saved')
                        ->state(fn (Agent $record): string => number_format(self::totals($record)['contacts']))
                        ->helperText(fn (Agent $record): string => self::plural(self::totals($record)['leads'], 'call').' left their details'),
                    TextEntry::make('totals_cost')->label('Cost to us (estimate)')
                        ->state(fn (Agent $record): string => '$'.number_format(self::totals($record)['cost'], 4))
                        ->helperText('From the tokens each call counted'),
                ]),

            Section::make('Voice')
                ->columns(3)
                ->schema([
                    TextEntry::make('speaker_id')->label('Speaker (voice library)')
                        ->state(fn (Agent $record): ?string => self::speakerLabel($record->speaker_id))
                        ->placeholder('Not picked yet'),
                    TextEntry::make('live_voice')->label('Voice it speaks with on calls')
                        ->placeholder('Not picked yet'),
                    ViewEntry::make('first_message')->label('What it says first')
                        ->view('filament.agents.paragraph', ['empty' => 'Nothing set: it opens in its own words.'])
                        ->columnSpanFull(),
                ]),

            Section::make('Script')
                ->description('What the agent is told before every call, as the owner left it. Every agent also keeps Listen\'s own rules (answer only about the business, never guess, read a phone number back).')
                ->schema([
                    ViewEntry::make('script.identity')->label('Who it is')
                        ->view('filament.agents.paragraph'),
                    ViewEntry::make('script.never')->label('What it must never do')
                        ->view('filament.agents.text-list', ['ordered' => false, 'empty' => 'Nothing added by the owner.']),
                    ViewEntry::make('script.steps')->label('Its steps on a call')
                        ->view('filament.agents.text-list', ['ordered' => true, 'empty' => 'No steps.']),
                    ViewEntry::make('script.handoff')->label('When it passes the caller to a person')
                        ->view('filament.agents.paragraph'),
                ]),

            Section::make('Decided at setup')
                ->description('The questions the setup asked the owner, with the answer the agent follows.')
                ->collapsible()
                ->schema([
                    ViewEntry::make('choices')->hiddenLabel()
                        ->view('filament.agents.choices'),
                ]),

            Section::make('Safety')
                ->description('The check before it went live, what callers reported, and whether the team suspended it (Suspend, at the top of the page).')
                ->columns(3)
                ->schema([
                    TextEntry::make('review_verdict')->label('Check before going live')
                        ->state(fn (Agent $record): string => match ($record->setting('review.verdict')) {
                            'allow' => 'Looks fine',
                            'review' => 'Marked for review',
                            'block' => 'Stopped from going live',
                            default => 'Not checked yet',
                        })
                        ->badge()
                        ->color(fn (Agent $record): string => match ($record->setting('review.verdict')) {
                            'allow' => 'success', 'review' => 'warning', 'block' => 'danger', default => 'gray',
                        }),
                    TextEntry::make('review_reason')->label('What the check said')
                        ->state(fn (Agent $record): string => (string) ($record->setting('review.reason') ?: '—'))
                        ->columnSpan(2),
                    TextEntry::make('suspended_reason')->label('Suspended by the team')
                        ->state(fn (Agent $record): string => $record->setting('suspended')
                            ? trim((string) $record->setting('suspended.reason')).' ('.Carbon::parse((string) $record->setting('suspended.at'))->toDayDateTimeString().')'
                            : 'No')
                        ->color(fn (Agent $record): ?string => $record->setting('suspended') ? 'danger' : null)
                        ->columnSpan(3),
                    TextEntry::make('reports_list')->label('Reported by callers')
                        ->state(fn (Agent $record): array => collect((array) $record->setting('reports', []))->reverse()->take(10)
                            ->map(fn ($r): string => Carbon::parse((string) ($r['at'] ?? now()))->toDayDateTimeString().' · '.(AgentReportController::REASONS[$r['reason'] ?? 'other'] ?? 'Other')
                                .(filled($r['note'] ?? null) ? ': '.Str::limit((string) $r['note'], 200) : ''))
                            ->values()->all() ?: ['No reports'])
                        ->listWithLineBreaks()
                        ->columnSpan(3),
                ]),

            Section::make('Settings')
                ->description('Anything else saved with the agent.')
                ->collapsible()
                ->schema([
                    ViewEntry::make('settings')->hiddenLabel()
                        ->view('filament.agents.key-values', ['empty' => 'No other settings.']),
                ]),

            Section::make('What it knows')
                ->description(fn (Agent $record): string => number_format(mb_strlen((string) $record->knowledge)).' characters. The agent answers only from these facts; up to '.number_format(AgentInstructions::MAX_KNOWLEDGE_CHARS).' characters go into every call.')
                ->collapsible()
                ->collapsed(fn (Agent $record): bool => mb_strlen((string) $record->knowledge) > 1500)
                ->schema([
                    ViewEntry::make('knowledge')->hiddenLabel()
                        ->view('filament.agents.long-text', ['empty' => 'Nothing yet.']),
                ]),

            Section::make('Where it learned it')
                ->description('Its knowledge sources: the website it read, the owner\'s notes and corrections, and files.')
                ->schema([
                    ViewEntry::make('sources')->hiddenLabel()
                        ->view('filament.agents.sources'),
                ]),

            Section::make('Call button and link page')
                ->description('Where customers reach it: the button a website loads with one line of code, and its link page (also its QR code).')
                ->schema([
                    RepeatableEntry::make('widgets')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('slug')->label('Link page')
                                ->state(fn (AgentWidget $record): string => self::talkUrl($record) ?? '/talk/'.$record->slug)
                                ->url(fn (AgentWidget $record): ?string => self::talkUrl($record), true)
                                ->copyable()
                                ->columnSpan(2),
                            TextEntry::make('public_key')->label('Call button key (in the website\'s code)')
                                ->copyable()->fontFamily('mono')
                                ->columnSpan(2),
                            IconEntry::make('enabled')->label('Call button on')->boolean(),
                            IconEntry::make('link_enabled')->label('Link page on')->boolean(),
                            TextEntry::make('installed_via')->label('Installed with')
                                ->formatStateUsing(fn (?string $state): string => self::INSTALLED_VIA[$state] ?? Str::headline((string) $state))
                                ->placeholder('Not installed yet'),
                            TextEntry::make('last_seen_at')->label('Last seen')
                                ->since()->dateTimeTooltip()
                                ->placeholder('Never'),
                            TextEntry::make('allowed_origins')->label('Websites allowed to load it')
                                ->state(fn (AgentWidget $record): array => array_values(array_filter((array) $record->allowed_origins)))
                                ->listWithLineBreaks()
                                ->placeholder('Any website')
                                ->columnSpan(2),
                            TextEntry::make('last_seen_origin')->label('Last seen on')
                                ->url(fn (AgentWidget $record): ?string => AgentConversationResource::safeUrl($record->last_seen_origin), true)
                                ->placeholder('Never')
                                ->columnSpan(2),
                            ViewEntry::make('appearance')->label('How the button looks')
                                ->view('filament.agents.key-values', ['defaults' => AgentWidget::DEFAULT_APPEARANCE, 'empty' => 'The default look.'])
                                ->columnSpanFull(),
                        ])
                        ->placeholder('No call button yet.'),
                ]),
        ]);
    }

    public const DELETE_NOTE = 'The agent goes for good, with its calls, their recordings, its knowledge files and its call buttons (they stop working on every website). Calls still on are ended first, so the credits they held go back to the owner. The customers it saved stay in the owner\'s list. This cannot be undone.';

    /** Delete for good (AgentPurge), on the list and on the agent's page; never Listen's own. */
    public static function deleteAction(): Action
    {
        return Action::make('delete')
            ->label('Delete for good')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (Agent $record): bool => AgentPurge::canPurge($record))
            ->requiresConfirmation()
            ->modalHeading(fn (Agent $record): string => 'Delete '.$record->name.' for good?')
            ->modalDescription(self::DELETE_NOTE)
            ->modalSubmitActionLabel('Delete for good')
            ->action(function (Agent $record): void {
                AgentPurge::purge($record);
                Notification::make()->title('Agent deleted')->success()->send();
            });
    }

    /** The websites table exists (not in the minute between a deploy and its migration). */
    public static function sitesReady(): bool
    {
        return once(fn (): bool => rescue(fn (): bool => \Illuminate\Support\Facades\Schema::hasTable('agent_widget_sites'), false, false));
    }

    /** "clinic.example", the website its button was last seen on; null when none. */
    public static function sitesLine(Agent $agent): ?string
    {
        if (self::sitesCount($agent) === 0) {
            return null;
        }
        // Where it shows first (the newest), else the newest website that was refused.
        $last = $agent->sites()->reorder()->orderByDesc('allowed')->orderByDesc('last_seen_at')->first(['origin', 'allowed']);

        return $last ? $last->host().($last->allowed ? '' : ' (refused)') : null;
    }

    public static function sitesMore(Agent $agent): ?string
    {
        $more = self::sitesCount($agent) - 1;

        return $more > 0 ? 'and '.self::plural($more, 'more website') : null;
    }

    /** How many websites (counted with the list's query, or here for one agent). */
    public static function sitesCount(Agent $agent): int
    {
        if (! self::sitesReady()) {
            return 0;
        }

        return (int) ($agent->getAttribute('sites_count') ?? $agent->sites()->count());
    }

    public static function getRelations(): array
    {
        return array_values(array_filter([
            self::sitesReady() ? SitesRelationManager::class : null,
            ConversationsRelationManager::class,
            ContactsRelationManager::class,
        ]));
    }

    public static function getWidgets(): array
    {
        return [AgentStats::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgents::route('/'),
            'view' => ViewAgent::route('/{record}'),
        ];
    }

    /** @return array<int, string> the Status badges of an agent: its status, then Listen's own and deleted */
    public static function badges(Agent $agent): array
    {
        return array_values(array_filter([
            (string) $agent->status,
            self::isHouse($agent) ? 'house' : null,
            $agent->trashed() ? 'deleted' : null,
            $agent->setting('suspended') ? 'suspended' : null,
            in_array($agent->setting('review.verdict'), ['review', 'block'], true) ? 'flagged' : null,
            count((array) $agent->setting('reports', [])) > 0 ? 'reported' : null,
        ]));
    }

    public static function badgeColor(?string $state): string
    {
        return match ($state) {
            Agent::LIVE => 'success',
            Agent::READY => 'info',
            Agent::PAUSED => 'warning',
            'house' => 'primary',
            'deleted', 'suspended' => 'danger',
            'flagged', 'reported' => 'warning',
            default => 'gray',
        };
    }

    /** "Egyptian (Cairene)" or "British (Standard)": the label the studio gives the code. */
    public static function dialectLabel(?string $code): ?string
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }

        return ArabicDialects::ALL[$code]['en'] ?? EnglishAccents::ALL[$code]['en'] ?? $code;
    }

    /** The agent's dialect, or for an English agent the accent it was given. */
    public static function accentLabel(Agent $agent): ?string
    {
        return self::dialectLabel($agent->dialect)
            ?? self::dialectLabel(is_string($agent->setting('accent')) ? $agent->setting('accent') : null)
            ?? (filled($agent->setting('accent_prompt')) ? Str::limit((string) $agent->setting('accent_prompt'), 60) : null);
    }

    /** The speaker's name in the voice library ("Sumaya"), never the engine's id for it. */
    public static function speakerName(?string $speakerId): ?string
    {
        if (blank($speakerId)) {
            return null;
        }

        return once(fn (): ?string => Voice::query()->where('internal_voice_id', $speakerId)->value('display_name'));
    }

    /** "Sumaya (female)", from the voice library. */
    public static function speakerLabel(?string $speakerId): ?string
    {
        if (blank($speakerId)) {
            return null;
        }
        $voice = once(fn (): ?Voice => Voice::query()->where('internal_voice_id', $speakerId)->first(['id', 'display_name', 'gender']));
        if ($voice === null) {
            return 'A speaker no longer in the voice library';
        }

        return $voice->display_name.(filled($voice->gender) ? ' ('.strtolower((string) $voice->gender).')' : '');
    }

    /** "Sumaya (Callirrhoe)": the library speaker, then the live voice it speaks with. */
    public static function voiceLabel(Agent $agent): ?string
    {
        $name = self::speakerName($agent->speaker_id);
        $live = trim((string) $agent->live_voice);

        return match (true) {
            $name !== null && $live !== '' => $name.' ('.$live.')',
            $name !== null => $name,
            $live !== '' => $live,
            default => null,
        };
    }

    /** @return array<int, string> the public ids of Listen's own agents, as the agents_house setting lists them */
    public static function houseAgentIds(): array
    {
        return once(function (): array {
            $all = json_decode((string) SiteSetting::get(HouseAgent::SETTING, ''), true);
            if (! is_array($all)) {
                return [];
            }

            return array_values(array_filter(array_map(
                fn ($row): string => is_array($row) ? (string) ($row['agent'] ?? '') : '',
                $all,
            )));
        });
    }

    /** Listen's own agent: listed in the agents_house setting, or set up as one (its calls are on Listen). */
    public static function isHouse(Agent $agent): bool
    {
        return in_array((string) $agent->public_id, self::houseAgentIds(), true) || filled($agent->setting('house'));
    }

    /** Narrows an agents query to Listen's own agents, or (false) to members' agents. */
    public static function whereHouse(Builder $query, bool $house = true): Builder
    {
        $ids = self::houseAgentIds();
        $match = fn (Builder $q): Builder => $q->whereNotNull('agents.settings->house')
            ->when($ids !== [], fn (Builder $q): Builder => $q->orWhereIn('agents.public_id', $ids));

        return $house ? $query->where($match) : $query->whereNot($match);
    }

    /** @return array<int, int> the database ids of Listen's own agents, deleted ones too */
    public static function houseAgentKeys(): array
    {
        return once(fn (): array => self::whereHouse(Agent::withTrashed())->pluck('agents.id')->map(fn ($id): int => (int) $id)->all());
    }

    /**
     * What an agent's calls added up to, worked out once per page.
     *
     * @return array{calls: int, by_channel: array<string, int>, seconds: int, billed: int, credits: int, free: int, free_seconds: int, live: int, failed: int, last: ?string, leads: int, contacts: int, cost: float}
     */
    public static function totals(Agent $agent): array
    {
        return once(function () use ($agent): array {
            $calls = fn (): Builder => AgentConversation::query()->where('agent_id', $agent->getKey());
            // One pass over its calls (by channel), however many there are, instead of one a figure.
            $rows = $calls()->toBase()->selectRaw('channel, count(*) as calls, sum(duration_seconds) as seconds, sum(billed_seconds) as billed,
                sum(credits) as credits, sum(case when free = 1 then 1 else 0 end) as free, sum(case when free = 1 then billed_seconds else 0 end) as free_seconds,
                sum(case when status = ? then 1 else 0 end) as failed, sum(case when lead is not null then 1 else 0 end) as leads,
                sum(coalesce(cost_usd, 0)) as cost, max(started_at) as last', [AgentConversation::FAILED])
                ->groupBy('channel')->get();
            $sum = fn (string $key): int => (int) $rows->sum(fn ($r): int => (int) $r->{$key});

            return [
                'calls' => $sum('calls'),
                'by_channel' => $rows->mapWithKeys(fn ($r): array => [(string) $r->channel => (int) $r->calls])->all(),
                'seconds' => $sum('seconds'),
                'billed' => $sum('billed'),
                'credits' => $sum('credits'),
                'free' => $sum('free'),
                'free_seconds' => $sum('free_seconds'),
                'live' => AgentConversationResource::whereLiveNow($calls())->count(),
                'failed' => $sum('failed'),
                'last' => $rows->max('last'),
                'leads' => $sum('leads'),
                'contacts' => AgentContact::query()->where('agent_id', $agent->getKey())->count(),
                'cost' => (float) $rows->sum(fn ($r): float => (float) $r->cost),
            ];
        });
    }

    public static function ownerUrl(?int $userId): ?string
    {
        return $userId ? UserResource::getUrl('view', ['record' => $userId]) : null;
    }

    /**
     * The Agent cell of the calls and customers lists: the business above, the
     * agent's name, and the owner's email below. It opens the agent.
     */
    public static function agentColumn(): TextColumn
    {
        return TextColumn::make('agent.name')
            ->label('Agent')
            ->weight(FontWeight::SemiBold)
            ->description(fn (Model $record): ?HtmlString => $record->agent
                ? new HtmlString(self::isolate(Str::limit((string) $record->agent->business_name, 28))?->toHtml().(self::isHouse($record->agent) ? ' · Listen\'s own' : ''))
                : null, 'above')
            ->description(fn (Model $record): ?string => Str::limit(self::ownerEmailOf($record), 30) ?: null)
            ->url(fn (Model $record): ?string => $record->agent ? self::getUrl('view', ['record' => $record->agent]) : null)
            ->searchable(['name', 'business_name'])
            ->placeholder('Agent removed');
    }

    /** The owner's email for a call (its user) or a customer (the owner_email its list selects). */
    public static function ownerEmailOf(Model $record): string
    {
        return (string) ($record instanceof AgentConversation
            ? $record->user?->email
            : ($record->getAttribute('owner_email') ?? $record->agent?->user?->email));
    }

    /** A short text in its own direction (an Arabic name on an English page), where the eye expects it. */
    public static function isolate(?string $text): ?HtmlString
    {
        $text = trim((string) $text);

        return $text === '' ? null : new HtmlString('<bdi>'.e($text).'</bdi>');
    }

    public static function talkUrl(AgentWidget $widget): ?string
    {
        return Route::has('agent-talk') && filled($widget->slug) ? route('agent-talk', ['slug' => $widget->slug]) : null;
    }

    /** The label the Agents setup shows for a kind of business. */
    public static function businessTypeLabel(?string $type): string
    {
        return self::setupLabel('agents.types.'.$type, (string) $type);
    }

    /** The label the Agents setup shows for one of an agent's jobs. */
    public static function jobLabel(?string $job): string
    {
        return self::setupLabel('agents.jobs.'.$job, (string) $job);
    }

    private static function setupLabel(string $key, string $fallback): string
    {
        $label = trans($key, [], 'en');

        return is_string($label) && $label !== $key ? $label : ($fallback !== '' ? Str::headline($fallback) : '—');
    }

    /** "4:05" for 245 seconds, "1:02:03" past an hour. */
    public static function clock(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return $seconds >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /** "1 call", "3 calls". */
    public static function plural(int $count, string $one, ?string $many = null): string
    {
        return number_format($count).' '.($count === 1 ? $one : ($many ?? $one.'s'));
    }

    /** "45 s", "12.5 min" or "1,240 min": the unit calls are charged in. */
    public static function minutes(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0 min';
        }
        if ($seconds < 60) {
            return $seconds.' s';
        }
        $minutes = number_format($seconds / 60, 1);

        return (str_ends_with($minutes, '.0') ? substr($minutes, 0, -2) : $minutes).' min';
    }
}
