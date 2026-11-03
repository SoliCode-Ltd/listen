<?php

namespace App\Http\Controllers;

use App\Jobs\ReviewAgent;
use App\Models\Agent;
use App\Models\AgentWidget;
use App\Models\AgentContact;
use App\Models\AgentConversation;
use App\Models\AgentSource;
use App\Services\Agents\AgentCalls;
use App\Services\Agents\AgentReview;
use App\Services\Agents\AgentWriter;
use App\Support\Agents\AgentAccess;
use App\Support\Agents\AgentBalance;
use App\Services\Agents\SiteReader;
use App\Services\Agents\WordPressPlugin;
use App\Support\Agents\AgentVoices;
use App\Support\ArabicDialects;
use App\Support\CreditPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Listen Agents, the owner's side: the list, the setup in four steps (the
 * business, its voice, talking to it, going live) and each agent's page.
 */
class AgentController extends Controller
{
    public function __construct(private AgentCalls $calls) {}

    public function index(Request $request): View|RedirectResponse
    {
        $agents = Agent::ownedBy($request->user())->with('widgets')->latest()->get();
        // Each card's numbers are for the month the call time above counts (the credit month).
        $since = CreditPeriod::current((int) $request->user()->id)['starts_at'];
        $calls = AgentConversation::query()->where('user_id', $request->user()->id)->where('started_at', '>=', $since)
            ->where('channel', '!=', 'test')->selectRaw('agent_id, count(*) as n, sum(billed_seconds) as s')->groupBy('agent_id')->get()->keyBy('agent_id');
        $lastCalls = AgentConversation::query()->where('user_id', $request->user()->id)->where('channel', '!=', 'test')
            ->selectRaw('agent_id, max(started_at) as last')->groupBy('agent_id')->pluck('last', 'agent_id');
        $customers = AgentContact::query()->where('user_id', $request->user()->id)->where('created_at', '>=', $since)
            ->selectRaw('agent_id, count(*) as n')->groupBy('agent_id')->pluck('n', 'agent_id');

        // Each agent's voice (its face and dialect on the card), one voice list per language.
        $arabicUi = app()->getLocale() === 'ar';
        $lists = [];
        $voices = $agents->mapWithKeys(function (Agent $agent) use (&$lists, $arabicUi) {
            if (! $agent->speaker_id) {
                return [$agent->id => null];
            }
            $lists[$agent->language] ??= collect(AgentVoices::all($agent->language, $arabicUi))->keyBy('id');

            return [$agent->id => $lists[$agent->language]->get($agent->speaker_id)];
        });

        return view('agents.index', [
            'agents' => $agents,
            'calls' => $calls,
            'lastCalls' => $lastCalls,
            'customers' => $customers,
            'since' => $since,
            'voices' => $voices,
            'balance' => AgentBalance::for($request->user()),
            'limits' => AgentAccess::limitsFor((int) $request->user()->id),
            // Callers who could not reach an agent today, and why (no credits, its daily limit ...).
            'missed' => $agents->mapWithKeys(fn (Agent $agent) => [$agent->id => AgentCalls::missedToday($agent)]),
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        if (Agent::ownedBy($request->user())->count() >= AgentAccess::limitsFor((int) $request->user()->id)['agents']) {
            return redirect()->route('voice-agents.index');
        }

        return view('agents.setup', $this->setupData($request, null));
    }

    public function edit(Request $request, Agent $agent): View
    {
        $this->own($request, $agent);

        return view('agents.setup', $this->setupData($request, $agent));
    }

    /** Step 1: the business, then the agent is written from it (and its website). */
    public function store(Request $request, SiteReader $reader, AgentWriter $writer): JsonResponse
    {
        $data = $this->validateBusiness($request);
        $most = AgentAccess::limitsFor((int) $request->user()->id)['agents'];
        if (Agent::ownedBy($request->user())->count() >= $most) {
            return response()->json(['message' => trans_choice('agents.error_max_agents', $most, ['count' => $most])], 422);
        }

        $agent = Agent::create($data + ['user_id' => $request->user()->id, 'status' => Agent::DRAFT]);

        return response()->json($this->writeAgent($agent, (string) $request->input('notes', ''), $reader, $writer));
    }

    /** Write the agent again from the business details (step 1 changed, or the website changed). */
    public function write(Request $request, Agent $agent, SiteReader $reader, AgentWriter $writer): JsonResponse
    {
        $this->own($request, $agent);
        $agent->fill($this->validateBusiness($request))->save();

        return response()->json($this->writeAgent($agent, (string) $request->input('notes', ''), $reader, $writer));
    }

    public function show(Request $request, Agent $agent): View
    {
        $this->own($request, $agent);
        $widget = $agent->mainWidget();
        // The same month as the call time on the agents list (the credit month).
        $monthStart = CreditPeriod::current((int) $request->user()->id)['starts_at'];
        $conversations = $agent->conversations()->latest('started_at')->limit(50)->get();
        $month = $agent->conversations()->where('started_at', '>=', $monthStart)->where('channel', '!=', 'test');

        return view('agents.show', [
            'agent' => $agent,
            'widget' => $widget,
            'voice' => $agent->speaker_id ? AgentVoices::find($agent->speaker_id, $agent->language, app()->getLocale() === 'ar') : null,
            'voices' => AgentVoices::all($agent->language, app()->getLocale() === 'ar'),
            'conversations' => $conversations,
            'contacts' => $agent->contacts()->latest('last_contact_at')->limit(100)->get(),
            'stats' => [
                'calls' => (clone $month)->count(),
                'seconds' => (int) (clone $month)->sum('billed_seconds'),
                'credits' => (int) (clone $month)->sum('credits'),
                'contacts' => $agent->contacts()->count(),
            ],
            'checklist' => $this->checklist($agent, $widget),
            'perMinute' => AgentCalls::creditsPerMinute(),
            'freeTestLeft' => $this->calls->freeTestSecondsLeft((int) $request->user()->id),
            'embed' => $this->embedCode($widget),
            'balance' => AgentBalance::for($request->user()),
            'limits' => AgentAccess::limitsFor((int) $request->user()->id),
            'missed' => AgentCalls::missedToday($agent),
            'refused' => $widget->lastRefused(),
        ]);
    }

    /** Any part of the agent, saved as the owner edits (voice, script, knowledge, choices, going live ...). */
    public function update(Request $request, Agent $agent): JsonResponse
    {
        $this->own($request, $agent);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'business_name' => ['sometimes', 'string', 'max:120'],
            'speaker_id' => ['sometimes', 'nullable', 'string', 'max:80'],
            // The dialect (or English accent) the agent speaks, like the studio's: any speaker can speak it.
            'dialect' => ['sometimes', 'string', 'max:20'],
            'first_message' => ['sometimes', 'nullable', 'string', 'max:400'],
            'knowledge' => ['sometimes', 'nullable', 'string', 'max:40000'],
            'script' => ['sometimes', 'array'],
            'script.identity' => ['nullable', 'string', 'max:1500'],
            'script.never' => ['nullable', 'array', 'max:12'],
            'script.never.*' => ['nullable', 'string', 'max:300'],
            'script.steps' => ['nullable', 'array', 'max:12'],
            'script.steps.*' => ['nullable', 'string', 'max:400'],
            'script.handoff' => ['nullable', 'string', 'max:800'],
            'choices' => ['sometimes', 'array', 'max:6'],
            'choices.*.question' => ['required', 'string', 'max:300'],
            'choices.*.answer' => ['required', 'string', 'max:300'],
            'max_call_seconds' => ['sometimes', 'integer', 'min:60', 'max:'.AgentAccess::limitsFor((int) $request->user()->id)['call_seconds']],
            // The owner's own ceiling on callers' minutes a day for this agent (0: none).
            'daily_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'status' => ['sometimes', 'in:ready,live,paused'],
            'allowed_origins' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'appearance' => ['sometimes', 'array'],
            'appearance.position' => ['nullable', 'in:start,end'],
            'appearance.theme' => ['nullable', 'in:auto,light,dark'],
            'appearance.label' => ['nullable', 'string', 'max:40'],
            'appearance.style' => ['nullable', 'in:'.implode(',', AgentWidget::STYLES)],
            'appearance.icon' => ['nullable', 'in:'.implode(',', AgentWidget::ICONS)],
            'appearance.size' => ['nullable', 'in:s,m,l'],
            'appearance.shape' => ['nullable', 'in:pill,round'],
            'appearance.accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'appearance.palette' => ['nullable', 'in:custom,'.implode(',', array_keys(AgentWidget::PALETTES))],
            'appearance.orb_colors' => ['nullable', 'array', 'size:2'],
            'appearance.orb_colors.*' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'appearance.radius' => ['nullable', 'in:square,soft,round'],
            'appearance.captions' => ['nullable', 'boolean'],
            'appearance.photo' => ['nullable', 'boolean'],
            'appearance.title' => ['nullable', 'string', 'max:60'],
            'appearance.subtitle' => ['nullable', 'string', 'max:80'],
            'appearance.font' => ['nullable', 'in:system,rounded,site'],
            'appearance.ready_text' => ['nullable', 'string', 'max:60'],
            'appearance.ready_show' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('dialect', $data)) {
            $known = $agent->language === 'en' ? \App\Support\EnglishAccents::ALL : ArabicDialects::ALL;
            if (! isset($known[$data['dialect']])) {
                return response()->json(['message' => __('agents.error_dialect')], 422);
            }
            $agent->dialect = $data['dialect'];
        }
        if (array_key_exists('speaker_id', $data)) {
            $voice = $data['speaker_id'] ? AgentVoices::find($data['speaker_id'], $agent->language, false) : null;
            $agent->speaker_id = $voice['id'] ?? null;
            $agent->live_voice = $voice['voice'] ?? null;
            if ($voice && $agent->status === Agent::DRAFT) {
                $agent->status = Agent::READY;
            }
        }
        if (isset($data['script'])) {
            $script = $agent->script ?? [];
            foreach (['identity', 'handoff'] as $k) {
                if (array_key_exists($k, $data['script'])) {
                    $script[$k] = trim((string) $data['script'][$k]);
                }
            }
            foreach (['never', 'steps'] as $k) {
                if (array_key_exists($k, $data['script'])) {
                    $script[$k] = array_values(array_filter(array_map(fn ($v) => trim((string) $v), (array) $data['script'][$k])));
                }
            }
            $agent->script = $script;
        }
        foreach (['name', 'business_name', 'first_message', 'knowledge', 'max_call_seconds'] as $k) {
            if (array_key_exists($k, $data)) {
                $agent->{$k} = $data[$k];
            }
        }
        // The tricky moments: the owner picks an answer; each question keeps the options it was set up with.
        if (array_key_exists('choices', $data)) {
            $before = collect((array) ($agent->choices ?? []))->keyBy(fn ($c) => (string) ($c['question'] ?? ''));
            $agent->choices = array_values(array_map(fn (array $c): array => [
                'question' => (string) $c['question'],
                'options' => array_values(array_filter((array) ($before->get((string) $c['question'])['options'] ?? []), 'is_string')),
                'answer' => (string) $c['answer'],
            ], (array) $data['choices']));
        }
        if (array_key_exists('daily_minutes', $data)) {
            $settings = (array) ($agent->settings ?? []);
            $settings['daily_minutes'] = (int) $data['daily_minutes'];
            $agent->settings = $settings;
        }
        if (isset($data['status'])) {
            if ($data['status'] === Agent::LIVE) {
                if ($refusal = $this->goLiveRefusal($request, $agent)) {
                    return response()->json($refusal, 422);
                }
                if (! $agent->went_live_at) {
                    $agent->went_live_at = now();
                }
            }
            $agent->status = $data['status'];
        }
        $setupChanged = $agent->isDirty(['name', 'business_name', 'first_message', 'knowledge', 'choices', 'script', 'dialect']);
        $agent->save();
        // A live agent whose setup changed is checked again in the background (paused if it now
        // deceives or harms callers).
        if ($setupChanged && $agent->isLive() && AgentAccess::reviewOn() && ! $agent->setting('house')
            && ($agent->setting('review.hash') !== AgentReview::hash($agent))) {
            ReviewAgent::dispatch($agent->id);
        }

        $widget = $agent->mainWidget();
        if (array_key_exists('allowed_origins', $data)) {
            $origins = array_values(array_unique(array_filter(array_map(
                fn ($o) => \App\Models\AgentWidget::normalizeOrigin($o),
                preg_split('/[\s,]+/', (string) $data['allowed_origins']) ?: [],
            ))));
            $widget->allowed_origins = array_slice($origins, 0, 10);
        }
        if (isset($data['appearance'])) {
            // Text fields may be emptied (back to the default words); the rest only change when sent.
            $look = array_merge($widget->appearance ?? [], array_filter($data['appearance'], fn ($v) => $v !== null));
            foreach (['label', 'title', 'subtitle', 'ready_text'] as $k) {
                if (array_key_exists($k, $data['appearance']) && blank($data['appearance'][$k])) {
                    $look[$k] = null;
                }
            }
            $widget->appearance = $look;
        }
        $widget->save();

        return response()->json(['ok' => true, 'agent' => $this->payload($agent)]);
    }

    /**
     * Why the agent cannot go live, or null: no voice yet, the team suspended it, the owner's plan
     * has no agents (with the plan that does), or the check found it set up to deceive or harm.
     *
     * @return array{message: string, reason: string, upgrade?: array<string, mixed>}|null
     */
    private function goLiveRefusal(Request $request, Agent $agent): ?array
    {
        if (! $agent->live_voice) {
            return ['message' => __('agents.voice_heading'), 'reason' => 'voice'];
        }
        if ($agent->setting('suspended')) {
            return ['message' => __('agents.error_suspended'), 'reason' => 'suspended'];
        }
        $limits = AgentAccess::limitsFor((int) $request->user()->id);
        if (! $limits['included']) {
            $upgrade = AgentAccess::upgrade(app()->getLocale() === 'ar');

            return [
                'message' => $upgrade['plan'] ? __('agents.error_plan_live', ['plan' => $upgrade['plan']]) : __('agents.error_plan_none'),
                'reason' => 'plan',
                'upgrade' => $upgrade,
            ];
        }
        $review = app(AgentReview::class)->check($agent);
        if ($review['verdict'] === AgentReview::BLOCK) {
            return ['message' => __('agents.error_review', ['reason' => $review['reason'] ?: __('agents.review_default')]), 'reason' => 'review'];
        }

        return null;
    }

    /** "Correct this answer" in the test call: the right answer joins what the agent knows. */
    public function correct(Request $request, Agent $agent): JsonResponse
    {
        $this->own($request, $agent);
        $data = $request->validate([
            'question' => ['nullable', 'string', 'max:500'],
            'answer' => ['required', 'string', 'max:1500'],
        ]);
        $line = trim(($data['question'] ? 'Q: '.trim($data['question'])."\nA: " : '').trim($data['answer']));
        $agent->knowledge = trim(trim((string) $agent->knowledge)."\n\n# Corrected by the owner\n".$line);
        $agent->save();
        AgentSource::create(['agent_id' => $agent->id, 'kind' => AgentSource::TEXT, 'title' => 'Correction', 'content' => $line, 'chars' => mb_strlen($line), 'status' => 'ready', 'read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** The owner's own test call (free up to the free test seconds, then from the plan). */
    public function testCall(Request $request, Agent $agent): JsonResponse
    {
        $this->own($request, $agent);
        if (! $agent->live_voice) {
            return response()->json(['message' => __('agents.voice_heading')], 422);
        }
        $result = $this->calls->start($agent, 'test', null, ['origin' => $request->getSchemeAndHttpHost(), 'visitor' => 'owner-'.$request->user()->id]);
        if (! $result['ok']) {
            // The owner hears exactly why (callers never do).
            $error = $result['error'];
            $message = match ($error) {
                'plan' => __('agents.error_plan_test'),
                'minutes' => __('agents.error_minutes'),
                'suspended' => __('agents.error_suspended'),
                'stopped' => __('agents.error_paused'),
                'credits' => __('agents.error_credits'),
                default => __('agents.error_busy'),
            };

            return response()->json(['message' => $message, 'reason' => $error], 422);
        }
        $agent->forceFill(['tested_at' => now()])->save();

        return response()->json(AgentCallController::startPayload($agent, $result));
    }

    public function destroy(Request $request, Agent $agent): RedirectResponse
    {
        $this->own($request, $agent);
        $agent->delete();

        return redirect()->route('voice-agents.index');
    }

    /** @return array<string, mixed> */
    private function setupData(Request $request, ?Agent $agent): array
    {
        $arabic = app()->getLocale() === 'ar';
        $dialects = [];
        foreach (ArabicDialects::ALL as $code => $d) {
            $dialects[] = ['code' => $code, 'label' => $d[$arabic ? 'ar' : 'en'], 'region' => $d['region']];
        }

        return [
            'agent' => $agent,
            'dialects' => $dialects,
            'voicesAr' => AgentVoices::all('ar', $arabic),
            'voicesEn' => AgentVoices::all('en', $arabic),
            'widget' => $agent?->mainWidget(),
            'embed' => $agent ? $this->embedCode($agent->mainWidget()) : null,
            'freeTestLeft' => $this->calls->freeTestSecondsLeft((int) $request->user()->id),
            'perMinute' => AgentCalls::creditsPerMinute(),
            'limits' => AgentAccess::limitsFor((int) $request->user()->id),
            'upgrade' => AgentAccess::upgrade($arabic),
        ];
    }

    /** @return array<string, mixed> */
    private function validateBusiness(Request $request): array
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:80'],
            'business_type' => ['required', 'in:'.implode(',', Agent::BUSINESS_TYPES)],
            'jobs' => ['nullable', 'array'],
            'jobs.*' => ['in:'.implode(',', Agent::JOBS)],
            'website_url' => ['nullable', 'string', 'max:500'],
            'language' => ['required', 'in:ar,en'],
            'dialect' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:8000'],
        ], ['business_name.required' => __('agents.error_name'), 'name.required' => __('agents.error_name')]);

        // Any Arabic dialect for an Arabic agent, any English accent for an English one.
        $dialect = $data['language'] === 'ar'
            ? (isset(ArabicDialects::ALL[$data['dialect'] ?? '']) ? $data['dialect'] : 'ar-XA-WH')
            : (isset(\App\Support\EnglishAccents::ALL[$data['dialect'] ?? '']) ? $data['dialect'] : 'en-US');

        return [
            'business_name' => trim($data['business_name']),
            'name' => trim($data['name']),
            'business_type' => $data['business_type'],
            'jobs' => array_values(array_unique($data['jobs'] ?? ['answer'])),
            'website_url' => ($data['website_url'] ?? '') !== '' ? SiteReader::normalizeUrl($data['website_url']) : null,
            'language' => $data['language'],
            'dialect' => $dialect,
        ];
    }

    /** @return array<string, mixed> */
    private function writeAgent(Agent $agent, string $notes, SiteReader $reader, AgentWriter $writer): array
    {
        $siteText = '';
        $siteOk = null;
        if ($agent->website_url) {
            $read = $reader->read($agent->website_url);
            $siteOk = $read['ok'];
            $siteText = $read['text'];
            AgentSource::updateOrCreate(['agent_id' => $agent->id, 'kind' => AgentSource::WEBSITE], [
                'url' => $agent->website_url, 'title' => $read['pages'][0]['title'] ?? null, 'content' => $read['text'],
                'chars' => mb_strlen($read['text']), 'status' => $read['ok'] ? 'ready' : 'failed', 'error' => $read['error'], 'read_at' => now(),
            ]);
        }
        if (trim($notes) !== '') {
            AgentSource::updateOrCreate(['agent_id' => $agent->id, 'kind' => AgentSource::TEXT, 'title' => 'Notes'], [
                'content' => trim($notes), 'chars' => mb_strlen(trim($notes)), 'status' => 'ready', 'read_at' => now(),
            ]);
        }

        $written = $writer->write($agent, $siteText, $notes);
        $agent->fill([
            'first_message' => $written['first_message'],
            'script' => ['identity' => $written['identity'], 'never' => $written['never'], 'steps' => $written['steps'], 'handoff' => $written['handoff']],
            'knowledge' => trim($written['knowledge']) !== '' ? $written['knowledge'] : trim($notes),
            'choices' => array_map(fn ($q) => ['question' => $q['question'], 'options' => $q['options'], 'answer' => $q['options'][$q['recommended']] ?? $q['options'][0]], $written['questions']),
        ])->save();

        return [
            'ok' => true,
            'agent' => $this->payload($agent),
            'questions' => $written['questions'],
            'site_read' => $siteOk,
            'written_by' => $written['written_by'],
        ];
    }

    /** @return array<string, mixed> */
    private function payload(Agent $agent): array
    {
        return [
            'id' => $agent->public_id,
            'name' => $agent->name,
            'business_name' => $agent->business_name,
            'status' => $agent->status,
            'language' => $agent->language,
            'dialect' => $agent->dialect,
            'speaker_id' => $agent->speaker_id,
            'first_message' => $agent->first_message,
            'script' => $agent->script ?? [],
            'knowledge' => $agent->knowledge,
            'choices' => $agent->choices ?? [],
            'urls' => [
                'show' => route('voice-agents.show', $agent),
                'update' => route('voice-agents.update', $agent),
                'test' => route('voice-agents.test-call', $agent),
                'correct' => route('voice-agents.correct', $agent),
                'write' => route('voice-agents.write', $agent),
                'edit' => route('voice-agents.edit', $agent),
            ],
        ];
    }

    /** @return array<string, bool> */
    private function checklist(Agent $agent, $widget): array
    {
        return [
            'created' => true,
            'voice' => (bool) $agent->live_voice,
            'tested' => $agent->tested_at !== null,
            // Prices and hours: typed, or in a file the agent has read.
            'facts' => mb_strlen(trim((string) $agent->knowledge)) >= 80
                || $agent->sources()->whereIn('kind', [AgentSource::FILE, AgentSource::SHEET])->where('status', AgentSource::READY)->exists(),
            'live' => $agent->status === Agent::LIVE,
        ];
    }

    /** The one line for a website; $via says which guide it came from (shown back to the owner). */
    public static function embedCode($widget, ?string $via = null): string
    {
        return '<script src="'.asset('js/listen-agent.js').'" data-agent="'.$widget->public_key.'"'.($via ? ' data-via="'.$via.'"' : '').' async></script>';
    }

    /** "Check installation" in the install panel: has the call button been seen on a website yet? */
    public function installStatus(Request $request, Agent $agent): JsonResponse
    {
        $this->own($request, $agent);
        $widget = $agent->mainWidget();
        $site = $widget->last_seen_origin ? parse_url($widget->last_seen_origin, PHP_URL_HOST) : null;
        $when = $site ? $widget->seenAgo() : null;

        return response()->json([
            'seen' => (bool) $when,
            'site' => $when ? $site : null,
            'via' => $when ? $widget->installed_via : null,
            'message' => $when ? __('agents.install.seen', ['site' => $site, 'when' => $when]) : __('agents.install.not_seen'),
            'tile' => $when ? __('agents.live_seen', ['site' => $site, 'when' => $when]) : __('agents.live_not_seen'),
        ])->header('Cache-Control', 'no-store');
    }

    /** The WordPress plugin, with this agent already in it. */
    public function plugin(Request $request, Agent $agent, string $platform, WordPressPlugin $wordpress): BinaryFileResponse
    {
        $this->own($request, $agent);
        $path = $wordpress->build($agent->mainWidget());

        return response()->download($path, WordPressPlugin::SLUG.'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    private function own(Request $request, Agent $agent): void
    {
        abort_unless((int) $agent->user_id === (int) $request->user()->id, 404);
    }
}
