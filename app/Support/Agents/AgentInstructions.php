<?php

namespace App\Support\Agents;

use App\Models\Agent;
use App\Support\ArabicDialects;
use Illuminate\Support\Str;

/**
 * What a Listen agent is told before every call: who it is, the business it
 * speaks for, the dialect it speaks (with that dialect's own sounds and
 * words, so a Saudi agent never slips into Egyptian), the facts it answers
 * from, its steps, and the rules every agent keeps:
 *
 * - it answers only about the business, from its facts, and never guesses:
 *   anything it does not know, it offers to have the team call back;
 * - it keeps replies short, one question at a time, and reads a phone number
 *   back before saving it;
 * - it says it is an AI assistant if asked, and passes the caller to a person
 *   when they ask or when the owner's rules say so.
 */
final class AgentInstructions
{
    public const MAX_KNOWLEDGE_CHARS = 18_000;

    public static function build(Agent $agent): string
    {
        $script = $agent->script ?? [];
        $arabic = $agent->language !== 'en';
        $lines = [];

        $lines[] = "You are {$agent->name}, the voice assistant of {$agent->business_name}. You talk with its customers on a live voice call.";
        if ($identity = trim((string) ($script['identity'] ?? ''))) {
            $lines[] = $identity;
        }

        $lines[] = '';
        $lines[] = '# How you speak';
        $lines[] = self::speech($agent);
        $lines[] = 'Keep every reply short: one or two sentences, then let the caller speak. Ask one question at a time. Sound warm, natural and polite, like the best employee of this business, never like a robot reading a list.';
        $lines[] = 'Say numbers, prices and times the way people say them aloud in this dialect.';

        $lines[] = '';
        $lines[] = '# Rules you always keep';
        $lines[] = '- Answer only about this business and what its customers need from it. If asked about anything else, say politely that you can only help with '.$agent->business_name.'.';
        $lines[] = '- Use only the facts below. Never invent a price, a date, an offer, a policy or a promise. If the answer is not in the facts, say you will check with the team, and offer to take the caller\'s name and phone number so the team calls back.';
        $lines[] = '- When the caller gives their name, phone number or email, repeat the phone number back digit by digit to confirm it, then call the save_contact tool. Never ask for card numbers, passwords or ID numbers.';
        $lines[] = '- If the caller asks whether you are a person, say honestly that you are an AI voice assistant of '.$agent->business_name.'.';
        $lines[] = '- Do not give medical, legal or financial advice beyond the business\'s own facts.';
        $lines[] = '- When the conversation is finished, say goodbye briefly and call the end_call tool.';
        foreach ((array) ($script['never'] ?? []) as $rule) {
            if ($rule = trim((string) $rule)) {
                $lines[] = '- '.$rule;
            }
        }

        if ($handoff = trim((string) ($script['handoff'] ?? ''))) {
            $lines[] = '';
            $lines[] = '# When to pass the caller to a person';
            $lines[] = $handoff.' In that case, take their name and phone number, save them, and tell them the team will contact them soon.';
        }

        $steps = array_values(array_filter(array_map('trim', (array) ($script['steps'] ?? []))));
        if ($steps !== []) {
            $lines[] = '';
            $lines[] = '# Your steps on a call';
            foreach ($steps as $i => $step) {
                $lines[] = ($i + 1).'. '.$step;
            }
        }

        foreach ((array) ($agent->choices ?? []) as $choice) {
            if (! empty($choice['question']) && ! empty($choice['answer'])) {
                $lines[] = '';
                $lines[] = '# Decided by the business';
                break;
            }
        }
        foreach ((array) ($agent->choices ?? []) as $choice) {
            if (! empty($choice['question']) && ! empty($choice['answer'])) {
                $lines[] = '- '.trim((string) $choice['question']).' → '.trim((string) $choice['answer']);
            }
        }

        $knowledge = trim((string) $agent->knowledge);
        if ($knowledge !== '') {
            $lines[] = '';
            $lines[] = '# Facts about '.$agent->business_name.' (your only source)';
            $lines[] = Str::limit($knowledge, self::MAX_KNOWLEDGE_CHARS, '');
        }

        $first = trim((string) $agent->first_message);
        if ($first !== '') {
            $lines[] = '';
            $lines[] = '# How you open the call';
            $lines[] = "Start the call by saying, in your own natural words close to this: \"{$first}\"";
        }

        return trim(implode("\n", $lines));
    }

    /** The dialect, with its own sounds and everyday words, and the words of other dialects to avoid. */
    public static function speech(Agent $agent): string
    {
        if ($agent->language === 'en') {
            $accent = trim((string) (\App\Support\EnglishAccents::ALL[$agent->dialect ?? '']['prompt'] ?? $agent->setting('accent_prompt', '')));

            return 'Speak natural, friendly English'.($accent !== '' ? " with this accent: {$accent}" : '').'. If the caller speaks Arabic, answer in Arabic.';
        }

        $code = $agent->dialect ?: 'ar-XA-WH';
        $prompt = ArabicDialects::prompt($code) ?? 'Arabic';
        if ((ArabicDialects::ALL[$code]['region'] ?? null) === 'fusha') {
            return "Speak {$prompt}, clear and natural, the way a polite receptionist speaks it. If the caller speaks English, answer in English.";
        }
        $markers = ArabicDialects::markers($code);
        $out = "Speak only {$prompt}, the everyday spoken dialect, never Modern Standard Arabic unless the caller asks for it.";
        if ($markers) {
            $out .= " Its sounds and words: {$markers}";
        }
        $out .= ' Never use words from other Arabic dialects. '.self::avoid($code);
        $out .= ' If the caller speaks English, answer in English.';

        return $out;
    }

    /** The slips that give a dialect away, by region (the voice test caught a Saudi agent saying تحب). */
    public static function avoid(string $code): string
    {
        return match (ArabicDialects::ALL[$code]['region'] ?? null) {
            'nile' => 'Do not use Gulf or Levantine words such as وش، أبغى، شلون، شو، هلق.',
            'gulf' => 'Do not use Egyptian or Levantine words such as عايز، تحب (say تبي or تبغى)، إزيك، دلوقتي، هلق، كتير.',
            'levant' => 'Do not use Egyptian or Gulf words such as عايز، دلوقتي، وش، أبغى، الحين.',
            'iraq_yemen' => 'Do not use Egyptian or Levantine words such as عايز، دلوقتي، إزيك، هلق.',
            'maghreb' => 'Do not use Egyptian, Gulf or Levantine words such as عايز، وش، شو، هلق.',
            default => 'Keep to this one dialect from the first word to the last.',
        };
    }
}
