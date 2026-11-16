<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NewsletterResource\Pages\CreateNewsletter;
use App\Filament\Resources\NewsletterResource\Pages\EditNewsletter;
use App\Filament\Resources\NewsletterResource\Pages\ListNewsletters;
use App\Filament\Resources\NewsletterResource\RelationManagers\DeliveriesRelationManager;
use App\Models\Newsletter;
use App\Models\Plan;
use App\Services\Newsletter\NewsletterPace;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;

class NewsletterResource extends Resource
{
    protected static ?string $model = Newsletter::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static string|\UnitEnum|null $navigationGroup = 'Newsletter';

    protected static ?string $navigationLabel = 'Newsletter';

    protected static ?string $modelLabel = 'newsletter';

    protected static ?string $pluralModelLabel = 'Newsletters';

    protected static ?string $slug = 'newsletter';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components(static::formSchema());
    }

    /**
     * @return array<int, Component>
     */
    public static function formSchema(): array
    {
        return [
            Section::make('Campaign')
                ->description('Saving stores a draft. Nothing is emailed until you use Send example or Send next batch.')
                ->schema([
                    TextInput::make('name')
                        ->label('Internal name')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Only visible in this admin. Recipients do not see it.'),
                    Placeholder::make('delivery_summary')
                        ->label('Delivery')
                        ->content(function (?Newsletter $record): string {
                            if (! $record?->exists) {
                                return 'Draft. Not sent.';
                            }

                            $example = $record->last_test_email
                                ? ' Last example: '.$record->last_test_email.' at '.$record->last_test_sent_at?->timezone(config('app.timezone'))->format('Y-m-d H:i').'.'
                                : ' No example sent yet.';

                            $queued = $record->queuedDeliveries()->count();
                            $pace = app(NewsletterPace::class);
                            $hold = $pace->holdReason();
                            $sending = $queued > 0
                                ? ' Press Send next batch. Queued mail goes out before this page finishes, up to '.$pace->hourlyRemaining().' this hour.'
                                : '';

                            return 'Status: '.$record->status
                                .'. Sent: '.$record->sent_count
                                .'. Failed: '.$record->failed_count
                                .'. Queued: '.$queued
                                .'. Mailbox today: '.$pace->sentToday().' of '.$pace->dailyLimit().'.'
                                .$sending
                                .($hold !== null ? ' Paused: '.$hold : '')
                                .$example;
                        }),
                    Textarea::make('internal_notes')
                        ->label('Internal notes')
                        ->rows(2)
                        ->columnSpanFull(),
                    TextInput::make('from_name')
                        ->label('From name')
                        ->maxLength(120)
                        ->default('Listen')
                        ->helperText('The name people see in their inbox.'),
                    TextInput::make('from_address')
                        ->label('From address')
                        ->email()
                        ->maxLength(255)
                        ->default('help@listenai.studio')
                        ->helperText('Titan only delivers from the signed-in mailbox, help@listenai.studio. The password is saved under Newsletter → Mailbox.'),
                    TextInput::make('reply_to')
                        ->label('Reply-to')
                        ->email()
                        ->maxLength(255)
                        ->helperText('Where replies should go. Leave empty to use the from address.'),
                ])
                ->columns(2),

            Section::make('Who receives it')
                ->description('Free means a registered account that is not on an active paid plan. Turn voice cloning on for that paid plan before you send, or billing will not unlock it.')
                ->schema([
                    Radio::make('audience')
                        ->label('Audience')
                        ->options([
                            Newsletter::AUDIENCE_FREE => 'Free registered users',
                            Newsletter::AUDIENCE_PAID => 'Paid subscribers',
                            Newsletter::AUDIENCE_ALL => 'Every registered user',
                            Newsletter::AUDIENCE_PLAN => 'One plan',
                            Newsletter::AUDIENCE_CUSTOM => 'Only the addresses I list',
                        ])
                        ->descriptions([
                            Newsletter::AUDIENCE_FREE => 'Accounts with no active paid plan, including people who never picked a plan.',
                            Newsletter::AUDIENCE_PAID => 'Accounts on an active paid or trial plan.',
                            Newsletter::AUDIENCE_ALL => 'Every registered account with a real email address.',
                            Newsletter::AUDIENCE_PLAN => 'Accounts whose current plan is the one you pick.',
                            Newsletter::AUDIENCE_CUSTOM => 'Ignores free and paid. Sends only to the addresses below.',
                        ])
                        ->default(Newsletter::AUDIENCE_FREE)
                        ->live()
                        ->required()
                        ->columnSpanFull(),
                    Select::make('plan_id')
                        ->label('Plan')
                        ->options(fn (): array => Plan::query()
                            ->orderBy('price_cents')
                            ->get()
                            ->mapWithKeys(fn (Plan $plan): array => [
                                $plan->id => $plan->name.' — '.($plan->price_cents > 0
                                    ? '$'.number_format($plan->price_cents / 100, 2)
                                    : 'Free'),
                            ])
                            ->all())
                        ->searchable()
                        ->visible(fn (Get $get): bool => $get('audience') === Newsletter::AUDIENCE_PLAN)
                        ->required(fn (Get $get): bool => $get('audience') === Newsletter::AUDIENCE_PLAN),
                    Textarea::make('custom_emails')
                        ->label('Addresses')
                        ->rows(5)
                        ->visible(fn (Get $get): bool => $get('audience') === Newsletter::AUDIENCE_CUSTOM)
                        ->required(fn (Get $get): bool => $get('audience') === Newsletter::AUDIENCE_CUSTOM)
                        ->helperText('One address per line. Commas and spaces also work.')
                        ->columnSpanFull(),
                    Toggle::make('exclude_blocked')
                        ->label('Skip blocked accounts')
                        ->default(true),
                    Toggle::make('exclude_unsubscribed')
                        ->label('Skip unsubscribed addresses')
                        ->default(true),
                    Toggle::make('exclude_already_sent')
                        ->label('Skip people who already received this newsletter')
                        ->helperText('Example sends do not count. You can send the real newsletter to someone after mailing yourself a preview. Turned off, people who had it before can get it once more: each batch still skips people sent it since you turned this off.')
                        ->default(true),
                    Toggle::make('skip_phone_accounts')
                        ->label('Skip phone accounts without a real email')
                        ->helperText('Phone signups use an internal address ending in @users.listen.local. Those addresses are never emailed.')
                        ->default(true),
                    Toggle::make('exclude_admins')
                        ->label('Skip admin accounts')
                        ->helperText('Keeps staff out of the audience. Turn this off if an admin should receive the newsletter too.')
                        ->default(true),
                ])
                ->columns(2),

            Section::make('Language')
                ->schema([
                    Radio::make('language')
                        ->label('What to send')
                        ->options([
                            Newsletter::LANGUAGE_BOTH => 'English and Arabic in one email',
                            Newsletter::LANGUAGE_EN => 'English only',
                            Newsletter::LANGUAGE_AR => 'Arabic only',
                        ])
                        ->descriptions([
                            Newsletter::LANGUAGE_BOTH => 'One message with both languages. The inbox subject uses both subjects.',
                            Newsletter::LANGUAGE_EN => 'English copy only. Arabic fields stay saved.',
                            Newsletter::LANGUAGE_AR => 'Arabic copy only. English fields stay saved.',
                        ])
                        ->default(Newsletter::LANGUAGE_BOTH)
                        ->live()
                        ->required(),
                    Radio::make('bilingual_order')
                        ->label('Order inside the email')
                        ->options([
                            'en_ar' => 'English, then Arabic',
                            'ar_en' => 'Arabic, then English',
                        ])
                        ->default('en_ar')
                        ->visible(fn (Get $get): bool => $get('language') === Newsletter::LANGUAGE_BOTH)
                        ->required(fn (Get $get): bool => $get('language') === Newsletter::LANGUAGE_BOTH),
                ]),

            Tabs::make('Copy')
                ->tabs([
                    Tab::make('English')->schema(static::copyFields('en')),
                    Tab::make('Arabic')->schema(static::copyFields('ar')),
                ])
                ->columnSpanFull(),

            Section::make('Preview')
                ->description('The preview uses the names Alex and أحمد. Refresh after you edit. The unsubscribe link is added on the real send.')
                ->schema([
                    Select::make('preview_locale')
                        ->label('Preview language')
                        ->options([
                            Newsletter::LANGUAGE_BOTH => 'English and Arabic',
                            Newsletter::LANGUAGE_EN => 'English',
                            Newsletter::LANGUAGE_AR => 'Arabic',
                        ])
                        ->default(Newsletter::LANGUAGE_BOTH)
                        ->dehydrated(false)
                        ->native(false),
                    Placeholder::make('preview_frame')
                        ->label('Email')
                        ->content(function ($livewire): HtmlString {
                            $html = is_object($livewire) && property_exists($livewire, 'previewHtml')
                                ? (string) $livewire->previewHtml
                                : '';

                            if ($html === '') {
                                return new HtmlString('<p style="margin:0;color:#6B7280;">Refresh the preview to see the email.</p>');
                            }

                            return new HtmlString(
                                '<iframe srcdoc="'.e($html).'" title="Newsletter preview" style="width:100%;height:820px;border:1px solid #E6E4F2;border-radius:16px;background:#F3F1FA;"></iframe>'
                            );
                        })
                        ->columnSpanFull(),
                ]),
        ];
    }

    /**
     * @return array<int, Component>
     */
    private static function copyFields(string $locale): array
    {
        $requiredWhen = fn (Get $get): bool => in_array(
            $get('language'),
            [$locale, Newsletter::LANGUAGE_BOTH],
            true
        );
        $defaults = Newsletter::defaultAttributes();

        return [
            TextInput::make('subject_'.$locale)
                ->label('Subject')
                ->maxLength(180)
                ->default($defaults['subject_'.$locale])
                ->required($requiredWhen)
                ->columnSpanFull(),
            TextInput::make('preheader_'.$locale)
                ->label('Inbox preview')
                ->maxLength(180)
                ->default($defaults['preheader_'.$locale])
                ->helperText('The short line some inboxes show after the subject.')
                ->columnSpanFull(),
            TextInput::make('eyebrow_'.$locale)
                ->label('Label')
                ->maxLength(80)
                ->default($defaults['eyebrow_'.$locale]),
            TextInput::make('headline_'.$locale)
                ->label('Headline')
                ->maxLength(180)
                ->default($defaults['headline_'.$locale])
                ->required($requiredWhen),
            Textarea::make('intro_'.$locale)
                ->label('Opening')
                ->rows(3)
                ->default($defaults['intro_'.$locale])
                ->helperText('Write {name} to insert the person’s first name. A greeting is added above this.')
                ->columnSpanFull(),
            Textarea::make('body_'.$locale)
                ->label('Body')
                ->rows(5)
                ->default($defaults['body_'.$locale])
                ->helperText('Leave a blank line between paragraphs.')
                ->columnSpanFull(),
            Repeater::make('highlights_'.$locale)
                ->label('Highlights')
                ->schema([
                    TextInput::make('text')
                        ->label('Line')
                        ->required()
                        ->maxLength(180),
                ])
                ->default($defaults['highlights_'.$locale])
                ->addActionLabel('Add highlight')
                ->reorderable()
                ->columnSpanFull(),
            TextInput::make('cta_label_'.$locale)
                ->label('Button label')
                ->maxLength(80)
                ->default($defaults['cta_label_'.$locale])
                ->required($requiredWhen),
            TextInput::make('cta_url_'.$locale)
                ->label('Button link')
                ->url()
                ->default($defaults['cta_url_'.$locale])
                ->required($requiredWhen)
                ->helperText('Billing page for this language.')
                ->columnSpanFull(),
            Textarea::make('footer_'.$locale)
                ->label('Footer')
                ->rows(2)
                ->default($defaults['footer_'.$locale])
                ->columnSpanFull(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Newsletter::STATUS_SENT => 'success',
                        Newsletter::STATUS_SENDING => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('audience')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Newsletter::AUDIENCE_PAID => 'Paid',
                        Newsletter::AUDIENCE_ALL => 'Everyone',
                        Newsletter::AUDIENCE_PLAN => 'One plan',
                        Newsletter::AUDIENCE_CUSTOM => 'Address list',
                        default => 'Free users',
                    }),
                TextColumn::make('language')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Newsletter::LANGUAGE_AR => 'Arabic',
                        Newsletter::LANGUAGE_EN => 'English',
                        default => 'English and Arabic',
                    }),
                TextColumn::make('sent_count')
                    ->label('Sent')
                    ->sortable(),
                TextColumn::make('queued_deliveries_count')
                    ->label('Queued')
                    ->counts('queuedDeliveries'),
                TextColumn::make('failed_count')
                    ->label('Failed')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        Newsletter::STATUS_DRAFT => 'Draft',
                        Newsletter::STATUS_SENDING => 'Sending',
                        Newsletter::STATUS_SENT => 'Sent',
                    ]),
                SelectFilter::make('audience')
                    ->options([
                        Newsletter::AUDIENCE_FREE => 'Free users',
                        Newsletter::AUDIENCE_PAID => 'Paid',
                        Newsletter::AUDIENCE_ALL => 'Everyone',
                        Newsletter::AUDIENCE_PLAN => 'One plan',
                        Newsletter::AUDIENCE_CUSTOM => 'Address list',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->action(function (Newsletter $record, $livewire): void {
                        $copy = $record->duplicateAsDraft();
                        $livewire->redirect(static::getUrl('edit', ['record' => $copy]));
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No newsletters yet')
            ->emptyStateDescription('Create a draft. Nothing is emailed until you send it.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function cleanAttributes(array $data): array
    {
        unset($data['preview_locale'], $data['delivery_summary']);

        foreach (['plan_id', 'reply_to', 'from_name', 'from_address', 'custom_emails', 'internal_notes'] as $field) {
            if (array_key_exists($field, $data) && blank($data[$field])) {
                $data[$field] = null;
            }
        }

        return Arr::only($data, (new Newsletter)->getFillable());
    }

    public static function getRelations(): array
    {
        return [
            DeliveriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNewsletters::route('/'),
            'create' => CreateNewsletter::route('/create'),
            'edit' => EditNewsletter::route('/{record}/edit'),
        ];
    }
}
