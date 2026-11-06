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
        $this->assertStringContainsString('if (!saidBefore && spoken(callerText).trim() && opts.onHear) opts.onHear(true);', $call);
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
}
