<?php

namespace App\Filament\Pages;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Newsletter\CancellationEmail;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Newsletter → Cancellation: the email a member gets the moment they cancel their
 * subscription, its words, a preview of both versions and a test send.
 */
class CancellationEmailSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-hand-raised';

    protected static string | \UnitEnum | null $navigationGroup = 'Newsletter';

    protected static ?string $navigationLabel = 'Cancellation';

    protected static ?int $navigationSort = 7;

    protected static ?string $title = 'Email when a member cancels';

    protected static ?string $slug = 'cancellation-email';

    protected string $view = 'filament.pages.cancellation-email-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = app(CancellationEmail::class)->settings();
        $settings['enabled'] = $settings['enabled'] === '1';
        $this->form->fill($settings);
    }

    public function form(Schema $schema): Schema
    {
        $placeholders = '{name} is the member\'s first name, {plan} their plan, {date} the day their plan ends.';

        return $schema
            ->components([
                Section::make('Sending')
                    ->description('Goes out by itself the moment a member cancels (on the site or in Stripe), once per cancellation, from the help mailbox: replies come back to you. The message says what happens, then asks why they cancelled; a member who already chose a reason in the cancel dialog is thanked instead (Billing → Cancellations lists the reasons). Switched off, members get the plain English "Cancellation scheduled" notice instead.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Send this email when a member cancels')
                            ->columnSpan(2),
                        Select::make('order')
                            ->label('Language order in the email')
                            ->options(['ar_en' => 'Arabic, then English', 'en_ar' => 'English, then Arabic'])
                            ->required(),
                    ]),
                Section::make('English')
                    ->columns(2)
                    ->schema($this->languageFields('en', $placeholders)),
                Section::make('العربية')
                    ->columns(2)
                    ->schema($this->languageFields('ar', $placeholders)),
            ])
            ->statePath('data');
    }

    /**
     * @return array<int, mixed>
     */
    private function languageFields(string $lang, string $placeholders): array
    {
        $rtl = $lang === 'ar' ? ['dir' => 'rtl'] : [];

        return [
            TextInput::make('subject_'.$lang)->label('Subject')->required()->maxLength(200)->extraInputAttributes($rtl)->columnSpan(2),
            TextInput::make('headline_'.$lang)->label('Headline')->maxLength(200)->extraInputAttributes($rtl)
                ->helperText($lang === 'en' ? $placeholders : null)->columnSpan(2),
            Textarea::make('body_'.$lang)->label('What happens')->rows(4)->required()->extraInputAttributes($rtl)
                ->helperText($lang === 'en' ? 'A blank line starts a new paragraph. **Words** between two pairs of stars are bold.' : null)->columnSpan(2),
            Textarea::make('ask_'.$lang)->label('The question (when they gave no reason)')->rows(9)->required()->extraInputAttributes($rtl)->columnSpan(2),
            Textarea::make('thanks_'.$lang)->label('The thanks (when they chose a reason in the cancel dialog)')->rows(3)->required()->extraInputAttributes($rtl)->columnSpan(2),
            TextInput::make('keep_'.$lang)->label('Just above the button')->maxLength(300)->extraInputAttributes($rtl)->columnSpan(2),
            TextInput::make('cta_label_'.$lang)->label('Button text (opens Manage subscription)')->maxLength(80)->extraInputAttributes($rtl),
            TextInput::make('footer_'.$lang)->label('Small print at the bottom')->maxLength(300)->extraInputAttributes($rtl),
        ];
    }

    public function save(): void
    {
        app(CancellationEmail::class)->save($this->form->getState());

        Notification::make()->title('Cancellation email saved')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalHeading('Preview: a member who gave no reason')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalWidth('3xl')
                ->modalContent(fn (): HtmlString => $this->previewFrame(false)),
            Action::make('previewThanks')
                ->label('Preview with a reason')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalHeading('Preview: a member who chose a reason in the cancel dialog')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalWidth('3xl')
                ->modalContent(fn (): HtmlString => $this->previewFrame(true)),
            Action::make('sendTest')
                ->label('Send a test')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->modalDescription('Saves your changes and sends one copy (the version that asks why) to the address below, for your own plan or a sample one ending in 20 days. No member gets anything and nothing is recorded.')
                ->schema([
                    TextInput::make('email')
                        ->label('Send the test to')
                        ->email()
                        ->required()
                        ->default(fn (): string => (string) auth()->user()?->email),
                ])
                ->action(function (array $data): void {
                    $this->save();
                    $this->sendTest((string) $data['email']);
                }),
        ];
    }

    private function previewFrame(bool $reasonGiven): HtmlString
    {
        return new HtmlString(
            '<iframe title="Cancellation email preview" style="width:100%;height:70vh;border:0;border-radius:12px;background:#fff" srcdoc="'
            .e(app(CancellationEmail::class)->previewHtml($this->previewSettings(), $reasonGiven)).'"></iframe>'
        );
    }

    private function sendTest(string $to): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        $plan = Subscription::query()->with('plan')->where('user_id', $user->id)->latest('id')->first()?->plan
            ?? Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->orderBy('price_cents')->first();

        try {
            app(CancellationEmail::class)->send($user, $plan, now()->addDays(20), false, $to);
        } catch (Throwable $e) {
            Notification::make()->title('The email was not sent')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        Notification::make()->title('Test sent')->body('Sent to '.$to.'.')->success()->send();
    }

    /**
     * @return array<string, string>
     */
    private function previewSettings(): array
    {
        $state = $this->form->getRawState();
        $settings = app(CancellationEmail::class)->settings();
        foreach ($settings as $key => $value) {
            if (isset($state[$key]) && is_scalar($state[$key]) && trim((string) $state[$key]) !== '') {
                $settings[$key] = (string) $state[$key];
            }
        }

        return $settings;
    }
}
