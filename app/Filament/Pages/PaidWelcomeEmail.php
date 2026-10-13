<?php

namespace App\Filament\Pages;

use App\Models\Subscription;
use App\Models\User;
use App\Services\Newsletter\PaidWelcomeEmail as WelcomeEmail;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * Newsletter → Paid welcome email: the words of the email new paid members get,
 * a preview, a test send, and sending it to any paid member by hand.
 */
class PaidWelcomeEmail extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'Newsletter';

    protected static ?string $navigationLabel = 'Paid welcome email';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Welcome email for paid members';

    protected static string $view = 'filament.pages.paid-welcome-email';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = app(WelcomeEmail::class)->settings();
        $settings['enabled'] = $settings['enabled'] === '1';
        $this->form->fill($settings);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Sending')
                    ->description('Goes out by itself each time a member starts a paid plan: after paying online, after a trial is paid, when you set a paid plan for them in Subscriptions, or when they move to another paid plan. Renewals of the same plan do not send it. Members on an unpaid trial do not get it until the trial is paid. You can always send it again by hand with the buttons above.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('enabled')
                            ->label('Send it automatically to new paid members')
                            ->columnSpan(2),
                        Forms\Components\Select::make('order')
                            ->label('Language order in the email')
                            ->options(['ar_en' => 'Arabic, then English', 'en_ar' => 'English, then Arabic'])
                            ->required(),
                        Forms\Components\TextInput::make('review_url')
                            ->label('Review link (the button)')
                            ->url()
                            ->required()
                            ->default(WelcomeEmail::REVIEW_URL),
                        Forms\Components\Placeholder::make('tracking')
                            ->label('Status')
                            ->content(fn (): string => WelcomeEmail::canTrack()
                                ? 'Ready.'
                                : 'Ready. Run the database migration to keep the list of who was welcomed.')
                            ->columnSpan(2),
                    ]),
                Forms\Components\Section::make('English')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('subject_en')->label('Subject')->required()->maxLength(200)->columnSpan(2),
                        Forms\Components\TextInput::make('headline_en')->label('Headline')->maxLength(200)->helperText('{name} becomes the member\'s first name, {plan} the name of their paid plan (in this language).')->columnSpan(2),
                        Forms\Components\Textarea::make('body_en')->label('Message')->rows(6)->required()->helperText('A blank line starts a new paragraph. {name} and {plan} work here too, and in the subject.')->columnSpan(2),
                        Forms\Components\TextInput::make('cta_label_en')->label('Button text')->maxLength(80),
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
                Forms\Components\Section::make('Recently welcomed')
                    ->collapsed()
                    ->schema([
                        Forms\Components\Placeholder::make('recent')
                            ->label('')
                            ->content(fn (): HtmlString|string => $this->recentTable()),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        app(WelcomeEmail::class)->save($this->form->getState());

        Notification::make()->title('Welcome email saved')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalHeading('Preview (sample name)')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalWidth('3xl')
                ->modalContent(fn (): HtmlString => new HtmlString(
                    '<iframe title="Welcome email preview" style="width:100%;height:70vh;border:0;border-radius:12px;background:#fff" srcdoc="'
                    .e(app(WelcomeEmail::class)->previewHtml($this->previewSettings())).'"></iframe>'
                )),
            Action::make('sendTest')
                ->label('Send a test')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->modalDescription('Saves your changes and sends one copy to the address below. No member gets anything and nothing is recorded.')
                ->form([
                    Forms\Components\TextInput::make('email')
                        ->label('Send the test to')
                        ->email()
                        ->required()
                        ->default(fn (): string => (string) auth()->user()?->email),
                ])
                ->action(function (array $data): void {
                    $this->save();
                    $this->sendTo(auth()->user(), $data['email']);
                }),
            Action::make('sendToMember')
                ->label('Send to a member')
                ->icon('heroicon-o-envelope')
                ->modalDescription('Saves your changes and sends the welcome email to one paid member now, even if they already had it.')
                ->form([
                    Forms\Components\Select::make('user_id')
                        ->label('Paid member')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => self::paidMemberOptions($search))
                        ->getOptionLabelUsing(fn ($value): ?string => ($user = User::query()->find($value)) ? self::memberLabel($user) : null)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->save();
                    $user = User::query()->find($data['user_id'] ?? 0);
                    if ($user) {
                        $this->sendTo($user);
                    }
                }),
        ];
    }

    private function sendTo(?User $user, ?string $testTo = null): void
    {
        if (! $user) {
            return;
        }

        try {
            app(WelcomeEmail::class)->send($user, $testTo);
        } catch (\Throwable $e) {
            Notification::make()->title('The email was not sent')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        Notification::make()
            ->title($testTo ? 'Test sent' : 'Welcome email sent')
            ->body('Sent to '.($testTo ?? $user->email).'.')
            ->success()
            ->send();
    }

    /**
     * @return array<string, string>
     */
    private function previewSettings(): array
    {
        $state = $this->form->getRawState();
        $settings = app(WelcomeEmail::class)->settings();
        foreach ($settings as $key => $value) {
            if (isset($state[$key]) && is_string($state[$key]) && trim($state[$key]) !== '') {
                $settings[$key] = $state[$key];
            }
        }

        return $settings;
    }

    /**
     * Members whose current plan is a paid one, matched by name or email.
     *
     * @return array<int, string>
     */
    public static function paidMemberOptions(string $search): array
    {
        $search = trim($search);

        return User::query()
            ->whereIn('id', Subscription::query()
                ->select('user_id')
                ->where('status', 'active')
                ->whereHas('plan', fn ($plan) => $plan->where('price_cents', '>', 0)))
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('email', 'like', '%'.$search.'%')
                ->orWhere('name', 'like', '%'.$search.'%')))
            ->orderBy('name')
            ->limit(30)
            ->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => self::memberLabel($user)])
            ->all();
    }

    private static function memberLabel(User $user): string
    {
        $sent = WelcomeEmail::canTrack() && $user->paid_welcome_sent_at
            ? ' — welcomed '.$user->paid_welcome_sent_at->format('Y-m-d')
            : '';

        return trim($user->name.' ('.$user->email.')').$sent;
    }

    private function recentTable(): HtmlString|string
    {
        if (! WelcomeEmail::canTrack()) {
            return 'Run the database migration to keep a record of who was welcomed.';
        }

        $users = User::query()->whereNotNull('paid_welcome_sent_at')->latest('paid_welcome_sent_at')->limit(25)->get(['id', 'name', 'email', 'paid_welcome_sent_at']);
        if ($users->isEmpty()) {
            return 'Nobody has been welcomed yet.';
        }

        $cell = 'padding:8px 12px;border-bottom:1px solid rgba(0,0,0,0.06);';
        $rows = $users->map(fn (User $user): string => '<tr>'
            .'<td style="'.$cell.'">'.e($user->paid_welcome_sent_at?->format('Y-m-d H:i')).'</td>'
            .'<td style="'.$cell.'">'.e($user->name).'</td>'
            .'<td style="'.$cell.'">'.e($user->email).'</td>'
            .'</tr>')->implode('');

        return new HtmlString('<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:14px;">'
            .'<thead><tr style="background:rgba(0,0,0,0.03);font-weight:600;">'
            .'<th style="padding:8px 12px;text-align:left;">Sent</th>'
            .'<th style="padding:8px 12px;text-align:left;">Name</th>'
            .'<th style="padding:8px 12px;text-align:left;">Email</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table></div>');
    }
}
