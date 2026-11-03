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
        return [
            'model' => 'models/'.GeminiClient::LIVE_MODEL,
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => [
                    'voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $agent->live_voice ?: self::DEFAULT_VOICE]],
                ],
            ],
            'systemInstruction' => ['parts' => [['text' => AgentInstructions::build($agent)]]],
            'tools' => [['functionDeclarations' => self::tools()]],
            'inputAudioTranscription' => (object) [],
            'outputAudioTranscription' => (object) [],
            // Long calls stay light: the oldest turns drop out once the conversation grows.
            'contextWindowCompression' => ['slidingWindow' => (object) []],
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
                'description' => 'End the call after saying goodbye, when the conversation is finished.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'reason' => ['type' => 'STRING', 'description' => 'Why the call ends: done, caller_left, out_of_scope or handoff'],
                    ],
                ],
            ],
        ];
    }
}
