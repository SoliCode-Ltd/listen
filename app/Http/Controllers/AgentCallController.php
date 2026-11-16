<?php

namespace App\Http\Controllers;

use App\Jobs\SummarizeAgentCall;
use App\Models\Agent;
use App\Models\AgentConversation;
use App\Models\AgentWidget;
use App\Models\AgentWidgetSite;
use App\Services\Agents\AgentCalls;
use App\Services\Agents\AgentChats;
use App\Services\Agents\GeminiClient;
use App\Support\Agents\AgentAccess;
use App\Support\Agents\AgentChannels;
use App\Support\Agents\AgentInstructions;
use App\Support\Agents\AgentVisits;
use App\Support\Agents\AgentVoices;
use App\Support\Agents\CallBackground;
use App\Support\Agents\CallerIdentity;
use App\Support\Agents\CallRecordings;
use App\Support\Agents\Gendered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Listen Agents, the callers' side: the agent's link page (/talk/{slug}), the
 * call button's settings for a website, and the call itself (start, ping,
 * tool, end) under /agent-api, which any website may reach (AgentCors): each
 * widget checks the website it is on against the owner's list.
 */
class AgentCallController extends Controller
{
    public function __construct(private AgentCalls $calls) {}

    /**
     * The link page (it also works as a QR code): the very call card the website button opens, with
     * the agent's own look, shown open in the middle of the page.
     */
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
            'card' => $this->card($agent, $widget),
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
        $origin = self::askingOrigin($request);
        $via = (string) $request->query('via', '');
        $via = in_array($via, ['wordpress', 'shopify', 'wix', 'laravel'], true) ? $via : null;
        if (! $widget->allowsOrigin($origin)) {
            $widget->noteRefused($origin);
            AgentWidgetSite::note($widget, $origin, false, $via);

            return response()->json(['error' => 'origin', 'message' => __('agents.error_origin')], 403);
        }
        // A card already on a page reads its settings again now and then (so a change its owner
        // makes shows without a reload): that is not a page load, and notes nothing.
        if (! $request->boolean('again')) {
            $this->seen($widget, $request);
            AgentWidgetSite::note($widget, $origin, true, $via);
        }

