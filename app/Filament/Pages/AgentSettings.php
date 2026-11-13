<?php

namespace App\Filament\Pages;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Placeholder;
use App\Models\SiteSetting;
use App\Support\Agents\AgentAccess;
use App\Support\Agents\AgentCost;
use App\Support\Credits;
use App\Support\VoiceCloningAccess;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * Listen Agents for the whole site (App\Support\Agents\AgentAccess): who sees
 * them, the credits a call minute uses, the free test minutes, and the safety
 * switches (stop every call now, daily ceilings, the check before an agent
 * goes live), with what a call minute costs and earns on each plan. Which
 * plans include agents, and their limits, are set on each plan.
 */
class AgentSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string | \UnitEnum | null $navigationGroup = 'Agents';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Agents Settings';

    protected string $view = 'filament.pages.agent-settings';

    private const GROUP = 'agents';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'agents_audience' => AgentAccess::audience(),
            // Empty unless the admin saved a rate of their own: then the rate follows the plans.
            'agents_credits_per_minute' => AgentAccess::rateIsSet() ? AgentAccess::creditsPerMinute() : null,
            // The same for a written chat reply.
            'agents_chat_credits_per_reply' => (int) AgentAccess::setting('agents_chat_credits_per_reply', 0) > 0 ? (int) AgentAccess::setting('agents_chat_credits_per_reply', 0) : null,
            'agents_free_test_minutes' => round(AgentAccess::freeTestSeconds() / 60, 1),
            'agents_calls_stopped' => AgentAccess::callsStopped(),
            'agents_site_daily_minutes' => AgentAccess::siteDailyMinutes(),
            'agents_free_daily_minutes' => AgentAccess::freeDailyMinutes(),
            'agents_house_daily_minutes' => AgentAccess::houseDailyMinutes(),
            'agents_house_charged' => AgentAccess::houseCharged(),
            'agents_review' => AgentAccess::reviewOn(),
            'agents_caller_transcribe' => AgentAccess::callerTranscribe(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Who sees Agents')
                    ->schema([
                        Radio::make('agents_audience')
                            ->label('Agents in the menu, the homepage, the Agents page and the dashboard')
                            ->options([
                                AgentAccess::AUDIENCE_EVERYONE => 'Everyone',
                                AgentAccess::AUDIENCE_PREVIEW => 'Admin email only',
                                AgentAccess::AUDIENCE_OFF => 'Off for everyone',
                            ])
                            ->descriptions([
                                AgentAccess::AUDIENCE_EVERYONE => 'Members build and test agents; plans with "Include voice agents" (Plans → Voice agents) put them live.',
                                AgentAccess::AUDIENCE_PREVIEW => 'Only '.VoiceCloningAccess::PREVIEW_EMAIL.' sees Agents, to try them before launch. Everyone else gets "page not found", and the homepage has no Agents section.',
                                AgentAccess::AUDIENCE_OFF => 'Nobody sees Agents. Agents already live keep answering their callers (use "Stop all calls now" below to stop them).',
                            ])
                            ->required(),
                    ]),
                Section::make('What a call minute uses')
                    ->description('Calls are paid from the member\'s plan credits, the same credits as their voice-overs (900 credits make one audio minute). A call minute costs Listen several times an audio minute, so it uses more credits.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('agents_credits_per_minute')
                            ->label('Credits a call minute uses')
                            ->numeric()->integer()->minValue(Credits::PER_MINUTE)->maxValue(Credits::PER_MINUTE * 10)->step(100)
                            ->suffix('credits')
                            ->placeholder(fn (): string => number_format(self::recommendedRate()).' (follows the plans)')
                            ->live(onBlur: true)
                            ->helperText(fn (Get $get): string => sprintf('Leave it empty and it follows the plans: now %s, the fewest credits that keep %d%% on every plan for a typical call (the plan whose credits cost least sets it, with its yearly price), so members get the most call minutes their plan can give. It changes by itself when plan prices or credits change. Type a number only to fix it. %s An agent with a great deal to know uses more on a long call, so no call earns less.',
                                number_format(self::recommendedRate()), (int) round(AgentCost::MARGIN * 100),
                                filled($get('agents_credits_per_minute'))
                                    ? 'Now fixed at '.number_format((int) $get('agents_credits_per_minute')).' ('.rtrim(rtrim(number_format(max(1, (int) $get('agents_credits_per_minute')) / Credits::PER_MINUTE, 2), '0'), '.').' audio minutes a call minute).'
                                    : '')),
                        TextInput::make('agents_free_test_minutes')
                            ->label('Free test minutes for each member')
                            ->numeric()->minValue(0)->maxValue(30)->step(0.5)
                            ->suffix('minutes')
                            ->required()
                            ->helperText('Once per member, across all their agents: their own test calls, before they pay for calls.'),
                        Toggle::make('agents_house_charged')
                            ->label('Listen\'s own agents use their owner\'s credits')
                            ->helperText('Their calls (visitors\' and test calls) take credits from the account that owns them, like a member\'s agent, so you see what they use. A call that account cannot pay for in full (no plan with agents, or its minutes or credits used up) goes on free: visitors are never turned away. Off: all their calls are free.')
                            ->columnSpan(2),
                        Placeholder::make('agents_plans')
                            ->label('Each plan, at this rate')
                            ->content(fn (Get $get): HtmlString => self::plansTable(filled($get('agents_credits_per_minute')) ? max(1, (int) $get('agents_credits_per_minute')) : self::recommendedRate()))
                            ->columnSpan(2),
                    ]),
                Section::make('What a chat reply uses')
                    ->description('A written chat is paid the same way: every reply the agent gives uses a fixed number of the member\'s plan credits. The member sees this number on their agent\'s Setup tab, beside the cost of a call minute.')
                    ->schema([
                        TextInput::make('agents_chat_credits_per_reply')
                            ->label('Credits a chat reply uses')
                            ->numeric()->integer()->minValue(5)->maxValue(5000)->step(5)
                            ->suffix('credits')
                            ->placeholder(fn (): string => number_format(AgentCost::recommendedChatRate() ?? (int) config('listen.agents.chat_credits_per_reply', 60)).' (follows the plans)')
                            ->helperText(fn (): string => self::chatRateHelp()),
                    ]),
                Section::make('Safety')
                    ->description('Ceilings on what calls can cost, whatever happens: a flood of calls on one agent, accounts made only for the free minutes, or a problem nobody has seen yet.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('agents_calls_stopped')
                            ->label('Stop all calls now')
                            ->helperText('No call starts on any agent, Listen\'s own agent and test calls included; callers hear the agent is not available. Calls already on finish. Switch it off to start again.')
                            ->onColor('danger')
                            ->columnSpan(2),
                        TextInput::make('agents_site_daily_minutes')
                            ->label('Members\' calls a day, all agents together')
                            ->numeric()->integer()->minValue(0)->maxValue(1000000)
                            ->suffix('minutes')
                            ->required()
                            ->helperText(fn (): string => '0: no limit. Past it, callers hear the agent is busy until midnight (UTC). At the heavy call\'s cost, 1,000 minutes is about $'.number_format(1000 * AgentCost::heavy(), 0).'.'),
                        TextInput::make('agents_free_daily_minutes')
                            ->label('Free test minutes a day, all members together')
                            ->numeric()->integer()->minValue(0)->maxValue(100000)
                            ->suffix('minutes')
                            ->required()
                            ->helperText('Past it, free test calls wait for tomorrow ("busy, try again later"); paid test calls go on.'),
                        TextInput::make('agents_house_daily_minutes')
                            ->label('Listen\'s own agent, minutes a day')
                            ->numeric()->integer()->minValue(0)->maxValue(100000)
                            ->suffix('minutes')
                            ->required()
                            ->helperText('The agent visitors talk to on Listen\'s public pages: its own ceiling, so a flood there never stops the members\' agents.'),
                        Toggle::make('agents_review')
                            ->label('Check each agent before it first goes live')
                            ->helperText('The text model reads what the agent is told and stops one set up to collect card numbers or passwords, pose as a bank, a government office or a real person, or anything harmful. Agents it is unsure of go live and are marked for you (Agents → filter "Marked for review").'),
                        Toggle::make('agents_caller_transcribe')
                            ->label('Write each caller line again from the caller\'s voice')
                            ->helperText('The live call\'s own transcription sometimes writes Arabic as English ("unbelievable") or in Latin letters, and spaces phone numbers out. On: each caller line is written again by the text model from that line\'s sound, in the agent\'s dialect, for the caller\'s screen and the call\'s transcript (about a second later, a fraction of a cent a line). The sound is never kept. Off: the live transcription as it is.')
                            ->columnSpan(2),
                    ]),
                Section::make('Real calls so far')
                    ->schema([
                        Placeholder::make('agents_measured')
                            ->label('Last 30 days')
                            ->content(function (): string {
                                $m = AgentCost::measured();
                                if ($m['calls'] === 0 || $m['per_minute'] === null) {
                                    return 'No calls with reported costs yet.';
                                }

                                return sprintf('%s calls, %s minutes billed, about $%s in all: $%s a call minute.',
                                    number_format($m['calls']), number_format($m['minutes'], 1), number_format($m['dollars'], 2), number_format($m['per_minute'], 3));
                            }),
                    ]),
            ])
            ->statePath('data');
    }

    /** The rate the plans call for (AgentCost::recommendedRate), or the config before any paid plan. */
    public static function recommendedRate(): int
    {
        return AgentCost::recommendedRate() ?? (int) config('listen.agents.credits_per_minute', 8100);
    }

    private static function plansTable(int $rate): HtmlString
    {
        $rows = AgentCost::plans($rate);
        if ($rows === []) {
            return new HtmlString('No paid plans yet.');
        }
        $money = fn (float $v): string => '$'.number_format($v, 3);
        $html = '<div style="overflow-x:auto"><table style="width:100%;font-size:.85rem;border-collapse:collapse">'
            .'<thead><tr style="text-align:start;opacity:.7"><th style="padding:.35rem .5rem">Plan</th><th style="padding:.35rem .5rem">Agents</th><th style="padding:.35rem .5rem">Price</th><th style="padding:.35rem .5rem">Call minutes the credits pay for</th><th style="padding:.35rem .5rem">A call minute earns</th><th style="padding:.35rem .5rem">Margin, typical call</th><th style="padding:.35rem .5rem">Margin, heavy call</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $html .= '<tr style="border-top:1px solid rgba(127,127,127,.2)">'
                .'<td style="padding:.35rem .5rem"><b>'.e($r['plan']).'</b></td>'
                .'<td style="padding:.35rem .5rem">'.($r['included'] ? 'Included' : 'Off').'</td>'
                .'<td style="padding:.35rem .5rem">$'.number_format($r['price'], 2).'</td>'
                .'<td style="padding:.35rem .5rem">'.number_format($r['minutes']).'</td>'
                .'<td style="padding:.35rem .5rem">'.$money($r['earns'])
                    .($r['earns_yearly'] !== null ? ' <span style="opacity:.7">('.$money($r['earns_yearly']).' yearly)</span>' : '')
                    .($r['earns_offer'] !== null ? ' <span style="opacity:.7">('.$money($r['earns_offer']).' in an offer month)</span>' : '').'</td>'
                .'<td style="padding:.35rem .5rem">'.($r['typical_margin'] !== null ? $r['typical_margin'].'%' : '—')
                    .($r['typical_margin_yearly'] !== null ? ' <span style="opacity:.7">('.$r['typical_margin_yearly'].'% yearly)</span>' : '').'</td>'
                .'<td style="padding:.35rem .5rem">'.($r['heavy_margin'] !== null ? $r['heavy_margin'].'%' : '—').'</td>'
                .'</tr>';
        }
        $html .= '</tbody></table></div>';
        $html .= '<p style="margin-top:.5rem;font-size:.8rem;opacity:.75">'.sprintf('A call minute costs Listen about $%s on a typical call (3 minutes, an agent with notes) and $%s on a heavy one (10 minutes, every note and file slot full): the live voice bills every turn again for the agent\'s instructions and the conversation so far.',
            number_format(AgentCost::typical(), 3), number_format(AgentCost::heavy(), 3)).'</p>';

        return new HtmlString($html);
    }

    /** Under the chat rate: what it follows, and what real replies have cost so far. */
    private static function chatRateHelp(): string
    {
        $measured = AgentCost::measuredChat();
        $price = (array) config('listen.agents.chat_price_per_million', []);

        return sprintf('Leave it empty and it follows the plans: now %s, the fewest credits that keep %d%% on a typical agent on every plan. An agent with a great deal to know uses more, since every reply reads all of it. The cost is worked out from the text model\'s prices in the server\'s settings ($%s in and $%s out per million tokens): check them against what the model really charges. %s',
            number_format(AgentCost::recommendedChatRate() ?? (int) config('listen.agents.chat_credits_per_reply', 60)), (int) round(AgentCost::MARGIN * 100),
            rtrim(rtrim(number_format((float) ($price['in'] ?? 0.5), 3), '0'), '.'), rtrim(rtrim(number_format((float) ($price['out'] ?? 3.0), 3), '0'), '.'),
            $measured['replies'] > 0
                ? sprintf('Real replies in the last 30 days: %s, $%s each on average at those prices.', number_format($measured['replies']), number_format((float) $measured['per_reply'], 5))
                : 'No reply has been given yet.');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $audience = (string) ($state['agents_audience'] ?? AgentAccess::AUDIENCE_EVERYONE);
        SiteSetting::set('agents_audience', in_array($audience, [AgentAccess::AUDIENCE_PREVIEW, AgentAccess::AUDIENCE_OFF], true) ? $audience : AgentAccess::AUDIENCE_EVERYONE, self::GROUP);
        // Empty: the rate follows the plans. A number fixes it.
        $rate = (int) ($state['agents_credits_per_minute'] ?? 0);
        SiteSetting::set('agents_credits_per_minute', $rate > 0 ? (string) max(Credits::PER_MINUTE, min(Credits::PER_MINUTE * 10, $rate)) : '', self::GROUP);
        $reply = (int) ($state['agents_chat_credits_per_reply'] ?? 0);
        SiteSetting::set('agents_chat_credits_per_reply', $reply > 0 ? (string) max(5, min(5000, $reply)) : '', self::GROUP);
        SiteSetting::set('agents_free_test_seconds', (string) max(0, min(1800, (int) round(((float) ($state['agents_free_test_minutes'] ?? 2)) * 60))), self::GROUP);
        SiteSetting::set('agents_calls_stopped', ! empty($state['agents_calls_stopped']) ? '1' : '0', self::GROUP);
        SiteSetting::set('agents_site_daily_minutes', (string) max(0, (int) ($state['agents_site_daily_minutes'] ?? 0)), self::GROUP);
        SiteSetting::set('agents_free_daily_minutes', (string) max(0, (int) ($state['agents_free_daily_minutes'] ?? 120)), self::GROUP);
        SiteSetting::set('agents_house_daily_minutes', (string) max(0, (int) ($state['agents_house_daily_minutes'] ?? 180)), self::GROUP);
        SiteSetting::set('agents_review', ! empty($state['agents_review']) ? '1' : '0', self::GROUP);
        SiteSetting::set('agents_house_charged', ! empty($state['agents_house_charged']) ? '1' : '0', self::GROUP);
        SiteSetting::set('agents_caller_transcribe', ! empty($state['agents_caller_transcribe']) ? '1' : '0', self::GROUP);
        SiteSetting::flushGroupCache(self::GROUP);

        Notification::make()->title('Agents settings saved.')->success()->send();
    }
}
