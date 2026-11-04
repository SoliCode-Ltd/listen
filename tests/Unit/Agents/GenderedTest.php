<?php

namespace Tests\Unit\Agents;

use App\Support\Agents\Gendered;
use Tests\TestCase;

/** Arabic words that follow the agent's voice: a woman's voice gets the _female lines. */
class GenderedTest extends TestCase
{
    public function test_a_womans_voice_gets_the_female_lines_and_the_twins_are_dropped(): void
    {
        $lines = ['idle' => 'جاهز متى شئت', 'idle_female' => 'جاهزة متى شئت', 'speaking' => ':name يتحدث', 'speaking_female' => ':name تتحدث', 'ended' => 'انتهت المكالمة'];

        $this->assertSame(['idle' => 'جاهزة متى شئت', 'speaking' => ':name تتحدث', 'ended' => 'انتهت المكالمة'], Gendered::lines($lines, true));
        $this->assertSame(['idle' => 'جاهز متى شئت', 'speaking' => ':name يتحدث', 'ended' => 'انتهت المكالمة'], Gendered::lines($lines, false));
    }

    public function test_one_line_falls_back_when_there_is_no_female_form(): void
    {
        app()->setLocale('ar');
        $this->assertSame(__('agents.talk_states.idle_female'), Gendered::line('agents.talk_states.idle', true));
        $this->assertSame(__('agents.talk_states.idle'), Gendered::line('agents.talk_states.idle', false));

        app()->setLocale('en');
        // English has one form for both.
        $this->assertSame(__('agents.talk_states.idle'), Gendered::line('agents.talk_states.idle', true));
    }
}