        // Kept by the visitor's own browser for a moment only, never by anything in between: a
        // change the owner makes is on their website on its next page load.
        return response()->json($this->card($agent, $widget))->header('Cache-Control', 'private, max-age=10');
    }

    /** The link page's card, read again by a page already open (agent-widget.js refreshCard). */
    public function linkCard(string $slug): JsonResponse
    {
        $widget = AgentWidget::where('slug', $slug)->first();
        $agent = $widget?->agent;
        if (! $widget || ! $agent || ! $widget->link_enabled) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json($this->card($agent, $widget))->header('Cache-Control', 'no-store');
    }

    /**
     * The call card's settings: its words, look, voice and photo, the same for the website button
     * and the link page.
     *
     * @return array<string, mixed>
     */
    private function card(Agent $agent, AgentWidget $widget): array
    {
        $ar = $agent->language !== 'en';
        $voice = $agent->speaker_id ? AgentVoices::find($agent->speaker_id, $agent->language, $ar) : null;
        $locale = $ar ? 'ar' : 'en';
        $look = $widget->style();

        return [
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
            // How customers reach it (Setup → Chat and calls): a written chat, voice calls, or both.
            // The chat opens first; an agent with no voice yet, or whose chat cannot be paid for now,
            // shows only what works.
            'channels' => [
                'chat' => AgentChannels::chat($agent) && app(AgentChats::class)->unavailable($agent) === null,
                'call' => AgentChannels::calls($agent) && filled($agent->live_voice),
            ],
            'chat' => [
                'greeting' => trim((string) $agent->first_message) ?: trans('agents.chat.greeting', ['name' => $agent->name], $locale),
                'max_chars' => AgentChats::maxChars(),
            ] + (array) trans('agents.chat.card', [], $locale),
            'text' => [
                'label' => $look['label'] ?: trans('agents.widget_label', [], $locale),
                'title' => $look['title'] ?: $agent->business_name,
                'subtitle' => $look['subtitle'] ?: trans(($voice['gender'] ?? null) === 'female' ? 'agents.widget_subtitle_female' : 'agents.widget_subtitle', [], $locale),
                'start' => trans('agents.talk_start', [], $locale),
                'end' => trans('agents.talk_end', [], $locale),
                // The line under the orb before a call: the owner's own words when set.
                'states' => array_merge($this->states($agent, $voice, $locale), $look['ready_text'] ? ['idle' => $look['ready_text']] : []),
                'mic' => trans('agents.talk_mic_blocked', [], $ar ? 'ar' : 'en'),
                // No internet: one sentence in the card's language, never the browser's "Failed to fetch".
                'offline' => trans('agents.network_error', [], $ar ? 'ar' : 'en'),
                'paused' => trans('agents.error_paused', [], $ar ? 'ar' : 'en'),
                'powered' => trans('agents.powered', [], $ar ? 'ar' : 'en'),
                'close' => trans('agents.widget_close', [], $locale),
                // After a call, one line: "Call ended after 01:24".
                'ended_after' => trans('agents.talk_ended_after', [], $locale),
                // The call's time limit: "Ends in 00:29" through its last half minute, then
                // "Call time is up", its length, and "Call again".
                'ends_in' => trans('agents.talk_ends_in', [], $locale),
                'ended_limit' => trans('agents.talk_ended_limit', [], $locale),
                'lasted' => trans('agents.talk_lasted', [], $locale),
                'again' => trans('agents.talk_again', [], $locale),
            ] + self::lineText($agent, $locale),
        ];
    }

    /**
     * The words for a caller who finds every line busy (waiting, then leaving a number), in the
     * agent's language, for the call button and the link page. "N callers ahead of you" comes ready
     * for 1 to 10 (Arabic has its own forms for each) and as a pattern beyond.
     *
     * @return array{lines: array<string, mixed>, callback: array<string, string>}
     */
    public static function lineText(Agent $agent, string $locale): array
    {
        $lines = (array) trans('agents.lines', [], $locale);
        $lines['ahead'] = collect(range(1, 10))->mapWithKeys(fn (int $n): array => [$n => trans_choice('agents.lines.ahead', $n, ['count' => $n], $locale)])
            ->put('many', trans_choice('agents.lines.ahead', 11, ['count' => ':count'], $locale))->all();
        $callback = (array) trans('agents.callback', ['business' => $agent->business_name], $locale);
        unset($callback['note']);

        return ['lines' => $lines, 'callback' => $callback];
    }

    /** @return array<string, string> the call's states, with the speaking line in the voice's gender */
    private function states(Agent $agent, ?array $voice, string $locale): array
    {
        return Gendered::lines((array) trans('agents.talk_states', ['name' => $agent->name], $locale), ($voice['gender'] ?? null) === 'female');
    }

    /**
     * The website asking for the call button or a call: the request's Origin. A browser sends no
     * Origin on a GET from the same website, so Listen's own pages (where Listen's own agent
     * answers) were refused as "no website": there the page's address stands in. A request from
     * another website always carries its real Origin (page scripts can neither drop it nor fake
     * Sec-Fetch-Site), so a copy of the button elsewhere is still refused.
     */
    public static function askingOrigin(Request $request): ?string
    {
        $origin = trim((string) $request->headers->get('Origin', ''));
        if ($origin !== '' && $origin !== 'null') {
            return $origin;
        }
        // Without an Origin, only the same website (or a browser too old to say) is taken at its word.
        $site = strtolower(trim((string) $request->headers->get('Sec-Fetch-Site', '')));
        if ($site !== '' && $site !== 'same-origin') {
            return null;
        }
        $referer = AgentWidget::normalizeOrigin($request->headers->get('Referer'));
        if ($referer !== null) {
            return $referer;
        }

        return $site === 'same-origin' ? AgentWidget::normalizeOrigin(url('/')) : null;
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
        $fill = [
            'last_seen_origin' => $origin,
            'last_seen_at' => now(),
            'installed_via' => in_array($via, ['wordpress', 'shopify', 'wix', 'laravel'], true) ? $via : ($widget->installed_via ?: 'embed'),
        ];
        // The first website that loads the button becomes its website: copied onto another site,
        // it does not work there, so nobody else's visitors spend the owner's credits. The owner
        // adds more websites, or allows a refused one in one tap, in Go live → Security.
        if ($widget->allowedOrigins() === [] && ! AgentWidget::isLocal($origin)) {
            $fill['allowed_origins'] = [$origin];
        }
        $widget->forceFill($fill)->save();
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
        $origin = self::askingOrigin($request);
        if ($channel === 'widget' && ! $widget->allowsOrigin($origin)) {
            $widget->noteRefused($origin);

            return $this->error('origin', 403, __('agents.error_origin'));
        }
        if (! $agent->isLive()) {
            return $this->error('paused', 409, __('agents.error_paused'));
        }
        if (! $agent->live_voice) {
            return $this->error('paused', 409, __('agents.error_paused'));
        }
        // The owner chose written chat only for this agent (Setup → Chat and calls).
        if (! AgentChannels::calls($agent)) {
            return $this->error('paused', 409, __('agents.error_paused'));
        }

        // A caller (browser) gets a few calls an hour and a day on one agent, and one network a few
        // more, so a new browser id never buys more calls. Mobile networks share addresses: hence the room.
        $network = (string) $request->ip();
        $visitor = $network.'|'.substr((string) ($in['visitor'] ?? ''), 0, 64);
        // Kept off this agent for a while: after abusing it, or leaving calls hanging (AgentCalls::blockCaller).
        if (AgentCalls::callerBlocked($agent->id, $visitor, $network)) {
            return $this->error('busy', 429, __('agents.error_busy'));
        }
        $perVisitor = max(1, (int) config('listen.agents.calls_per_visitor_hour', 6));
        $perDay = max($perVisitor, (int) config('listen.agents.calls_per_visitor_day', 15));
        $limits = [
            'agent-call:'.$agent->id.':'.sha1($visitor) => [$perVisitor, 3600],
            'agent-call-ip:'.$agent->id.':'.sha1($network) => [$perVisitor * 5, 3600],
            'agent-call-day:'.$agent->id.':'.sha1($visitor) => [$perDay, 86400],
            'agent-call-ip-day:'.$agent->id.':'.sha1($network) => [$perDay * 4, 86400],
        ];
        foreach ($limits as $limitKey => [$max]) {
            if (RateLimiter::tooManyAttempts($limitKey, $max)) {
                return $this->error('busy', 429, __('agents.error_busy'));
            }
        }
        foreach ($limits as $limitKey => [, $decay]) {
            RateLimiter::hit($limitKey, $decay);
        }
        // Listen's own agent: never more than a handful of calls at once. A member's agents share
        // their plan's calls at once (AgentCalls::start), so one busy agent can use them all. A call
        // that stopped reporting still counts until it could have ended on its own.
        $longest = AgentAccess::MAX_CALL_MINUTES * 60 + 30;
        if ($agent->setting('house') && $agent->conversations()->where('status', 'live')->where('started_at', '>', now()->subSeconds($longest))->count() >= 8) {
            return $this->error('busy', 429, __('agents.error_busy'));
        }

        $result = $this->calls->start($agent, $channel, $widget, [
            'origin' => $origin,
            'page_url' => (string) ($in['page_url'] ?? ''),
            'visitor' => $visitor,
            'network' => $network,
            'country' => strtoupper(substr((string) $request->headers->get('CF-IPCountry', ''), 0, 2)) ?: null,
            // Who is calling, when the website the button is on signed its signed-in visitor (a
            // call button only: a link page is on Listen, which never tells a business who you are).
            'caller' => $channel === 'widget' ? CallerIdentity::verify($widget, $in['caller'] ?? null) : null,
            // What the caller wrote in the chat just before pressing call: the call goes on from it.
            'before' => ($chat = $this->chatOf($agent, $in['chat'] ?? null)) ? (AgentChats::spokenBefore($chat) ?: null) : null,
        ]);
        if (! $result['ok'] && $result['error'] === 'lines') {
            // Every line is taken: the call button waits and tries again. Waiting is not a call, so
            // the caller's calls an hour stay as they were.
            foreach ($limits as $limitKey => [, $decay]) {
                RateLimiter::decrement($limitKey, $decay);
            }

            return response()->json([
                'error' => 'lines',
                'message' => trans('agents.lines.waiting', [], $agent->language === 'en' ? 'en' : 'ar'),
                'position' => $result['position'],
                'retry_after' => $result['retry_after'],
                // 202: the caller is in line (not an error the business's website should log).
            ], 202);
        }
        if (! $result['ok']) {
            // The caller is never told why on the owner's side (credits, plan, limits): the agent
            // is just not available. Busy means try again in a moment.
            return $result['error'] === 'busy'
                ? $this->error('busy', 429, __('agents.error_busy'))
                : $this->error('paused', 409, __('agents.error_paused'));
        }

        // One visit: the chat this call was made from, or this visitor's last conversation a moment ago.
        rescue(fn () => AgentVisits::join($result['conversation'], $chat), null, true);

        return response()->json(self::startPayload($agent, $result));
    }

    /**
     * A caller who found every line busy leaves their name and number, so the business calls them
     * back (AgentCalls::callBack): from the call button (key) or the link page (slug), like a call.
     */
    public function callback(Request $request): JsonResponse
    {
        $in = $this->body($request);
        $widget = ! empty($in['key'])
            ? AgentWidget::where('public_key', (string) $in['key'])->first()
            : AgentWidget::where('slug', (string) ($in['slug'] ?? ''))->first();
        $agent = $widget?->agent;
        if (! $widget || ! $agent || ! $widget->enabled) {
            return $this->error('not_found', 404);
        }
        $locale = $agent->language === 'en' ? 'en' : 'ar';
        if (! empty($in['key']) && ! $widget->allowsOrigin($request->headers->get('Origin'))) {
            return $this->error('origin', 403, trans('agents.error_origin', [], $locale));
        }
        $network = (string) $request->ip();
        $visitor = $network.'|'.substr((string) ($in['visitor'] ?? ''), 0, 64);
        if (AgentCalls::callerBlocked($agent->id, $visitor, $network)) {
            return $this->error('busy', 429, trans('agents.callback.not_now', [], $locale));
        }
        $phone = AgentCalls::phoneFrom($in['phone'] ?? '');
        if ($phone === null) {
            return $this->error('phone', 422, trans('agents.callback.invalid_phone', [], $locale));
        }
        $result = $this->calls->callBack($agent, ['visitor' => $visitor, 'name' => (string) ($in['name'] ?? ''), 'phone' => $phone]);
        if (! $result['ok']) {
            return $this->error($result['error'], 429, trans('agents.callback.not_now', [], $locale));
        }

        return response()->json(['ok' => true, 'phone' => $phone]);
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

    /**
     * A caller's line written again from their own voice (16-bit mono PCM in base64, at 8 or 16 kHz),
     * in the agent's dialect. Answers { text } (null when it could not be written).
     */
    public function transcribe(Request $request, string $conversation): JsonResponse
    {
        $in = $this->body($request);
        $c = $this->calls->findByKey($conversation, (string) ($in['call_key'] ?? ''));
        if (! $c) {
            return $this->error('not_found', 404);
        }
        $audio = (string) ($in['audio'] ?? '');
        // 30 s at 8 kHz or 15 s at 16 kHz is about 640 KB in base64; more is not from the call client.
        $pcm = $audio !== '' && strlen($audio) <= 1_100_000 ? base64_decode($audio, true) : false;
        if ($pcm === false || $pcm === '') {
            return response()->json(['text' => null]);
        }

        return response()->json(['text' => $this->calls->hearCaller($c, (int) ($in['n'] ?? 0), $pcm, (int) ($in['rate'] ?? 16000),
            Str::limit((string) ($in['heard'] ?? ''), 600, ''), Str::limit((string) ($in['said'] ?? ''), 600, ''))]);
    }

    /** An agent line of an Arabic call that came out in Latin letters, written in Arabic ones. Answers { text }. */
    public function agentLine(Request $request, string $conversation): JsonResponse
    {
        $in = $this->body($request);
        $c = $this->calls->findByKey($conversation, (string) ($in['call_key'] ?? ''));
        if (! $c) {
            return $this->error('not_found', 404);
        }

        return response()->json(['text' => $this->calls->writeAgentLine($c, (int) ($in['n'] ?? 0), Str::limit((string) ($in['text'] ?? ''), 600, ''), ! empty($in['wrong']))]);
    }

    /**
     * One part of the call's recording (base64, in order: part 0, 1, 2 ...), sent every 10 s and
     * as the call ends. Answers { result }: kept, again (had it), or why it was not kept.
     */
    public function recording(Request $request, string $conversation): JsonResponse
    {
        $in = $this->body($request);
        $c = $this->calls->findByKey($conversation, (string) ($in['call_key'] ?? ''));
        if (! $c) {
            return $this->error('not_found', 404);
        }
        $audio = (string) ($in['audio'] ?? '');
        $bytes = $audio !== '' && strlen($audio) <= (int) ceil(CallRecordings::MAX_PART_BYTES * 4 / 3) + 8 ? base64_decode($audio, true) : false;
        if ($bytes === false) {
            return response()->json(['result' => 'bad'], 422);
        }
        $result = CallRecordings::add($c, max(0, (int) ($in['part'] ?? 0)), $bytes, (string) ($in['type'] ?? ''));
        // The last part: the file is packed whole, after the answer (the caller's page may be closing).
        if ($result === 'kept' && ! empty($in['last'])) {
            dispatch(fn () => CallRecordings::finish($c->fresh()))->afterResponse();
        }

        return response()->json(['result' => $result], in_array($result, ['kept', 'again', 'off'], true) ? 200 : 422);
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
        dispatch(new SummarizeAgentCall($c->id))->afterResponse();

        return response()->json(['ok' => true, 'seconds' => $c->billed_seconds]);
    }

    /** The chat a call follows (its id and key, from the same browser), as the call is told it. */
    /** The written chat a call was made from, when the browser named it with its key (this agent's only). */
    private function chatOf(Agent $agent, mixed $chat): ?AgentConversation
    {
        if (! is_array($chat) || self::word($chat, 'conversation') === '' || self::word($chat, 'chat_key') === '') {
            return null;
        }
        $c = app(AgentChats::class)->findByKey(self::word($chat, 'conversation'), self::word($chat, 'chat_key'));

        return $c && (int) $c->agent_id === (int) $agent->id ? $c : null;
    }

    /** A value a browser sent, as text: anything that is not plain text or a number is nothing. */
    private static function word(mixed $in, string $key): string
    {
        $value = is_array($in) ? ($in[$key] ?? '') : '';

        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }

    /**
     * A visitor opens a written chat: from a website's button (key) or the link page (slug).
     * The browser gets a chat key; nothing is taken from the owner's plan until a message is
     * answered.
     */
    public function chatStart(Request $request, AgentChats $chats): JsonResponse
    {
        $in = $this->body($request);
        $key = self::word($in, 'key');
        $widget = $key !== ''
            ? AgentWidget::where('public_key', $key)->first()
            : AgentWidget::where('slug', self::word($in, 'slug'))->first();
        $agent = $widget?->agent;
        if (! $widget || ! $agent || ! $widget->enabled) {
            return $this->error('not_found', 404);
        }
        $channel = $key !== '' ? 'chat' : 'chat_link';
        $origin = self::askingOrigin($request);
        if ($channel === 'chat' && ! $widget->allowsOrigin($origin)) {
            $widget->noteRefused($origin);

            return $this->error('origin', 403, __('agents.error_origin'));
        }
        $language = $agent->language === 'en' ? 'en' : 'ar';
        $away = fn (): JsonResponse => $this->error('paused', 409, trans('agents.chat.away', [], $language));
        if (! $agent->isLive() || ! AgentChannels::chat($agent)) {
            return $away();
        }

        // A visitor (browser) gets a few chats an hour and a day on one agent, and one network a
        // few more, as with calls; someone kept off the agent for abuse is kept off its chat too.
        $network = (string) $request->ip();
        $visitor = $network.'|'.substr(self::word($in, 'visitor'), 0, 64);
        if (AgentCalls::callerBlocked($agent->id, $visitor, $network)) {
            return $this->error('busy', 429, trans('agents.chat.busy', [], $language));
        }
        $perHour = max(1, (int) config('listen.agents.chats_per_visitor_hour', 6));
        $perDay = max($perHour, (int) config('listen.agents.chats_per_visitor_day', 15));
        $limits = [
            'agent-chat:'.$agent->id.':'.sha1($visitor) => [$perHour, 3600],
            'agent-chat-ip:'.$agent->id.':'.sha1($network) => [$perHour * 5, 3600],
            'agent-chat-day:'.$agent->id.':'.sha1($visitor) => [$perDay, 86400],
            'agent-chat-ip-day:'.$agent->id.':'.sha1($network) => [$perDay * 4, 86400],
        ];
        foreach ($limits as $limitKey => [$max]) {
            if (RateLimiter::tooManyAttempts($limitKey, $max)) {
                return $this->error('busy', 429, trans('agents.chat.busy', [], $language));
            }
        }
        foreach ($limits as $limitKey => [, $decay]) {
            RateLimiter::hit($limitKey, $decay);
        }

        $result = $chats->start($agent, $widget, [
            'origin' => $origin,
            'page_url' => self::word($in, 'page_url'),
            'visitor' => $visitor,
            'country' => strtoupper(substr((string) $request->headers->get('CF-IPCountry', ''), 0, 2)) ?: null,
            'caller' => $channel === 'chat' ? CallerIdentity::verify($widget, $in['caller'] ?? null) : null,
        ], $channel);
        if (! $result['ok']) {
            // The visitor is never told why on the owner's side (credits, plan): the chat is just
            // not available now.
            return $result['error'] === 'busy' ? $this->error('busy', 429, trans('agents.chat.busy', [], $language)) : $away();
        }
        $c = $result['conversation'];
        // A voice call the visitor had just before, in the same card: its words are context.
        $call = is_array($in['call'] ?? null) ? $this->calls->findByKey(self::word($in['call'], 'conversation'), self::word($in['call'], 'call_key')) : null;
        if ($call) {
            $chats->comesAfter($c, $call);
        }
        // One visit: the call this chat follows, or this visitor's last conversation a moment ago.
        rescue(fn () => AgentVisits::join($c->refresh(), $call), null, true);

        return response()->json([
            'conversation' => $c->public_id,
            'chat_key' => $result['chat_key'],
            'api' => url('/agent-api/chats/'.$c->public_id),
            'replies' => $result['replies'],
            'max_chars' => AgentChats::maxChars(),
        ]);
    }

    /** The visitor's message in, the agent's reply out (AgentChats::reply). */
    public function chatMessage(Request $request, AgentChats $chats, string $conversation): JsonResponse
    {
        $in = $this->body($request);
        $c = $chats->findByKey($conversation, self::word($in, 'chat_key'));
        if (! $c) {
            return $this->error('not_found', 404);
        }
        $language = $c->agent?->language === 'en' ? 'en' : 'ar';
        // Someone kept off the agent for abuse is kept off a chat they already opened too, and
        // one visitor (and one network) gets so many answers an hour and a day on one agent:
        // far more than a person writes, far fewer than a script would take. Listen's own
        // agents, which answer free, allow a third of it. The visitor is the one the chat was
        // opened by (kept on it), whatever this request says.
        $network = hash('sha256', (string) $request->ip());
        $visitor = (string) ($c->visitor_hash ?: $network);
        $share = $c->agent?->setting('house') ? 3 : 1;
        $perHour = max(5, intdiv(max(1, (int) config('listen.agents.chat_replies_per_visitor_hour', 80)), $share));
        $perDay = max($perHour, intdiv(max(1, (int) config('listen.agents.chat_replies_per_visitor_day', 250)), $share));
        $limits = [
            'agent-chat-replies:'.$c->agent_id.':'.$visitor => [$perHour, 3600],
            'agent-chat-replies-ip:'.$c->agent_id.':'.$network => [$perHour * 3, 3600],
            'agent-chat-replies-day:'.$c->agent_id.':'.$visitor => [$perDay, 86400],
            'agent-chat-replies-ip-day:'.$c->agent_id.':'.$network => [$perDay * 3, 86400],
        ];
        $tooMany = Cache::has('agent-block:'.$c->agent_id.':'.$visitor) || Cache::has('agent-block:'.$c->agent_id.':'.$network);
        foreach ($limits as $limitKey => [$max]) {
            $tooMany = $tooMany || RateLimiter::tooManyAttempts($limitKey, $max);
        }
        if ($tooMany) {
            return $this->error('busy', 429, trans('agents.chat.busy', [], $language));
        }
        // A voice call made from this chat's card since it opened: what was said is context too.
        if (is_array($in['call'] ?? null) && ! isset($c->reservation['after_call'])
            && ($call = $this->calls->findByKey(self::word($in['call'], 'conversation'), self::word($in['call'], 'call_key')))) {
            $chats->comesAfter($c, $call);
        }
        $result = $chats->reply($c, self::word($in, 'id'), self::word($in, 'text'));
        // Every time the model was asked counts, answered or not; a message sent again that
        // already had its answer does not.
        if (! empty($result['asked'])) {
            foreach ($limits as $limitKey => [, $decay]) {
                RateLimiter::hit($limitKey, $decay);
            }
        }
        if (! $result['ok']) {
            // closed / limit: this conversation is over, a new one can start. failed / busy: the
            // same message can be sent again. Nothing was charged in either case.
            [$status, $words] = match ($result['error']) {
                'empty' => [422, 'agents.chat.empty'],
                'closed', 'limit' => [410, 'agents.chat.over'],
                'off' => [409, 'agents.chat.away'],
                default => [503, 'agents.chat.failed'],
            };

            return $this->error($result['error'], $status, trans($words, [], $language));
        }

        return response()->json([
            'reply' => $result['reply'],
            'links' => $result['links'] ?? [],
            'left' => $result['left'] ?? 0,
        ]);
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
            // A caller this quiet is asked if they are still there; as long again, the agent says goodbye.
            'silence_seconds' => max(8, (int) config('listen.agents.silence_seconds', 20)),
            'speaks_first' => true,
            // Each caller line written again from their voice, in the agent's dialect (transcribe).
            'transcribe' => AgentAccess::callerTranscribe(),
            // An Arabic agent's line that comes out in Latin letters is written in Arabic ones (agent-line).
            'arabic_lines' => $agent->language !== 'en',
            // The call's sound is recorded in the browser and sent in parts (CallRecordings, recording).
            'record' => CallRecordings::enabled(),
            // The language it speaks, as the model is told it: a line in another language is stopped
            // as it starts and the agent is asked to say it again in this one (agent-call.js).
            'speaks' => AgentInstructions::speaks($agent),
            'api' => url('/agent-api/calls/'.$c->public_id),
            'agent' => ['name' => $agent->name, 'language' => $agent->language],
            // What the caller hears behind the agent's voice (Design → Call sound): a quiet office
            // by default, or nothing. A test call or a demo follows the agent's first call button.
            'background' => CallBackground::for($c->agent_widget_id ? $c->widget : $agent->widgets()->oldest('id')->first()),
        ];
    }

    /**
     * A call's background sound (CallBackground), for the websites a call button is on: open to any
     * website and the same for all of them, so browsers and caches keep it (the address carries
     * the file's version).
     */
    public function sound(string $name): BinaryFileResponse
    {
        $path = CallBackground::path($name);
        abort_unless($path !== null && is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Access-Control-Allow-Origin' => '*',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
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
