<?php

namespace App\Filament\Pages;

use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Newsletter\PlanEndReminder;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * Newsletter → Reminder: the email a plan that does not renew by itself gets before
 * it ends, how many days before, a preview and a test send.
 */
class PlanEndReminderEmail extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationGroup = 'Newsletter';

    protected static ?string $navigationLabel = 'Reminder';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Reminder before a plan ends';

    protected static string $view = 'filament.pages.plan-end-reminder-email';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = app(PlanEndReminder::class)->settings();
        $settings['enabled'] = $settings['enabled'] === '1';
        $this->form->fill($settings);
    }

    public function form(Form $form): Form
    {
        $placeholders = '{name} is the member\'s first name, {plan} their plan, {date} the day it ends, {when} "in 3 days" / "tomorrow", {days} the number of days.';

        return $form
            ->schema([
                Forms\Components\Section::make('Sending')
                    ->description('Goes out by itself, every morning at 9:00 (UTC), to members whose plan does not renew by itself: paid by InstaPay or Vodafone Cash, or given by the team. Members who pay by card never get it: their card renews the plan, and Stripe sends them its own reminder. A member who renews or gets a new end date is reminded again for the new date.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('enabled')
                            ->label('Send the reminder automatically')
                            ->columnSpan(2),
                        Forms\Components\TextInput::make('first_days')
                            ->label('First reminder: days before the plan ends')
                            ->numeric()->integer()->minValue(1)->maxValue(PlanEndReminder::MAX_DAYS)->required()
                            ->helperText('Recommended: 3. Close enough to act on, with time to transfer and for you to approve it.'),
                        Forms\Components\TextInput::make('second_days')
                            ->label('Last reminder: days before the plan ends')
                            ->numeric()->integer()->minValue(0)->maxValue(PlanEndReminder::MAX_DAYS)->required()
                            ->helperText('Recommended: 1 (the day before). 0 sends only the first. Must be fewer days than the first.'),
                        Forms\Components\Select::make('order')
                            ->label('Language order in the email')
                            ->options(['ar_en' => 'Arabic, then English', 'en_ar' => 'English, then Arabic'])
                            ->required(),
                    ]),
                Forms\Components\Section::make('English')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('subject_en')->label('Subject')->required()->maxLength(200)->columnSpan(2),
                        Forms\Components\TextInput::make('headline_en')->label('Headline')->maxLength(200)->helperText($placeholders)->columnSpan(2),
                        Forms\Components\Textarea::make('body_en')->label('Message')->rows(6)->required()->helperText('A blank line starts a new paragraph. The placeholders work here and in the subject too.')->columnSpan(2),
                        Forms\Components\TextInput::make('cta_label_en')->label('Button text (opens Manage subscription)')->maxLength(80),
                        Forms\Components\TextInput::make('footer_en')->label('Small print at the bottom')->maxLength(300),
                    ]),
                Forms\Components\Section::make('العربية')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('subject_ar')->label('Subject')->required()->maxLength(200)->extraInputAttributes(['dir' => 'rtl'])->columnSpan(2),
                        Forms\Components\TextInput::make('headline_ar')->label('Headline')->maxLength(200)->extraInputAttributes(['dir' => 'rtl'])->columnSpan(2),
                        Forms\Components\Textarea::make('body_ar')->label('Message')->rows(6)->required()->extraInputAttributes(['dir' => 'rtl'])->columnSpan(2),
                        Forms\Components\TextInput::make('cta_label_ar')->label('Button text')->maxLength(80)->extraInputAttributes(['dir' => 'rtl']),
                        Forms\Components\TextInput::make('footer_ar')->label('Small print at the bottom')->maxLength(300)->extraInputAttributes(['dir' => 'rtl']),
                    ]),
                Forms\Components\Section::make('Coming up')
                    ->description('Plans that do not renew by card and end in the next 14 days.')
                    ->collapsed()
                    ->schema([
                        Forms\Components\Placeholder::make('upcoming')
                            ->label('')
                            ->content(fn (): HtmlString|string => $this->upcomingTable()),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        if ((int) $state['second_days'] > 0 && (int) $state['second_days'] >= (int) $state['first_days']) {
            Notification::make()->title('Not saved')->body('The last reminder must be fewer days before the end than the first (or 0 for none).')->danger()->send();

            return;
        }

        app(PlanEndReminder::class)->save($state);

        Notification::make()->title('Reminder saved')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalHeading('Preview (a plan ending in the first reminder\'s days)')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalWidth('3xl')
                ->modalContent(fn (): HtmlString => new HtmlString(
                    '<iframe title="Reminder preview" style="width:100%;height:70vh;border:0;border-radius:12px;background:#fff" srcdoc="'
                    .e(app(PlanEndReminder::class)->previewHtml($this->previewSettings())).'"></iframe>'
                )),
            Action::make('sendTest')
                ->label('Send a test')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->modalDescription('Saves your changes and sends one copy to the address below, for your own plan (or a sample one) ending in the first reminder\'s days. No member gets anything and nothing is recorded.')
                ->form([
                    Forms\Components\TextInput::make('email')
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

        $reminder = app(PlanEndReminder::class);
        $days = $reminder->days()['first'];
        $subscription = Subscription::query()->where('user_id', $user->id)->latest('id')->first()?->replicate()
            ?? new Subscription;
        if (! $subscription->plan_id) {
            $subscription->plan_id = Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->orderBy('price_cents')->value('id');
        }
        // Shown as if it ends in the first reminder's days; nothing is saved.
        $subscription->current_period_end = now()->addDays($days);

        try {
            $reminder->send($user, $subscription, $days, $to);
        } catch (\Throwable $e) {
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
        $settings = app(PlanEndReminder::class)->settings();
        foreach ($settings as $key => $value) {
            if (isset($state[$key]) && is_scalar($state[$key]) && trim((string) $state[$key]) !== '') {
                $settings[$key] = (string) $state[$key];
            }
        }

        return $settings;
    }

    private function upcomingTable(): HtmlString|string
    {
        $plans = Subscription::query()
            ->with(['user:id,name,email', 'plan:id,name,price_cents'])
            ->where('status', 'active')
            ->whereBetween('current_period_end', [now(), now()->addDays(14)])
            ->whereHas('plan', fn ($q) => $q->where('price_cents', '>', 0))
            ->whereNotIn('user_id', BillingSubscription::query()->select('user_id')->whereIn('stripe_status', ['active', 'trialing', 'past_due']))
            ->orderBy('current_period_end')
            ->limit(50)
            ->get();
        if ($plans->isEmpty()) {
            return 'No plan paid by hand ends in the next 14 days.';
        }

        $cell = 'padding:8px 12px;border-bottom:1px solid rgba(0,0,0,0.06);';
        $rows = $plans->map(fn (Subscription $s): string => '<tr>'
            .'<td style="'.$cell.'">'.e($s->current_period_end?->format('Y-m-d H:i')).'</td>'
            .'<td style="'.$cell.'">'.e($s->user?->email).'</td>'
            .'<td style="'.$cell.'">'.e($s->plan?->name).'</td>'
            .'<td style="'.$cell.'">'.e(collect([$s->billing_reminder_7d_sent_at ? 'first '.$s->billing_reminder_7d_sent_at->format('M j') : null, $s->billing_reminder_1d_sent_at ? 'last '.$s->billing_reminder_1d_sent_at->format('M j') : null])->filter()->implode(', ') ?: '—').'</td>'
            .'</tr>')->implode('');

        return new HtmlString('<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:14px;">'
            .'<thead><tr style="background:rgba(0,0,0,0.03);font-weight:600;">'
            .'<th style="padding:8px 12px;text-align:left;">Ends (UTC)</th>'
            .'<th style="padding:8px 12px;text-align:left;">Member</th>'
            .'<th style="padding:8px 12px;text-align:left;">Plan</th>'
            .'<th style="padding:8px 12px;text-align:left;">Reminders sent</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table></div>');
    }
}
