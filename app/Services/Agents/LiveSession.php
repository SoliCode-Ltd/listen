<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Support\Agents\AgentInstructions;

/**
 * The live call setup of an agent, sent to Gemini inside its short-lived key
 * (GeminiClient::liveToken): with the setup locked in the key, the browser
 * that holds it can only have this conversation, in this voice, with these
 * instructions and tools, and only until the key runs out.
 *
 * Tools the agent may call during a call (the browser carries them to Listen):
 *   save_contact(name, phone, email, note)  the customer's details
 *   end_call(reason)                        the conversation is over
 */
final class LiveSession
{
    public const DEFAULT_VOICE = 'Kore';

    /** @return array<string, mixed> the BidiGenerateContentSetup, model included */
    public static function setupFor(Agent $agent): array
    {
        $instructions = AgentInstructions::build($agent);
        // Every turn is billed again for all the call keeps in mind, so the conversation it keeps
        // besides the instructions is bounded: past it, the oldest turns drop out (to half of it),
        // and a long call costs about the same each minute (App\Support\Agents\AgentCost).
        $keep = max(2000, (int) config('listen.agents.history_tokens', 8000));
        $base = \App\Support\Agents\AgentCost::tokensIn($instructions);

        return [
            'model' => 'models/'.GeminiClient::LIVE_MODEL,
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => [
                    'voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $agent->live_voice ?: self::DEFAULT_VOICE]],
                ],
            ],
            'systemInstruction' => ['parts' => [['text' => $instructions]]],
            'tools' => [['functionDeclarations' => self::tools()]],
            'inputAudioTranscription' => (object) [],
            'outputAudioTranscription' => (object) [],
            'contextWindowCompression' => [
                'triggerTokens' => $base + $keep,
                'slidingWindow' => ['targetTokens' => $base + intdiv($keep, 2)],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function tools(): array
    {
        return [
            [
                'name' => 'save_contact',
                'description' => 'Save the customer\'s contact details once they have given them and you have read the phone number back to them.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name' => ['type' => 'STRING', 'description' => 'The customer\'s name'],
                        'phone' => ['type' => 'STRING', 'description' => 'Their phone number, digits only with the country code if they gave it'],
                        'email' => ['type' => 'STRING', 'description' => 'Their email, if they gave it'],
                        'note' => ['type' => 'STRING', 'description' => 'What they want, in one short sentence, in the language of the call'],
                    ],
                ],
            ],
            [
                'name' => 'end_call',
                // The test calls on staging: an agent ended an emergency call on its own as "out_of_scope".
                'description' => 'End the call after your goodbye, once the caller has said goodbye or that there is nothing else (or as your rules say for abuse, or when told the caller has gone quiet). Never end a call because the caller has an urgent problem or asked for something you cannot help with.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'reason' => ['type' => 'STRING', 'description' => 'Why the call ends: done, caller_left, out_of_scope (the call was only about something this business does not do), handoff, or abusive (the caller insulted you, harassed you, or kept pushing you to break your rules after one warning)'],
                    ],
                ],
            ],
        ];
    }
}
