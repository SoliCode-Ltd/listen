<?php

namespace App\Filament\Pages;

use App\Filament\Resources\UserResource;
use App\Models\Plan;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Billing\ExtraCredits;
use App\Services\Newsletter\CreditsEndedEmail;
use App\Services\Newsletter\NewsletterPace;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Newsletter → Credits ended: the email a paid member gets when their credits
 * run out, its words, a preview, a test send, every member who is out of credits
 * now with whether it went to them, and who got it lately.
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
                    ->description('Goes out by itself the moment a member on a paid plan runs out of credits (less than a minute of voice left), wherever it happens: the studio, the browser extension or the developer API. Once for each credit period, and again only if they add extra credits and use those up too. Its button opens the billing page with the extra-credits window, in the browser (also on a phone with the app, where nothing can be paid). Only members who can buy extra credits get it: nothing is sent while extra credits are switched off (Billing Settings), to free members, or while a renewal payment is overdue.')
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
                        TextInput::make('cta_label_en')->label('Button text (opens the extra-credits window on the billing page)')->maxLength(80),
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
                Section::make('Members who already ran out')
                    ->description('Members whose credits had already run out before this email existed never got it: it goes out when a request is refused, and they may not have tried since. The button at the top sends it to them, a few a minute, so the mailbox is never flooded. Each member is checked again just before their email goes: anyone who renewed, bought credits or got it meanwhile is left out, and nobody gets it twice.')
                    ->schema([
                        Placeholder::make('backfill')
                            ->hiddenLabel()
                            ->content(fn (): HtmlString => $this->backfillStatus()),
                        Placeholder::make('ran_out')
                            ->hiddenLabel()
                            ->content(fn (): HtmlString => $this->ranOutTable()),
                    ]),
                Section::make('Sent lately')
                    ->description('Members this email went to in the last 30 days.')
                    ->collapsed()
                    ->schema([
                        Placeholder::make('sent')
                            ->hiddenLabel()
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
            Action::make('sendAlreadyOut')
                ->label('Send to members who already ran out')
                ->icon('heroicon-o-users')
                ->visible(fn (): bool => ! $this->backfillRunning())
                ->modalHeading('Send this email to members who already ran out')
                ->modalDescription(fn (): string => $this->alreadyOutDescription())
                ->modalSubmitActionLabel('Send it to them')
                ->modalSubmitAction(fn (Action $action) => $this->alreadyOutCount() > 0 ? $action : false)
                ->action(function (): void {
                    $email = app(CreditsEndedEmail::class);
                    $count = $email->startBackfill((int) auth()->id());
                    $this->alreadyOutCount = null;
                    Cache::forget(self::RAN_OUT_CACHE);
                    if ($count === 0) {
                        Notification::make()->title('Nothing was sent')->body($email->backfillBlocker() ?? 'No member is waiting for this email now.')->warning()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Sending to '.number_format($count).' '.($count === 1 ? 'member' : 'members'))
                        ->body('About '.$this->minutesFor($count).'. The progress shows below; you can stop it at any time.')
                        ->success()
                        ->send();
                }),
            Action::make('stopAlreadyOut')
                ->label('Stop sending to members who already ran out')
                ->icon('heroicon-o-stop')
                ->color('danger')
                ->visible(fn (): bool => $this->backfillRunning())
                ->requiresConfirmation()
                ->modalHeading('Stop sending?')
                ->modalDescription('Members not reached yet get nothing. Those already sent it keep it. You can start again later: nobody gets it twice.')
                ->modalSubmitActionLabel('Stop sending')
                ->action(function (): void {
                    app(CreditsEndedEmail::class)->stopBackfill();
                    Cache::forget(self::RAN_OUT_CACHE);
                    Notification::make()->title('Stopped')->success()->send();
                }),
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

    /** Counted once each time the send window opens (it looks at every member on a paid plan). */
    public ?int $alreadyOutCount = null;

    private function alreadyOutCount(): int
    {
        return $this->alreadyOutCount ??= count(app(CreditsEndedEmail::class)->alreadyOut());
    }

    private function alreadyOutDescription(): string
    {
        $this->alreadyOutCount = null;
        $email = app(CreditsEndedEmail::class);
        if (($blocker = $email->backfillBlocker()) !== null) {
            return 'It cannot be sent now. '.$blocker;
        }

        $count = $this->alreadyOutCount();
        if ($count === 0) {
            return 'Nobody is waiting for it: every member on a paid plan who has run out has already had it for their current credit period.';
        }

        return number_format($count).' '.($count === 1 ? 'member on a paid plan has' : 'members on a paid plan have').' run out of credits and not had this email for their current credit period. '
            .'It goes to them a few a minute (about '.$this->minutesFor($count).'), with your saved words, and waits while a newsletter batch is going out. '
            .'Each one is checked again just before it is sent. Save any changes to the words before you send.';
    }

    private function minutesFor(int $count): string
    {
        $minutes = (int) ceil($count / max(1, app(NewsletterPace::class)->perMinute()));

        return $minutes <= 1 ? 'a minute' : ($minutes < 90 ? $minutes.' minutes' : round($minutes / 60, 1).' hours');
    }

    private function backfillRunning(): bool
    {
        $state = app(CreditsEndedEmail::class)->backfill();

        return $state !== null && $state['ids'] !== [];
    }

    private function backfillStatus(): HtmlString
    {
        $state = app(CreditsEndedEmail::class)->backfill();
        $when = fn (?string $iso): string => $iso ? Carbon::parse($iso)->utc()->format('j M Y, H:i').' UTC' : '';
        if ($state === null) {
            return new HtmlString('Not sent yet. Press <b>Send to members who already ran out</b> at the top to see how many members it would go to before anything is sent.');
        }

        $line = number_format($state['sent']).' of '.number_format($state['total']).' sent, '.number_format($state['skipped']).' left out';
        if ($state['ids'] !== []) {
            return new HtmlString('<span wire:poll.20s>Sending now: '.e($line).', '.number_format(count($state['ids'])).' waiting (about '.e($this->minutesFor(count($state['ids']))).'). Started '.e($when($state['started_at'])).'.</span>');
        }

        $line .= $state['not_reached'] > 0 ? ', '.number_format($state['not_reached']).' not reached' : '';

        return new HtmlString(e(($state['stopped'] ? 'Stopped on ' : 'Finished on ').$when($state['finished_at']).': '.$line.'.')
            .'<br><span style="opacity:.7">"Left out" are members who renewed, bought credits or got it on their own meanwhile, or whose address refused it three times.</span>');
    }

    /** The list below is worked out from every member on a paid plan, so it is kept for a minute. */
    private const RAN_OUT_CACHE = 'credits_ended:ran_out_list';

    private const RAN_OUT_SHOWN = 200;

    /**
     * Every member on a paid plan who is out of credits now, and whether this email went
     * to them for their current credit period (CreditsEndedEmail::ranOutMembers).
     */
    private function ranOutTable(): HtmlString
    {
        if (! ExtraCredits::ready()) {
            return new HtmlString('Run the migrations first.');
        }

        $sending = $this->backfillRunning();
        // Fresh while a send is going, so each row changes as its email leaves.
        $rows = Cache::remember(self::RAN_OUT_CACHE, $sending ? 15 : 60, fn (): array => app(CreditsEndedEmail::class)->ranOutMembers());
        if ($rows === []) {
            return new HtmlString('<div style="margin-top:6px;opacity:.75">No member on a paid plan is out of credits right now.</div>');
        }

        $count = array_count_values(array_column($rows, 'state'));
        $n = fn (string $state): int => (int) ($count[$state] ?? 0);
        $parts = array_filter([
            $n(CreditsEndedEmail::MAIL_SENT) > 0 ? number_format($n(CreditsEndedEmail::MAIL_SENT)).' sent' : null,
            $n(CreditsEndedEmail::MAIL_WAITING) > 0 ? number_format($n(CreditsEndedEmail::MAIL_WAITING)).' waiting in line' : null,
            $n(CreditsEndedEmail::MAIL_NOT_SENT) > 0 ? number_format($n(CreditsEndedEmail::MAIL_NOT_SENT)).' not sent yet' : null,
            $n(CreditsEndedEmail::MAIL_HELD) > 0 ? number_format($n(CreditsEndedEmail::MAIL_HELD)).' held back' : null,
        ]);
        $total = count($rows);
        $summary = number_format($total).' '.($total === 1 ? 'member on a paid plan is' : 'members on a paid plan are').' out of credits now: '.implode(', ', $parts).'.';

        $badge = fn (string $text, string $colour): string => '<span style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap;'.$colour.'">'.e($text).'</span>';
        $cell = 'padding:9px 12px;border-bottom:1px solid rgba(128,128,128,0.18);vertical-align:top;';
        $body = '';
        foreach (array_slice($rows, 0, self::RAN_OUT_SHOWN) as $row) {
            $status = match ($row['state']) {
                CreditsEndedEmail::MAIL_SENT => $badge('Sent', 'background:rgba(18,128,79,.14);color:#12804F;')
                    .'<div style="font-size:12px;opacity:.7;margin-top:3px">'.e($row['sent_at'] ? Carbon::parse($row['sent_at'])->utc()->format('j M Y, H:i').' UTC' : '').'</div>',
                CreditsEndedEmail::MAIL_WAITING => $badge('Waiting in line', 'background:rgba(118,89,250,.14);color:#7659FA;'),
                CreditsEndedEmail::MAIL_NOT_SENT => $badge('Not sent yet', 'background:rgba(232,154,26,.18);color:#B26A00;')
                    .'<div style="font-size:12px;opacity:.7;margin-top:3px">'.($sending ? 'Ran out after this send began' : 'Use the button at the top').'</div>',
                default => $badge('Held back', 'background:rgba(128,128,128,.18);color:inherit;')
                    .'<div style="font-size:12px;opacity:.7;margin-top:3px">'.e(ucfirst($row['why'])).'</div>',
            };
            $member = '<a href="'.e(UserResource::getUrl('view', ['record' => $row['user_id']])).'" style="font-weight:600;text-decoration:underline;text-underline-offset:2px">'.e($row['email'] !== '' ? $row['email'] : 'Member '.$row['user_id']).'</a>'
                .(filled($row['name']) ? '<div style="font-size:12px;opacity:.7;margin-top:3px">'.e($row['name']).'</div>' : '');
            $body .= '<tr>'
                .'<td style="'.$cell.'">'.$member.'</td>'
                .'<td style="'.$cell.'">'.e($row['plan'] !== '' ? $row['plan'] : '—').'</td>'
                .'<td style="'.$cell.'white-space:nowrap">'.e($row['renews_at'] ? Carbon::parse($row['renews_at'])->format('j M Y') : '—').'</td>'
                .'<td style="'.$cell.'">'.$status.'</td>'
                .'</tr>';
        }
        $head = 'padding:8px 12px;text-align:start;font-weight:600;';

        return new HtmlString('<div'.($sending ? ' wire:poll.20s' : '').' style="margin-top:10px">'
            .'<div style="font-weight:600;margin-bottom:8px">'.e($summary).'</div>'
            .'<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:14px;">'
            .'<thead><tr style="background:rgba(128,128,128,0.10);">'
            .'<th style="'.$head.'">Member</th>'
            .'<th style="'.$head.'">Plan</th>'
            .'<th style="'.$head.'">Credits renew</th>'
            .'<th style="'.$head.'">This email</th>'
            .'</tr></thead><tbody>'.$body.'</tbody></table></div>'
            .($total > self::RAN_OUT_SHOWN ? '<div style="font-size:12px;opacity:.7;margin-top:8px">The first '.self::RAN_OUT_SHOWN.' of '.number_format($total).' are shown.</div>' : '')
            .'<div style="font-size:12px;opacity:.7;margin-top:8px">Members who added extra credits or whose credits renewed since are no longer out, so they are not in this list: those who were sent the email are under "Sent lately" below. The list is worked out again every minute.</div>'
            .'</div>');
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
