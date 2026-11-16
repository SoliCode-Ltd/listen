<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AgentContactResource\Pages\ListAgentContacts;
use App\Filament\Resources\Concerns\ReadOnlyResource;
use App\Models\AgentContact;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Customers (read only): the callers who left their name and phone number
 * (or email) with an agent, so its business can call them back. One per
 * phone number (or email) per owner; a second call updates it.
 */
class AgentContactResource extends Resource
{
    use ReadOnlyResource;

    protected static ?string $model = AgentContact::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static string|\UnitEnum|null $navigationGroup = 'Agents';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Customers';

    protected static ?string $modelLabel = 'customer';

    protected static ?string $pluralModelLabel = 'customers';

    protected static ?string $slug = 'agent-customers';

    /** The owner's email comes with each row (a customer keeps its owner even if the agent goes). */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->addSelect(['owner_email' => User::query()->select('email')->whereColumn('users.id', 'agent_contacts.user_id')->limit(1)])
            ->with([
                'agent:id,public_id,user_id,name,business_name,settings,deleted_at',
                'conversation:id,public_id,channel,started_at',
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(self::tableColumns())
            ->defaultSort('last_contact_at', 'desc')
            ->filters([
                SelectFilter::make('agent_id')
                    ->label('Agent')
                    ->options(fn (): array => AgentConversationResource::agentOptions())
                    ->searchable(),
                TernaryFilter::make('house')
                    ->label('Listen\'s own agents')
                    ->placeholder('All agents')
                    ->trueLabel('Only Listen\'s own')
                    ->falseLabel('Only members\' agents')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('agent', fn (Builder $agent): Builder => AgentResource::whereHouse($agent)),
                        false: fn (Builder $query): Builder => $query->whereHas('agent', fn (Builder $agent): Builder => AgentResource::whereHouse($agent, false)),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                TernaryFilter::make('phone')
                    ->label('Phone number')
                    ->placeholder('With or without')
                    ->trueLabel('Left a phone number')
                    ->falseLabel('No phone number')
                    ->nullable(),
                TernaryFilter::make('email')
                    ->label('Email')
                    ->placeholder('With or without')
                    ->trueLabel('Left an email')
                    ->falseLabel('No email')
                    ->nullable(),
                Filter::make('last_contact_at')
                    ->form([
                        DatePicker::make('from')->label('Last contact from'),
                        DatePicker::make('until')->label('Last contact until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('agent_contacts.last_contact_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('agent_contacts.last_contact_at', '<=', $date)))
                    ->indicateUsing(fn (array $data): array => array_values(array_filter([
                        ($data['from'] ?? null) ? 'Last contact from '.Carbon::parse($data['from'])->toFormattedDateString() : null,
                        ($data['until'] ?? null) ? 'Last contact until '.Carbon::parse($data['until'])->toFormattedDateString() : null,
                    ]))),
            ])
            ->recordUrl(fn (AgentContact $record): ?string => self::callUrl($record))
            ->recordActions([
                self::openCallAction(),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('No customers yet')
            ->emptyStateDescription('When a caller leaves their name and phone number (or email) with an agent, they show here.');
    }

    /**
     * The customers table, also under each agent (without the agent and owner columns there).
     *
     * @return array<int, Tables\Columns\Column>
     */
    public static function tableColumns(bool $withAgent = true): array
    {
        return array_values(array_filter([
            TextColumn::make('name')
                ->weight(FontWeight::SemiBold)
                ->formatStateUsing(fn (?string $state): ?HtmlString => AgentResource::isolate(Str::limit((string) $state, 40)))
                ->searchable()
                ->sortable()
                ->placeholder('No name given'),
            TextColumn::make('phone')
                ->label('Phone and email')
                // The email takes the phone's place when there is no phone, so the line under it is never lost.
                ->state(fn (AgentContact $record): ?string => $record->phone ?: $record->email)
                ->description(fn (AgentContact $record): ?string => $record->phone ? $record->email : null)
                ->searchable(['phone', 'email'])
                ->copyable()
                ->placeholder('—'),
            TextColumn::make('note')
                ->label('What they want')
                ->formatStateUsing(fn (?string $state): ?HtmlString => AgentResource::isolate(Str::limit((string) $state, 80)))
                ->tooltip(fn (AgentContact $record): ?string => mb_strlen((string) $record->note) > 80 ? $record->note : null)
                ->wrap()
                ->extraHeaderAttributes(['style' => 'min-width: 15rem'])
                ->searchable()
                ->placeholder('—'),
            $withAgent ? AgentResource::agentColumn() : null,
            $withAgent ? TextColumn::make('owner_email')
                ->label('Owner')
                ->url(fn (AgentContact $record): ?string => AgentResource::ownerUrl($record->user_id))
                ->limit(28)
                ->tooltip(fn (AgentContact $record): ?string => $record->owner_email)
                ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereIn(
                    'agent_contacts.user_id',
                    User::query()->select('id')->where('email', 'like', '%'.$search.'%'),
                ))
                ->sortable()
                ->placeholder('Account deleted')
                ->toggleable(isToggledHiddenByDefault: true) : null,
            TextColumn::make('last_contact_at')
                ->label('Last contact')
                ->since()
                ->dateTimeTooltip()
                ->sortable()
                ->placeholder('—'),
            TextColumn::make('created_at')
                ->label('First saved')
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ]));
    }

    /** The call the customer last left their details on. */
    public static function openCallAction(): Action
    {
        return Action::make('openCall')
            ->label('Open the call')
            ->tooltip('Open the call they left their details on')
            ->iconButton()
            ->icon('heroicon-o-phone')
            ->url(fn (AgentContact $record): ?string => self::callUrl($record))
            ->visible(fn (AgentContact $record): bool => self::callUrl($record) !== null);
    }

    public static function callUrl(AgentContact $contact): ?string
    {
        return $contact->conversation ? AgentConversationResource::getUrl('view', ['record' => $contact->conversation]) : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentContacts::route('/'),
        ];
    }
}
