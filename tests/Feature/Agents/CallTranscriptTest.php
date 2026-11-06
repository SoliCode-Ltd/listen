<?php

namespace Tests\Feature\Agents;

use Tests\TestCase;

/**
 * The call's words on the page as they are said, in every call card (the website button, the link
 * page, the agent page's test call, the demo page and the Design tab's pretend call): the agent's
 * line grows a word at a time in step with its voice, the caller's line keeps its place from their
 * first word, and nothing shows that must not (a line in the wrong language, Arabic in Latin
 * letters, half a number). A real call's answers used to show three dots for up to 13.7 s, then
 * the whole line at once.
 */
class CallTranscriptTest extends TestCase
{
    private function js(string $file): string
    {
        return (string) file_get_contents(public_path('js/'.$file));
    }

    public function test_the_call_client_gives_the_agents_words_as_it_says_them(): void
    {
        $call = $this->js('agent-call.js');

        // The line so far while it speaks, one word at a time, about as fast as people speak and
        // faster when more words are waiting.
        $this->assertStringContainsString("opts.onWords({ n: l.n, text: l.units.slice(0, l.shown).join(' ') });", $call);
        $this->assertStringContainsString('Math.max(60, Math.min(380, 380 / left))', $call);
        $this->assertStringContainsString("else showLive();", $call);
        // Only whole words: the transcription's last piece may be the start of a word.
        $this->assertStringContainsString("if (!whole && !/[\\s.,!?؟،؛…]$/.test(text)) text = text.replace(/\\S+$/, '');", $call);
        // Nothing before the line is plainly in the call's language; never a wrong-language line, and
        // never an Arabic agent's line in Latin letters (it is written in Arabic ones first).
        $this->assertStringContainsString("if ((k !== 'ar' && k !== 'en') || wrongTongue(text)) return null;", $call);
        $this->assertStringContainsString("if (info && info.arabic_lines && latinArabic(text) && k !== 'en') return null;", $call);
        // A number being read out digit by digit waits until it is whole.
        $this->assertStringContainsString("if (!whole) text = text.replace(DIGIT_TAIL, '');", $call);
        // Its end goes with its turn, in the same bubble; a dropped line takes its words away.
        $this->assertStringContainsString('if (live.shown && t) t.live = live.n;', $call);
        $this->assertStringContainsString("opts.onWords({ n: live.n, text: '', gone: true });", $call);
        $this->assertMatchesRegularExpression("/function stopWrong\\(\\) \\{\\s*dropTurn = true;\\s*redone\\+\\+;\\s*agentText = '';\\s*endLive\\(null\\);/", $call);
        // A plainly English answer to a caller who speaks English is not written in Arabic letters.
        $this->assertStringContainsString('latinArabic(text) && !english(text)) writeAgain(t, text);', $call);
        // The caller's line keeps its place from their first word, and while it is written again.
        $this->assertStringContainsString('if (!saidBefore && spoken(callerText).trim() && opts.onHear) opts.onHear(true, late);', $call);
        $this->assertStringContainsString('if (t.wait && opts.onHold) opts.onHold(t);', $call);
        $this->assertStringContainsString("else if (role === 'caller' && opts.onHear) opts.onHear(false);", $call);
    }

