<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentWidget;
use App\Services\Agents\AgentCalls;
use App\Services\Agents\GeminiClient;
use App\Support\Agents\AgentVoices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Listen Agents, the callers' side: the agent's link page (/talk/{slug}), the
 * call button's settings for a website, and the call itself (start, ping,
 * tool, end) under /agent-api, which any website may reach (AgentCors): each
 * widget checks the website it is on against the owner's list.
 */
class AgentCallController extends Controller
{
    public function __construct(private AgentCalls $calls) {}

    /** The link page: it also works as a QR code. */
    public function talk(string $slug): View|Response
    {
        $widget = AgentWidget::where('slug', $slug)->first();
        $agent = $widget?->agent;
        if (! $widget || ! $agent || ! $widget->link_enabled) {
            abort(404);
        }
        app()->setLocale($agent->language === 'en' ? 'en' : 'ar');

        return response()->view('agents.talk', [
            'agent' => $agent,
            'widget' => $widget,
            'voice' => $agent->speaker_id ? AgentVoices::find($agent->speaker_id, $agent->language, $agent->language !== 'en') : null,
            'available' => $agent->isLive(),
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** The call button's settings: read on every page load, so a change never needs new code. */
    public function widget(Request $request, string $key): JsonResponse
    {
        $widget = AgentWidget::where('public_key', $key)->first();
        $agent = $widget?->agent;
        if (! $widget || ! $agent || ! $widget->enabled) {
            return response()->json(['error' => 'not_found'], 404);
        }
        if (! $widget->allowsOrigin($request->headers->get('Origin'))) {
            return response()->json(['error' => 'origin', 'message' => __('agents.error_origin')], 403);
        }
        $ar = $agent->language !== 'en';
        $voice = $agent->speaker_id ? AgentVoices::find($agent->speaker_id, $agent->language, $ar) : null;
        $this->seen($widget, $request);
        $locale = $ar ? 'ar' : 'en';
        $look = $widget->style();

        return response()->json([
            'available' => $agent->isLive(),
            // The button and the call, versioned: a new version reaches every website on its own.
            'assets' => [
                'widget' => asset('js/agent-widget.js').'?v='.@filemtime(public_path('js/agent-widget.js')),
                'call' => asset('js/agent-call.js').'?v='.@filemtime(public_path('js/agent-call.js')),
            ],
            'name' => $agent->name,
            'business' => $agent->business_name,
            'language' => $agent->language,
            'dir' => $ar ? 'rtl' : 'ltr',
            // The agent's photo, shown small next to its name (unless the owner turned it off).
            'avatar' => $look['photo'] ? ($voice['avatar'] ?? null) : null,
            'colors' => $look['colors'],
            'look' => $look,
            'text' => [
                'label' => $look['label'] ?: trans('agents.widget_label', [], $locale),
                'title' => $look['title'] ?: $agent->business_name,
                'subtitle' => $look['subtitle'] ?: trans(($voice['gender'] ?? null) === 'female' ? 'agents.widget_subtitle_female' : 'agents.widget_subtitle', [], $locale),
                'start' => trans('agents.talk_start', [], $locale),
                'end' => trans('agents.talk_end', [], $locale),
                'states' => $this->states($agent, $voice, $locale),
                'mic' => trans('agents.talk_mic_blocked', [], $ar ? 'ar' : 'en'),
                'paused' => trans('agents.error_paused', [], $ar ? 'ar' : 'en'),
                'powered' => trans('agents.powered', [], $ar ? 'ar' : 'en'),
                'mute' => trans('agents.talk_mute', [], $locale),
                'unmute' => trans('agents.talk_unmute', [], $locale),
                'close' => trans('agents.widget_close', [], $locale),
            ],
        ])->header('Cache-Control', 'public, max-age=60');
    }

    /** @return array<string, string> the call's states, with the speaking line in the voice's gender */
    private function states(Agent $agent, ?array $voice, string $locale): array
    {
        return \App\Support\Agents\Gendered::lines((array) trans('agents.talk_states', ['name' => $agent->name], $locale), ($voice['gender'] ?? null) === 'female');
    }

    /**
     * Where the call button was last seen (and how it was added), so the owner
     * sees it working on their website. Written at most every ten minutes.
     */
    private function seen(AgentWidget $widget, Request $request): void
    {
        $origin = AgentWidget::normalizeOrigin($request->headers->get('Origin'));
        if (! $origin || $origin === AgentWidget::normalizeOrigin(url('/'))) {
            return;
        }
        if ($widget->last_seen_at && $widget->last_seen_at->gt(now()->subMinutes(10)) && $widget->last_seen_origin === $origin) {
            return;
        }
        $via = (string) $request->query('via', '');
        $widget->forceFill([
            'last_seen_origin' => $origin,
            'last_seen_at' => now(),
            'installed_via' => in_array($via, ['wordpress', 'shopify', 'wix', 'laravel'], true) ? $via : ($widget->installed_via ?: 'embed'),
        ])->save();
    }

    /** A caller starts a call: from a website's call button (key) or the link page (slug). */
    public function start(Request $request): JsonResponse
    {
        $in = $this->body($request);
        $widget = ! empty($in['key'])
            ? AgentWidget::where('public_key', (string) $in['key'])->first()
            : AgentWidget::where('slug', (string) ($in['slug'] ?? ''))->first();
        $agent = $widget?->agent;
        if (! $widget || ! $agent || ! $widget->enabled) {
            return $this->error('not_found', 404);
        }
        $channel = ! empty($in['key']) ? 'widget' : 'link';
        $origin = $request->headers->get('Origin');
        if ($channel === 'widget' && ! $widget->allowsOrigin($origin)) {
            return $this->error('origin', 403, __('agents.error_origin'));
        }
        if (! $agent->isLive()) {
            return $this->error('paused', 409, __('agents.error_paused'));
        }
        if (! $agent->live_voice) {
            return $this->error('paused', 409, __('agents.error_paused'));
        }

        // A caller (browser) gets a few calls an hour on one agent, and one network a few more,
        // so a new browser id never buys more calls. Mobile networks share addresses: hence the room.
        $visitor = (string) $request->ip().'|'.substr((string) ($in['visitor'] ?? ''), 0, 64);
        $perVisitor = max(1, (int) config('listen.agents.calls_per_visitor_hour', 6));
        $limits = [
            'agent-call:'.$agent->id.':'.sha1($visitor) => $perVisitor,
            'agent-call-ip:'.$agent->id.':'.sha1((string) $request->ip()) => $perVisitor * 5,
        ];
        foreach ($limits as $limitKey => $max) {
            if (RateLimiter::tooManyAttempts($limitKey, $max)) {
                return $this->error('busy', 429, __('agents.error_busy'));
            }
        }
        foreach (array_keys($limits) as $limitKey) {
            RateLimiter::hit($limitKey, 3600);
        }
        // Never more than a handful of calls at once on one agent. A call that stopped reporting
        // still counts until it could have ended on its own.
        $longest = (int) config('listen.agents.max_call_seconds', 600) + 30;
        if ($agent->conversations()->where('status', 'live')->where('started_at', '>', now()->subSeconds($longest))->count() >= 8) {
            return $this->error('busy', 429, __('agents.error_busy'));
        }

        $result = $this->calls->start($agent, $channel, $widget, [
            'origin' => $origin,
            'page_url' => (string) ($in['page_url'] ?? ''),
            'visitor' => $visitor,
            'country' => strtoupper(substr((string) $request->headers->get('CF-IPCountry', ''), 0, 2)) ?: null,
        ]);
        if (! $result['ok']) {
            // The caller is never told about the owner's credits: the agent is just not available.
            return $this->error($result['error'] === 'credits' ? 'paused' : $result['error'], 409, __('agents.error_paused'));
        }

        return response()->json(self::startPayload($agent, $result));
    }

    public function ping(Request $request, string $conversation): JsonResponse
    {
        $in = $this->body($request);
        $c = $this->calls->findByKey($conversation, (string) ($in['call_key'] ?? ''));
        if (! $c) {
            return $this->error('not_found', 404);
        }

        return response()->json($this->calls->ping($c, (array) ($in['turns'] ?? [])));
    }

    public function tool(Request $request, string $conversation): JsonResponse
    {
        $in = $this->body($request);
        $c = $this->calls->findByKey($conversation, (string) ($in['call_key'] ?? ''));
        if (! $c || ! $c->isLive()) {
            return $this->error('not_found', 404);
        }

        return response()->json($this->calls->tool($c, (string) ($in['name'] ?? ''), (array) ($in['args'] ?? [])));
    }

    public function end(Request $request, string $conversation): JsonResponse
    {
        $in = $this->body($request);
        $c = $this->calls->findByKey($conversation, (string) ($in['call_key'] ?? ''));
        if (! $c) {
            return $this->error('not_found', 404);
        }
        $usage = $in['usage'] ?? null;
        $c = $this->calls->end($c, (array) ($in['turns'] ?? []), is_array($usage) ? $usage : null, (string) ($in['reason'] ?? 'done'));
        dispatch(new \App\Jobs\SummarizeAgentCall($c->id))->afterResponse();

        return response()->json(['ok' => true, 'seconds' => $c->billed_seconds]);
    }

    /** @param  array<string, mixed>  $result */
    public static function startPayload(Agent $agent, array $result): array
    {
        $c = $result['conversation'];

        return [
            'conversation' => $c->public_id,
            'call_key' => $result['call_key'],
            'token' => $result['token'],
            'ws' => 'wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1beta.GenerativeService.BidiGenerateContentConstrained',
            'model' => 'models/'.GeminiClient::LIVE_MODEL,
            'max_seconds' => $result['max_seconds'],
            'free' => $result['free'],
            'speaks_first' => true,
            'api' => url('/agent-api/calls/'.$c->public_id),
            'agent' => ['name' => $agent->name, 'language' => $agent->language],
        ];
    }

    /**
     * The request's fields: the call client sends JSON as text/plain, so other
     * websites need no preflight; a form or a JSON request works too.
     *
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $json = json_decode((string) $request->getContent(), true);

        return is_array($json) ? $json : $request->all();
    }

    private function error(string $code, int $status, ?string $message = null): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $status);
    }
}
