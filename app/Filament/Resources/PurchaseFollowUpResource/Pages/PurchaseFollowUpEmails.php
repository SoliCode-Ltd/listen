<?php

namespace App\Filament\Resources\PurchaseFollowUpResource\Pages;

use App\Filament\Resources\PurchaseFollowUpResource;
use App\Models\PurchaseFollowUp;
use App\Models\User;
use App\Services\Billing\PurchaseFollowUps;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Newsletter → Purchase follow-ups → Emails and settings: the two emails' on/off switches,
 * how long each waits, their words in English and Arabic, a preview and a test send.
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
        foreach (['failed_enabled', 'left_enabled'] as $switch) {
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
                    ->description('Both emails go out by themselves, from the live site only. A member gets at most one of them in '.PurchaseFollowUps::QUIET_DAYS.' days, and none once they have a paid plan. A member whose payment was declined gets that email, not the checkout reminder.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('failed_enabled')
                            ->label('Email a member whose first payment was declined')
                            ->helperText('With a button back to the checkout for the same plan.'),
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
                        Select::make('order')
                            ->label('Language order in the emails')
                            ->options(['ar_en' => 'Arabic, then English', 'en_ar' => 'English, then Arabic'])
                            ->required(),
                    ]),
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
     * @return array<int, \Filament\Schemas\Components\Component|\Filament\Forms\Components\Field>
     */
    private function wordFields(string $prefix, string $lang, ?string $help = null): array
    {
        $rtl = $lang === 'ar' ? ['dir' => 'rtl'] : [];

        return [
            TextInput::make("{$prefix}_subject_{$lang}")->label('Subject')->required()->maxLength(200)->extraInputAttributes($rtl)->columnSpan(2),
            TextInput::make("{$prefix}_headline_{$lang}")->label('Headline')->maxLength(200)->helperText($help)->extraInputAttributes($rtl)->columnSpan(2),
            Textarea::make("{$prefix}_body_{$lang}")->label('Message')->rows(6)->required()->helperText($lang === 'en' ? 'A blank line starts a new paragraph.' : null)->extraInputAttributes($rtl)->columnSpan(2),
            TextInput::make("{$prefix}_cta_label_{$lang}")->label($lang === 'en' ? 'Button text (opens the checkout for their plan)' : 'Button text')->maxLength(80)->extraInputAttributes($rtl),
            TextInput::make("{$prefix}_footer_{$lang}")->label('Small print at the bottom')->maxLength(300)->extraInputAttributes($rtl),
        ];
    }

    public function save(): void
    {
        app(PurchaseFollowUps::class)->save($this->form->getState());

        Notification::make()->title('Saved')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        $preview = fn (string $kind, string $label): Action => Action::make('preview_'.$kind)
            ->label($label)
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->modalHeading($label.' (a sample member and plan)')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalWidth('3xl')
            ->modalContent(fn (): HtmlString => new HtmlString(
                '<iframe title="Email preview" style="width:100%;height:70vh;border:0;border-radius:12px;background:#fff" srcdoc="'
                .e(app(PurchaseFollowUps::class)->previewHtml($kind, $this->previewSettings())).'"></iframe>'
            ));

        return [
            Action::make('back')
                ->label('Back to the list')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(PurchaseFollowUpResource::getUrl('index')),
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
            $followUps->send($followUps->sample($kind, $user), $to);
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