    public function test_every_call_card_shows_the_words_as_they_come(): void
    {
        $widget = $this->js('agent-widget.js');
        $page = $this->js('agents.js');
        $css = (string) file_get_contents(public_path('css/agents.css'));

        // The website button and the link page (agent-widget.js), the agent page and the demo page (agents.js).
        foreach (['onWords: words,', 'onHold: hold,', 'onHear: hear,'] as $hook) {
            $this->assertStringContainsString($hook, $widget);
            $this->assertStringContainsString($hook, $page);
        }
        // Each new word fades in, in the same bubble the dots were in; no dots under words already coming.
        $this->assertStringContainsString("node.appendChild(el('span', 'lva-w', to.slice(from.length)));", $widget);
        $this->assertStringContainsString("'.lva-w{animation:lva-word .34s ease both}'", $widget);
        $this->assertStringContainsString('if (typing || liveView || !parts.lines', $widget);
        $this->assertStringContainsString(".ag-w { animation: ag-word .34s ease both; }", $css);
        // A line's end comes in place, with no flash.
        $this->assertStringNotContainsString("n.className = 'lva-line is-agent is-fixed';", $widget);
        // The Design tab's pretend call shows its words the same way.
        $this->assertStringContainsString("line({ role: 'agent', text: text, live: n });", $widget);
    }
    public function test_the_callers_late_words_go_above_the_answer_and_its_echo_never_cuts_the_agent(): void
    {
        $call = (string) file_get_contents(public_path('js/agent-call.js'));
        $widget = (string) file_get_contents(public_path('js/agent-widget.js'));
        $page = (string) file_get_contents(public_path('js/agents.js'));

        // The caller's words come on their own, often after the agent has started its answer: words of a
        // line said before it (not cut short) go above it, and the answer is not split.
        $this->assertStringContainsString('var late = !!agentText.trim() && !cutLine && callerVoiceAt < lineAt;', $call);
        $this->assertStringContainsString("if (agentText.trim() && !late) { turn('agent', agentText); agentText = ''; }", $call);
        $this->assertStringContainsString('opts.onHear(true, late);', $call);
        $this->assertStringContainsString("if (callerText.trim() && !lateCaller) { turn('caller', callerText); callerText = ''; }", $call);
        $this->assertStringContainsString('lateTimer = setTimeout(endLate, LATE_GAP);', $call);
        $this->assertMatchesRegularExpression('/function flushTurns\(\) \{\s*endLate\(\);/', $call);
        $this->assertStringContainsString('if (sc.interrupted) { endLate(); cutLine = true; hush();', $call);
        $this->assertStringContainsString('if (fresh) { lineAt = Date.now(); cutLine = false; }', $call);
        // Both cards put such a line above the answer that is coming.
        $this->assertStringContainsString('function hear(on, before) {', $widget);
        $this->assertStringContainsString('var next = before && ((liveView && liveView.el) || typing);', $widget);
        $this->assertStringContainsString('function hear(on, before) {', $page);
        $this->assertStringContainsString('place(hearing, before);', $page);

        // While the agent speaks, what the speakers leave in the microphone goes as silence: only a caller
        // plainly speaking over it gets through (with the 0.4 s before), so its own echo never cuts it.
        $this->assertStringContainsString('var ECHO_TAIL = 300, BARGE_MIN = 1000, BARGE_PIECES = 3, BARGE_LEARN = 400, BARGE_HOLD = 4;', $call);
        $this->assertStringContainsString('l > Math.max(BARGE_MIN, 3 * echo)', $call);
        $this->assertStringContainsString('return [new ArrayBuffer(buffer.byteLength)];', $call);
        $this->assertStringContainsString('gate(e.data, agentOn, now).forEach(function (b) {', $call);
        $this->assertStringContainsString('var now = Date.now(), agentOn = sources.size > 0 || now - voiceEndAt < ECHO_TAIL;', $call);
        $this->assertStringNotContainsString("ws.send(JSON.stringify({ realtimeInput: { audio: { data: b64(e.data)", $call);
    }

    public function test_the_call_cards_orb_flows_like_the_sites_own_orbs(): void
    {
        $widget = (string) file_get_contents(public_path('js/agent-widget.js'));
        $orbs = (string) file_get_contents(public_path('js/listen-orbs.js'));

        // The same light as Listen's own orbs, drawn in the card's own canvas, moving with the voice.
        foreach (['float v = fbm(s * 0.8 + 1.7 * w);', 'float ring = sin(r * 17.0 + (w.x - 0.5) * 7.0 - uTime * 5.0);', "col = mix(col, mix(uC2, uC3, 0.7), uLift);"] as $line) {
            $this->assertStringContainsString($line, $orbs);
            $this->assertStringContainsString($line, $widget);
        }
        $this->assertStringContainsString('if (!small) flowOrb(orbEl);', $widget);
        $this->assertStringContainsString('orbEl.__lvl = Math.max(a, c * 0.35);', $widget);
        $this->assertStringContainsString("'.lva-orb.is-gl::before,.lva-orb.is-gl::after{display:none}',", $widget);
        // Without WebGL the CSS ball stays; it stops (and gives its context back) once its card is gone.
        $this->assertStringContainsString("try { gl = cv.getContext('webgl'", $widget);
        $this->assertStringContainsString("if (lose) lose.loseContext(); return;", $widget);
    }
}
