<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentConversation;
use App\Models\AgentSource;
use App\Services\Agents\AgentCalls;
use App\Services\Agents\AgentWriter;
use App\Services\Agents\SiteReader;
use App\Services\Agents\WordPressPlugin;
use App\Support\Agents\AgentVoices;
use App\Support\ArabicDialects;
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
        $agents = Agent::ownedBy($request->user())->latest()->get();
        $monthStart = now()->startOfMonth();
        $calls = AgentConversation::query()->where('user_id', $request->user()->id)->where('started_at', '>=', $monthStart)
            ->where('channel', '!=', 'test')->selectRaw('agent_id, count(*) as n, max(started_at) as last')->groupBy('agent_id')->get()->keyBy('agent_id');

        return view('agents.index', [
            'agents' => $agents,
            'calls' => $calls,
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        if (Agent::ownedBy($request->user())->count() >= (int) config('listen.agents.max_agents', 10)) {
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
        if (Agent::ownedBy($request->user())->count() >= (int) config('listen.agents.max_agents', 10)) {
            return response()->json(['message' => __('agents.error_name')], 422);
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
        $monthStart = now()->startOfMonth();
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
            'max_call_seconds' => ['sometimes', 'integer', 'min:60', 'max:'.(int) config('listen.agents.max_call_seconds', 600)],
            'status' => ['sometimes', 'in:ready,live,paused'],
            'allowed_origins' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'appearance' => ['sometimes', 'array'],
            'appearance.position' => ['nullable', 'in:start,end'],
            'appearance.theme' => ['nullable', 'in:auto,light,dark'],
            'appearance.label' => ['nullable', 'string', 'max:40'],
        ]);

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
        foreach (['name', 'business_name', 'first_message', 'knowledge', 'choices', 'max_call_seconds'] as $k) {
            if (array_key_exists($k, $data)) {
                $agent->{$k} = $data[$k];
            }
        }
        if (isset($data['status'])) {
            if ($data['status'] === Agent::LIVE && ! $agent->live_voice) {
                return response()->json(['message' => __('agents.voice_heading')], 422);
            }
            $agent->status = $data['status'];
            if ($data['status'] === Agent::LIVE && ! $agent->went_live_at) {
                $agent->went_live_at = now();
            }
        }
        $agent->save();

        $widget = $agent->mainWidget();
        if (array_key_exists('allowed_origins', $data)) {
            $origins = array_values(array_unique(array_filter(array_map(
                fn ($o) => \App\Models\AgentWidget::normalizeOrigin($o),
                preg_split('/[\s,]+/', (string) $data['allowed_origins']) ?: [],
            ))));
            $widget->allowed_origins = array_slice($origins, 0, 10);
        }
        if (isset($data['appearance'])) {
            $widget->appearance = array_merge($widget->appearance ?? [], array_filter($data['appearance'], fn ($v) => $v !== null));
        }
        $widget->save();

        return response()->json(['ok' => true, 'agent' => $this->payload($agent)]);
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
            return response()->json(['message' => __('agents.error_'.$result['error'])], 422);
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

        $dialect = $data['language'] === 'ar' && isset(ArabicDialects::ALL[$data['dialect'] ?? '']) ? $data['dialect'] : ($data['language'] === 'ar' ? 'ar-XA-WH' : null);

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
            'facts' => mb_strlen(trim((string) $agent->knowledge)) >= 80,
            'live' => $agent->status === Agent::LIVE,
        ];
    }

    /** The one line for a website; $via says which guide it came from (shown back to the owner). */
    public static function embedCode($widget, ?string $via = null): string
    {
        return '<script src="'.asset('js/listen-agent.js').'" data-agent="'.$widget->public_key.'"'.($via ? ' data-via="'.$via.'"' : '').' async></script>';
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
