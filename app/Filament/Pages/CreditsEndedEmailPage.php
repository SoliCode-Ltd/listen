<?php

namespace App\Filament\Pages;

use App\Models\Plan;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Billing\ExtraCredits;
use App\Services\Newsletter\CreditsEndedEmail;
use App\Services\PlanAccessService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
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
 * Newsletter → Credits ended: the email a paid member gets when their credits
 * run out, its words, a preview, a test send and who got it lately.
 */
class CreditsEndedEmailPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-battery-0';

    protected static string|\UnitEnum|null $navigationGroup = 'Newsletter';

    protected static ?string $navigationLabel = 'Credits ended';

    protected static ?int $navigationSort = 7;

    protected static ?string $title = 'Email when credits run out';

    protected static ?string $slug = 'credits-ended-email';

    protected string $view = 'filament.pages.credits-ended-email';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = app(CreditsEndedEmail::class)->settings();
        $settings['enabled'] = $settings['enabled'] === '1';
        $this->form->fill($settings);
    }

    public function form(Schema $schema): Schema
    {
        $placeholders = '{name} is the member\'s first name, {plan} their plan, {date} the day the plan\'s credits renew.';

        return $schema
            ->components([
                Section::make('Sending')
                    ->description('Goes out by itself the moment a member on a paid plan runs out of credits (less than a minute of voice left), wherever it happens: the studio, the browser extension or the developer API. Once for each credit period, and again only if they add extra credits and use those up too. Its button opens the studio with the extra-credits window. Only members who can buy extra credits get it: nothing is sent while extra credits are switched off (Billing Settings), to free members, or while a renewal payment is overdue.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Send the email automatically')
                            ->helperText(ExtraCredits::enabled() ? 'Extra credits are on, so this email can go out.' : 'Extra credits are off (Billing Settings → Extra credits), so nothing is sent yet.')
                            ->columnSpan(2),
                        Select::make('order')
                            ->label('Language order in the email')
                            ->options(['ar_en' => 'Arabic, then English', 'en_ar' => 'English, then Arabic'])
                            ->required(),
                    ]),
                Section::make('English')
                    ->columns(2)
                    ->schema([
                        TextInput::make('subject_en')->label('Subject')->required()->maxLength(200)->columnSpan(2),
                        TextInput::make('headline_en')->label('Headline')->maxLength(200)->helperText($placeholders)->columnSpan(2),
                        Textarea::make('body_en')->label('Message')->rows(6)->required()->helperText('A blank line starts a new paragraph. The placeholders work here and in the subject too.')->columnSpan(2),
                        TextInput::make('cta_label_en')->label('Button text (opens the studio\'s extra-credits window)')->maxLength(80),
                        TextInput::make('footer_en')->label('Small print at the bottom')->maxLength(300),
                    ]),
                Section::make('العربية')
                    ->columns(2)
                    ->schema([
                        TextInput::make('subject_ar')->label('Subject')->required()->maxLength(200)->extraInputAttributes(['dir' => 'rtl'])->columnSpan(2),
                        TextInput::make('headline_ar')->label('Headline')->maxLength(200)->extraInputAttributes(['dir' => 'rtl'])->columnSpan(2),
                        Textarea::make('body_ar')->label('Message')->rows(6)->required()->extraInputAttributes(['dir' => 'rtl'])->columnSpan(2),
                        TextInput::make('cta_label_ar')->label('Button text')->maxLength(80)->extraInputAttributes(['dir' => 'rtl']),
                        TextInput::make('footer_ar')->label('Small print at the bottom')->maxLength(300)->extraInputAttributes(['dir' => 'rtl']),
                    ]),
                Section::make('Sent lately')
                    ->description('Members this email went to in the last 30 days.')
                    ->collapsed()
                    ->schema([
                        Placeholder::make('sent')
                            ->label('')
                            ->content(fn (): HtmlString|string => $this->sentTable()),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        app(CreditsEndedEmail::class)->save($this->form->getState());

        Notification::make()->title('Email saved')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalHeading('Preview (a sample member)')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalWidth('3xl')
                ->modalContent(fn (): HtmlString => new HtmlString(
                    '<iframe title="Email preview" style="width:100%;height:70vh;border:0;border-radius:12px;background:#fff" srcdoc="'
                    .e(app(CreditsEndedEmail::class)->previewHtml($this->previewSettings())).'"></iframe>'
                )),
            Action::make('sendTest')
                ->label('Send a test')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->modalDescription('Saves your changes and sends one copy to the address below, as if your own credits had run out. No member gets anything and nothing is recorded.')
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

    private function sendTest(string $to): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        $plan = app(PlanAccessService::class)->resolveActivePlanForUserId((int) $user->id);
        if (! $plan || (int) $plan->price_cents <= 0) {
            $plan = Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->orderBy('price_cents')->first();
        }

        try {
            app(CreditsEndedEmail::class)->send($user, $plan, now()->addDays(12), $to);
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
        $settings = app(CreditsEndedEmail::class)->settings();
        foreach ($settings as $key => $value) {
            if (isset($state[$key]) && is_scalar($state[$key]) && trim((string) $state[$key]) !== '') {
                $settings[$key] = (string) $state[$key];
            }
        }

        return $settings;
    }

    private function sentTable(): HtmlString|string
    {
        if (! ExtraCredits::ready()) {
            return 'Run the migrations first.';
        }

        $rows = UsageCounter::query()
            ->with('user:id,name,email')
            ->whereNotNull('credits_ended_email_at')
            ->where('credits_ended_email_at', '>=', now()->subDays(30))
            ->latest('credits_ended_email_at')
            ->limit(50)
            ->get();
        if ($rows->isEmpty()) {
            return 'Nobody was sent this email in the last 30 days.';
        }

        $cell = 'padding:8px 12px;border-bottom:1px solid rgba(0,0,0,0.06);';
        $html = $rows->map(fn (UsageCounter $row): string => '<tr>'
            .'<td style="'.$cell.'">'.e($row->credits_ended_email_at?->format('Y-m-d H:i')).'</td>'
            .'<td style="'.$cell.'">'.e($row->user?->email ?? 'Deleted account').'</td>'
            .'<td style="'.$cell.'">'.e($row->monthLabel()).'</td>'
            .'<td style="'.$cell.'">'.e((int) $row->extra_chars > 0 ? number_format((int) $row->extra_chars) : '—').'</td>'
            .'</tr>')->implode('');

        return new HtmlString('<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:14px;">'
            .'<thead><tr style="background:rgba(0,0,0,0.03);font-weight:600;">'
            .'<th style="padding:8px 12px;text-align:left;">Sent (UTC)</th>'
            .'<th style="padding:8px 12px;text-align:left;">Member</th>'
            .'<th style="padding:8px 12px;text-align:left;">Credit period</th>'
            .'<th style="padding:8px 12px;text-align:left;">Extra credits in the period</th>'
            .'</tr></thead><tbody>'.$html.'</tbody></table></div>');
    }
}
