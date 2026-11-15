<?php

namespace App\Filament\Resources\PurchaseFollowUpResource\Pages;

use App\Filament\Resources\PurchaseFollowUpResource;
use App\Models\PurchaseFollowUp;
use App\Models\User;
use App\Services\Billing\PurchaseFollowUps;
use App\Services\Billing\RenewalGrace;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Newsletter → Purchase follow-ups → Emails and settings: the two follow-up emails' on/off
 * switches and how long each waits, the words of all three emails (those two and the
 * warning a declined renewal gets) in English and Arabic, a preview and a test send.
 */
class PurchaseFollowUpEmails extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = PurchaseFollowUpResource::class;

    protected static ?string $title = 'Purchase follow-up emails';

    protected string $view = 'filament.pages.purchase-follow-up-emails';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = app(PurchaseFollowUps::class)->settings();
        foreach (['failed_enabled', 'left_enabled', 'discounts_enabled'] as $switch) {
            $settings[$switch] = $settings[$switch] === '1';
        }
        $this->form->fill($settings);
    }

    public function form(Schema $schema): Schema
    {
        $placeholders = '{name} is the member\'s first name and {plan} the plan they chose.';

        return $schema
            ->components([
                Section::make('Sending')
                    ->description('The two follow-up emails go out by themselves, from the live site only. A member gets at most one of them in '.PurchaseFollowUps::QUIET_DAYS.' days, and none once they have a paid plan. A member whose payment was declined gets that email, not the checkout reminder. The warning for a declined renewal (further down) has no switch or wait: it goes out the moment a paying member\'s renewal is declined, once for each renewal.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('failed_enabled')
                            ->label('Email a member whose first payment was declined')
                            ->helperText('Its button opens the payment page for that same payment, where they pay with another card. That page stays open for about a day; after that the button opens the checkout for the same plan.'),
                        TextInput::make('failed_after_minutes')
                            ->label('Wait before that email (minutes)')
                            ->numeric()->integer()->minValue(5)->maxValue(720)->required()
                            ->helperText('Recommended: 30. Many try another card at once; this leaves them time.'),
                        Toggle::make('left_enabled')
                            ->label('Remind a member who opened the checkout and left without paying'),
                        TextInput::make('left_after_hours')
                            ->label('Wait before the reminder (hours)')
                            ->numeric()->integer()->minValue(1)->maxValue(PurchaseFollowUps::TOO_LATE_HOURS)->required()
                            ->helperText('Recommended: 3. Opening the checkout again starts the wait again.'),
                        Toggle::make('discounts_enabled')
                            ->label('Allow discounts on declined renewals')
                            ->helperText('Shows the "Give a discount" button on a declined renewal in the list: a percentage off that renewal, or off that member\'s next renewals too, for the one member you choose. Off: no discount can be given.')
                            ->columnSpan(2),
                        Select::make('order')
                            ->label('Language order in the emails')
                            ->options(['ar_en' => 'Arabic, then English', 'en_ar' => 'English, then Arabic'])
                            ->required(),
                    ]),
                Section::make('Declined renewal: English')
                    ->description('For a paying member whose renewal was declined. Their plan stays on for the grace days ('.RenewalGrace::graceDays().' now, set in Billing Settings), and the button opens the payment page for that renewal.')
                    ->columns(2)
                    ->collapsible()
                    ->schema($this->wordFields('renewal', 'en', '{name} is the member\'s first name, {plan} their plan, {date} the day the plan stays on until. {reason} adds the bank\'s reason when it helps ("because the card did not have enough balance"), or nothing.')),
                Section::make('Declined renewal: العربية')
                    ->columns(2)
                    ->collapsible()
                    ->schema($this->wordFields('renewal', 'ar')),
                Section::make('Declined renewal with a discount: English')
                    ->description('For a declined renewal you gave a discount to (the "Give a discount" button on its case in the list). From then on that member\'s email is this one, not the warning above.')
                    ->columns(2)
                    ->collapsible()
                    ->schema($this->wordFields('renewal_offer', 'en', '{name} is the member\'s first name, {plan} their plan, {discount} the discount ("20%"), {price} what they pay now, {old_price} the full price, {date} the day the plan stays on until (empty when it has no grace days). {next} adds a sentence when the discount also covers their next renewals ("The same discount also comes off your next 3 renewals."), or nothing when it is for this renewal only; the preview shows it for 3.')),
                Section::make('Declined renewal with a discount: العربية')
                    ->columns(2)
                    ->collapsible()
                    ->schema($this->wordFields('renewal_offer', 'ar')),
                Section::make('Declined payment: English')
                    ->columns(2)
                    ->collapsible()
                    ->schema($this->wordFields('failed', 'en', $placeholders.' {reason} adds the bank\'s reason when it helps ("because the card did not have enough balance"), or nothing.')),
                Section::make('Declined payment: العربية')
                    ->columns(2)
                    ->collapsible()
                    ->schema($this->wordFields('failed', 'ar')),
                Section::make('Checkout left: English')
                    ->columns(2)
                    ->collapsible()
                    ->schema($this->wordFields('left', 'en', $placeholders)),
                Section::make('Checkout left: العربية')
                    ->columns(2)
                    ->collapsible()
                    ->schema($this->wordFields('left', 'ar')),
            ])
            ->statePath('data');
    }

    /**
     * @return array<int, Component|Field>
     */
    private function wordFields(string $prefix, string $lang, ?string $help = null): array
    {
        $rtl = $lang === 'ar' ? ['dir' => 'rtl'] : [];
        $button = match ($prefix) {
            'failed' => 'Button text (opens the payment page for that payment)',
            'renewal', 'renewal_offer' => 'Button text (opens the payment page for that renewal)',
            default => 'Button text (opens the checkout for their plan)',
        };

        return array_values(array_filter([
            TextInput::make("{$prefix}_subject_{$lang}")->label('Subject')->required()->maxLength(200)->extraInputAttributes($rtl)->columnSpan(2),
            TextInput::make("{$prefix}_headline_{$lang}")->label('Headline')->maxLength(200)->helperText($help)->extraInputAttributes($rtl)->columnSpan(2),
            Textarea::make("{$prefix}_body_{$lang}")->label($prefix === 'renewal' ? 'Message (the plan stays on for the grace days)' : 'Message')->rows(6)->required()->helperText($lang === 'en' ? 'A blank line starts a new paragraph.' : null)->extraInputAttributes($rtl)->columnSpan(2),
            $prefix === 'renewal'
                ? Textarea::make("{$prefix}_paused_body_{$lang}")->label('Message when there are no grace days (the first charge after a free trial: the paid features pause at once)')->rows(4)->required()->helperText($lang === 'en' ? '{date} is empty here.' : null)->extraInputAttributes($rtl)->columnSpan(2)
                : null,
            TextInput::make("{$prefix}_cta_label_{$lang}")->label($lang === 'en' ? $button : 'Button text')->maxLength(80)->extraInputAttributes($rtl),
            TextInput::make("{$prefix}_footer_{$lang}")->label('Small print at the bottom')->maxLength(300)->extraInputAttributes($rtl),
        ]));
    }

    public function save(): void
    {
        app(PurchaseFollowUps::class)->save($this->form->getState());

        Notification::make()->title('Saved')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        $preview = fn (string $kind, string $label, bool $withDiscount = false): Action => Action::make('preview_'.$kind.($withDiscount ? '_offer' : ''))
            ->label($label)
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->modalHeading($label.' (a sample member and plan)')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalWidth('3xl')
            ->modalContent(fn (): HtmlString => new HtmlString(
                '<iframe title="Email preview" style="width:100%;height:70vh;border:0;border-radius:12px;background:#fff" srcdoc="'
                .e(app(PurchaseFollowUps::class)->previewHtml($kind, $this->previewSettings(), $withDiscount)).'"></iframe>'
            ));

        return [
            Action::make('back')
                ->label('Back to the list')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(PurchaseFollowUpResource::getUrl('index')),
            $preview(PurchaseFollowUp::KIND_RENEWAL_FAILED, 'Preview: declined renewal'),
            $preview(PurchaseFollowUp::KIND_RENEWAL_FAILED, 'Preview: renewal with a discount', true),
            $preview(PurchaseFollowUp::KIND_PAYMENT_FAILED, 'Preview: declined payment'),
            $preview(PurchaseFollowUp::KIND_CHECKOUT_LEFT, 'Preview: checkout left'),
            Action::make('sendTest')
                ->label('Send a test')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->modalDescription('Saves your changes and sends one copy of the chosen email to the address below, for a sample plan. No member gets anything and nothing is recorded.')
                ->schema([
                    Select::make('kind')
                        ->label('Which email')
                        ->options([
                            PurchaseFollowUp::KIND_RENEWAL_FAILED => 'Declined renewal',
                            'renewal_offer' => 'Declined renewal with a discount',
                            PurchaseFollowUp::KIND_PAYMENT_FAILED => 'Declined payment',
                            PurchaseFollowUp::KIND_CHECKOUT_LEFT => 'Checkout left',
                        ])
                        ->default(PurchaseFollowUp::KIND_PAYMENT_FAILED)
                        ->required(),
                    TextInput::make('email')
                        ->label('Send the test to')
                        ->email()
                        ->required()
                        ->default(fn (): string => (string) auth()->user()?->email),
                ])
                ->action(function (array $data): void {
                    $this->save();
                    $this->sendTest((string) $data['kind'], (string) $data['email']);
                }),
        ];
    }

    private function sendTest(string $kind, string $to): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        $followUps = app(PurchaseFollowUps::class);
        try {
            $followUps->send($kind === 'renewal_offer'
                ? $followUps->sample(PurchaseFollowUp::KIND_RENEWAL_FAILED, $user, true)
                : $followUps->sample($kind, $user), $to);
        } catch (Throwable $e) {
            Notification::make()->title('The email was not sent')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        Notification::make()->title('Test sent')->body('Sent to '.$to.'.')->success()->send();
    }

    /**
     * The saved settings with what is typed in the form now, so a preview shows unsaved words.
     *
     * @return array<string, string>
     */
    private function previewSettings(): array
    {
        $state = $this->form->getRawState();
        $settings = app(PurchaseFollowUps::class)->settings();
        foreach ($settings as $key => $value) {
            if (isset($state[$key]) && is_scalar($state[$key]) && trim((string) $state[$key]) !== '') {
                $settings[$key] = is_bool($state[$key]) ? ($state[$key] ? '1' : '0') : (string) $state[$key];
            }
        }

        return $settings;
    }
}
