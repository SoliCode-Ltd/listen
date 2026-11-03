<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use App\Support\Agents\AgentAccess;
use App\Support\Agents\AgentCost;
use App\Support\Credits;
use App\Support\VoiceCloningAccess;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
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

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Agents';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Agents Settings';

    protected static string $view = 'filament.pages.agent-settings';

    private const GROUP = 'agents';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'agents_audience' => AgentAccess::audience(),
            'agents_credits_per_minute' => AgentAccess::creditsPerMinute(),
            'agents_free_test_minutes' => round(AgentAccess::freeTestSeconds() / 60, 1),
            'agents_calls_stopped' => AgentAccess::callsStopped(),
            'agents_site_daily_minutes' => AgentAccess::siteDailyMinutes(),
            'agents_free_daily_minutes' => AgentAccess::freeDailyMinutes(),
            'agents_house_daily_minutes' => AgentAccess::houseDailyMinutes(),
            'agents_review' => AgentAccess::reviewOn(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Who sees Agents')
                    ->schema([
                        Forms\Components\Radio::make('agents_audience')
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
                Forms\Components\Section::make('What a call minute uses')
                    ->description('Calls are paid from the member\'s plan credits, the same credits as their voice-overs (900 credits make one audio minute). A call minute costs Listen several times an audio minute, so it uses more credits.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('agents_credits_per_minute')
                            ->label('Credits a call minute uses')
                            ->numeric()->integer()->minValue(Credits::PER_MINUTE)->maxValue(Credits::PER_MINUTE * 10)->step(90)
                            ->suffix('credits')
                            ->required()
                            ->live(onBlur: true)
                            ->helperText(fn (Forms\Get $get): string => sprintf('%s audio minutes for each call minute. Members see their call time in minutes. Recommended: 1,800.',
                                rtrim(rtrim(number_format(max(1, (int) $get('agents_credits_per_minute')) / Credits::PER_MINUTE, 2), '0'), '.'))),
                        Forms\Components\TextInput::make('agents_free_test_minutes')
                            ->label('Free test minutes for each member')
                            ->numeric()->minValue(0)->maxValue(30)->step(0.5)
                            ->suffix('minutes')
                            ->required()
                            ->helperText('Once per member, across all their agents: their own test calls, before they pay for calls.'),
                        Forms\Components\Placeholder::make('agents_plans')
                            ->label('Each plan, at this rate')
                            ->content(fn (Forms\Get $get): HtmlString => self::plansTable(max(1, (int) $get('agents_credits_per_minute'))))
                            ->columnSpan(2),
                    ]),
                Forms\Components\Section::make('Safety')
                    ->description('Ceilings on what calls can cost, whatever happens: a flood of calls on one agent, accounts made only for the free minutes, or a problem nobody has seen yet.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('agents_calls_stopped')
                            ->label('Stop all calls now')
                            ->helperText('No call starts on any agent, Listen\'s own agent and test calls included; callers hear the agent is not available. Calls already on finish. Switch it off to start again.')
                            ->onColor('danger')
                            ->columnSpan(2),
                        Forms\Components\TextInput::make('agents_site_daily_minutes')
                            ->label('Members\' calls a day, all agents together')
                            ->numeric()->integer()->minValue(0)->maxValue(1000000)
                            ->suffix('minutes')
                            ->required()
                            ->helperText(fn (): string => '0: no limit. Past it, callers hear the agent is busy until midnight (UTC). At the heavy call\'s cost, 1,000 minutes is about $'.number_format(1000 * AgentCost::heavy(), 0).'.'),
                        Forms\Components\TextInput::make('agents_free_daily_minutes')
                            ->label('Free test minutes a day, all members together')
                            ->numeric()->integer()->minValue(0)->maxValue(100000)
                            ->suffix('minutes')
                            ->required()
                            ->helperText('Past it, free test calls wait for tomorrow ("busy, try again later"); paid test calls go on.'),
                        Forms\Components\TextInput::make('agents_house_daily_minutes')
                            ->label('Listen\'s own agent, minutes a day')
                            ->numeric()->integer()->minValue(0)->maxValue(100000)
                            ->suffix('minutes')
                            ->required()
                            ->helperText('The agent visitors talk to on Listen\'s public pages: its own ceiling, so a flood there never stops the members\' agents.'),
                        Forms\Components\Toggle::make('agents_review')
                            ->label('Check each agent before it first goes live')
                            ->helperText('The text model reads what the agent is told and stops one set up to collect card numbers or passwords, pose as a bank, a government office or a real person, or anything harmful. Agents it is unsure of go live and are marked for you (Agents → filter "Marked for review").'),
                    ]),
                Forms\Components\Section::make('Real calls so far')
                    ->schema([
                        Forms\Components\Placeholder::make('agents_measured')
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

    /** What a call minute earns on each plan on sale, against what one costs. */
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
                .'<td style="padding:.35rem .5rem">'.$money($r['earns']).($r['earns_offer'] !== null ? ' <span style="opacity:.7">('.$money($r['earns_offer']).' in an offer month)</span>' : '').'</td>'
                .'<td style="padding:.35rem .5rem">'.($r['typical_margin'] !== null ? $r['typical_margin'].'%' : '—').'</td>'
                .'<td style="padding:.35rem .5rem">'.($r['heavy_margin'] !== null ? $r['heavy_margin'].'%' : '—').'</td>'
                .'</tr>';
        }
        $html .= '</tbody></table></div>';
        $html .= '<p style="margin-top:.5rem;font-size:.8rem;opacity:.75">'.sprintf('A call minute costs Listen about $%s on a typical call (3 minutes, an agent with notes) and $%s on a heavy one (10 minutes, every note and file slot full): the live voice bills every turn again for the agent\'s instructions and the conversation so far.',
            number_format(AgentCost::typical(), 3), number_format(AgentCost::heavy(), 3)).'</p>';

        return new HtmlString($html);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $audience = (string) ($state['agents_audience'] ?? AgentAccess::AUDIENCE_EVERYONE);
        SiteSetting::set('agents_audience', in_array($audience, [AgentAccess::AUDIENCE_PREVIEW, AgentAccess::AUDIENCE_OFF], true) ? $audience : AgentAccess::AUDIENCE_EVERYONE, self::GROUP);
        SiteSetting::set('agents_credits_per_minute', (string) max(Credits::PER_MINUTE, min(Credits::PER_MINUTE * 10, (int) ($state['agents_credits_per_minute'] ?? 1800))), self::GROUP);
        SiteSetting::set('agents_free_test_seconds', (string) max(0, min(1800, (int) round(((float) ($state['agents_free_test_minutes'] ?? 2)) * 60))), self::GROUP);
        SiteSetting::set('agents_calls_stopped', ! empty($state['agents_calls_stopped']) ? '1' : '0', self::GROUP);
        SiteSetting::set('agents_site_daily_minutes', (string) max(0, (int) ($state['agents_site_daily_minutes'] ?? 0)), self::GROUP);
        SiteSetting::set('agents_free_daily_minutes', (string) max(0, (int) ($state['agents_free_daily_minutes'] ?? 120)), self::GROUP);
        SiteSetting::set('agents_house_daily_minutes', (string) max(0, (int) ($state['agents_house_daily_minutes'] ?? 180)), self::GROUP);
        SiteSetting::set('agents_review', ! empty($state['agents_review']) ? '1' : '0', self::GROUP);
        SiteSetting::flushGroupCache(self::GROUP);

        Notification::make()->title('Agents settings saved.')->success()->send();
    }
}
