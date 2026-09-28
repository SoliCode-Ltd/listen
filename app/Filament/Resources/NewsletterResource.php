<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NewsletterResource\Pages;
use App\Filament\Resources\NewsletterResource\RelationManagers\DeliveriesRelationManager;
use App\Models\Newsletter;
use App\Models\Plan;
use App\Services\Newsletter\NewsletterPace;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Arr;

class NewsletterResource extends Resource
{
    protected static ?string $model = Newsletter::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Newsletter';

    protected static ?string $navigationLabel = 'Newsletter';

    protected static ?string $modelLabel = 'newsletter';

    protected static ?string $pluralModelLabel = 'Newsletters';

    protected static ?string $slug = 'newsletter';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    public static function formSchema(): array
    {
        return [
            Forms\Components\Section::make('Campaign')
                ->description('Saving stores a draft. Nothing is emailed until you use Send example or Send next batch.')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Internal name')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Only visible in this admin. Recipients do not see it.'),
                    Forms\Components\Placeholder::make('delivery_summary')
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
                                ? ' Press Send next batch. The next '.$pace->perClick().' go out before this page finishes.'
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
                    Forms\Components\Textarea::make('internal_notes')
                        ->label('Internal notes')
                        ->rows(2)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('from_name')
                        ->label('From name')
                        ->maxLength(120)
                        ->default('Listen')
                        ->helperText('The name people see in their inbox.'),
                    Forms\Components\TextInput::make('from_address')
                        ->label('From address')
                        ->email()
                        ->maxLength(255)
                        ->default('help@listenai.studio')
                        ->helperText('Titan only delivers from the signed-in mailbox, help@listenai.studio. The password is saved under Newsletter → Mailbox.'),
                    Forms\Components\TextInput::make('reply_to')
                        ->label('Reply-to')
                        ->email()
                        ->maxLength(255)
                        ->helperText('Where replies should go. Leave empty to use the from address.'),
                ])
                ->columns(2),

            Forms\Components\Section::make('Who receives it')
                ->description('Free means a registered account that is not on an active paid plan. Turn voice cloning on for that paid plan before you send, or billing will not unlock it.')
                ->schema([
                    Forms\Components\Radio::make('audience')
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
                    Forms\Components\Select::make('plan_id')
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
                    Forms\Components\Textarea::make('custom_emails')
                        ->label('Addresses')
                        ->rows(5)
                        ->visible(fn (Get $get): bool => $get('audience') === Newsletter::AUDIENCE_CUSTOM)
                        ->required(fn (Get $get): bool => $get('audience') === Newsletter::AUDIENCE_CUSTOM)
                        ->helperText('One address per line. Commas and spaces also work.')
                        ->columnSpanFull(),
                    Forms\Components\Toggle::make('exclude_blocked')
                        ->label('Skip blocked accounts')
                        ->default(true),
                    Forms\Components\Toggle::make('exclude_unsubscribed')
                        ->label('Skip unsubscribed addresses')
                        ->default(true),
                    Forms\Components\Toggle::make('exclude_already_sent')
                        ->label('Skip people who already received this newsletter')
                        ->helperText('Example sends do not count. You can send the real newsletter to someone after mailing yourself a preview.')
                        ->default(true),
                    Forms\Components\Toggle::make('skip_phone_accounts')
                        ->label('Skip phone accounts without a real email')
                        ->helperText('Phone signups use an internal address ending in @users.listen.local.')
                        ->default(true),
                    Forms\Components\Toggle::make('exclude_admins')
                        ->label('Skip admin accounts')
                        ->helperText('Keeps staff out of the audience. Turn this off if an admin should receive the newsletter too.')
                        ->default(true),
                ])
                ->columns(2),

            Forms\Components\Section::make('Language')
                ->schema([
                    Forms\Components\Radio::make('language')
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
                    Forms\Components\Radio::make('bilingual_order')
                        ->label('Order inside the email')
                        ->options([
                            'en_ar' => 'English, then Arabic',
                            'ar_en' => 'Arabic, then English',
                        ])
                        ->default('en_ar')
                        ->visible(fn (Get $get): bool => $get('language') === Newsletter::LANGUAGE_BOTH)
                        ->required(fn (Get $get): bool => $get('language') === Newsletter::LANGUAGE_BOTH),
                ]),

            Forms\Components\Tabs::make('Copy')
                ->tabs([
                    Forms\Components\Tabs\Tab::make('English')->schema(static::copyFields('en')),
                    Forms\Components\Tabs\Tab::make('Arabic')->schema(static::copyFields('ar')),
                ])
                ->columnSpanFull(),

            Forms\Components\Section::make('Preview')
                ->description('The preview uses the names Alex and أحمد. Refresh after you edit. The unsubscribe link is added on the real send.')
                ->schema([
                    Forms\Components\Select::make('preview_locale')
                        ->label('Preview language')
                        ->options([
                            Newsletter::LANGUAGE_BOTH => 'English and Arabic',
                            Newsletter::LANGUAGE_EN => 'English',
                            Newsletter::LANGUAGE_AR => 'Arabic',
                        ])
                        ->default(Newsletter::LANGUAGE_BOTH)
                        ->dehydrated(false)
                        ->native(false),
                    Forms\Components\Placeholder::make('preview_frame')
                        ->label('Email')
                        ->content(function ($livewire): \Illuminate\Support\HtmlString {
                            $html = is_object($livewire) && property_exists($livewire, 'previewHtml')
                                ? (string) $livewire->previewHtml
                                : '';

                            if ($html === '') {
                                return new \Illuminate\Support\HtmlString('<p style="margin:0;color:#6B7280;">Refresh the preview to see the email.</p>');
                            }

                            return new \Illuminate\Support\HtmlString(
                                '<iframe srcdoc="'.e($html).'" title="Newsletter preview" style="width:100%;height:820px;border:1px solid #E6E4F2;border-radius:16px;background:#F3F1FA;"></iframe>'
                            );
                        })
                        ->columnSpanFull(),
                ]),
        ];
    }

    /**
     * @return array<int, Forms\Components\Component>
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
            Forms\Components\TextInput::make('subject_'.$locale)
                ->label('Subject')
                ->maxLength(180)
                ->default($defaults['subject_'.$locale])
                ->required($requiredWhen)
                ->columnSpanFull(),
            Forms\Components\TextInput::make('preheader_'.$locale)
                ->label('Inbox preview')
                ->maxLength(180)
                ->default($defaults['preheader_'.$locale])
                ->helperText('The short line some inboxes show after the subject.')
                ->columnSpanFull(),
            Forms\Components\TextInput::make('eyebrow_'.$locale)
                ->label('Label')
                ->maxLength(80)
                ->default($defaults['eyebrow_'.$locale]),
            Forms\Components\TextInput::make('headline_'.$locale)
                ->label('Headline')
                ->maxLength(180)
                ->default($defaults['headline_'.$locale])
                ->required($requiredWhen),
            Forms\Components\Textarea::make('intro_'.$locale)
                ->label('Opening')
                ->rows(3)
                ->default($defaults['intro_'.$locale])
                ->helperText('Write {name} to insert the person’s first name. A greeting is added above this.')
                ->columnSpanFull(),
            Forms\Components\Textarea::make('body_'.$locale)
                ->label('Body')
                ->rows(5)
                ->default($defaults['body_'.$locale])
                ->helperText('Leave a blank line between paragraphs.')
                ->columnSpanFull(),
            Forms\Components\Repeater::make('highlights_'.$locale)
                ->label('Highlights')
                ->schema([
                    Forms\Components\TextInput::make('text')
                        ->label('Line')
                        ->required()
                        ->maxLength(180),
                ])
                ->default($defaults['highlights_'.$locale])
                ->addActionLabel('Add highlight')
                ->reorderable()
                ->columnSpanFull(),
            Forms\Components\TextInput::make('cta_label_'.$locale)
                ->label('Button label')
                ->maxLength(80)
                ->default($defaults['cta_label_'.$locale])
                ->required($requiredWhen),
            Forms\Components\TextInput::make('cta_url_'.$locale)
                ->label('Button link')
                ->url()
                ->default($defaults['cta_url_'.$locale])
                ->required($requiredWhen)
                ->helperText('Billing page for this language.')
                ->columnSpanFull(),
            Forms\Components\Textarea::make('footer_'.$locale)
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
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Newsletter::STATUS_SENT => 'success',
                        Newsletter::STATUS_SENDING => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('audience')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Newsletter::AUDIENCE_PAID => 'Paid',
                        Newsletter::AUDIENCE_ALL => 'Everyone',
                        Newsletter::AUDIENCE_PLAN => 'One plan',
                        Newsletter::AUDIENCE_CUSTOM => 'Address list',
                        default => 'Free users',
                    }),
                Tables\Columns\TextColumn::make('language')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Newsletter::LANGUAGE_AR => 'Arabic',
                        Newsletter::LANGUAGE_EN => 'English',
                        default => 'English and Arabic',
                    }),
                Tables\Columns\TextColumn::make('sent_count')
                    ->label('Sent')
                    ->sortable(),
                Tables\Columns\TextColumn::make('queued_deliveries_count')
                    ->label('Queued')
                    ->counts('queuedDeliveries'),
                Tables\Columns\TextColumn::make('failed_count')
                    ->label('Failed')
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        Newsletter::STATUS_DRAFT => 'Draft',
                        Newsletter::STATUS_SENDING => 'Sending',
                        Newsletter::STATUS_SENT => 'Sent',
                    ]),
                Tables\Filters\SelectFilter::make('audience')
                    ->options([
                        Newsletter::AUDIENCE_FREE => 'Free users',
                        Newsletter::AUDIENCE_PAID => 'Paid',
                        Newsletter::AUDIENCE_ALL => 'Everyone',
                        Newsletter::AUDIENCE_PLAN => 'One plan',
                        Newsletter::AUDIENCE_CUSTOM => 'Address list',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->action(function (Newsletter $record, $livewire): void {
                        $copy = $record->duplicateAsDraft();
                        $livewire->redirect(static::getUrl('edit', ['record' => $copy]));
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListNewsletters::route('/'),
            'create' => Pages\CreateNewsletter::route('/create'),
            'edit' => Pages\EditNewsletter::route('/{record}/edit'),
        ];
    }
}
