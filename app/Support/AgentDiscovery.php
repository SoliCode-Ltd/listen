<?php

namespace App\Support;

use App\Services\Api\ApiSpeechService;

/**
 * What the site publishes so AI agents can find and use the developer API:
 *
 * - the API catalog at /.well-known/api-catalog (RFC 9727): where the API is,
 *   its OpenAPI description, its guide and its health check;
 * - an agent skill at /.well-known/agent-skills/ (Agent Skills Discovery
 *   v0.2.0): short instructions an agent loads to make speech with the API,
 *   listed in an index with its SHA-256 digest.
 *
 * Kept in step with routes/v1.php and App\Support\Api\OpenApiSpec.
 */
class AgentDiscovery
{
    public const SKILL = 'listen-text-to-speech';

    public const SKILLS_SCHEMA = 'https://schemas.agentskills.io/discovery/0.2.0/schema.json';

    public const CATALOG_PROFILE = 'https://www.rfc-editor.org/info/rfc9727';

    /** @return array{linkset: list<array<string, mixed>>} */
    public static function apiCatalog(): array
    {
        return ['linkset' => [[
            'anchor' => url('/v1'),
            'service-desc' => [['href' => url('/v1/openapi.json'), 'type' => 'application/vnd.oai.openapi+json']],
            'service-doc' => [
                ['href' => url('/en/developers'), 'type' => 'text/html', 'hreflang' => 'en'],
                ['href' => url('/ar/developers'), 'type' => 'text/html', 'hreflang' => 'ar'],
            ],
            'status' => [['href' => url('/up'), 'type' => 'text/html']],
        ]]];
    }

    public static function skillDescription(): string
    {
        // No ": " in it: it is a plain YAML value in the skill's front matter.
        return 'Turn Arabic and English text into natural speech with the Listen API (listenai.studio). Choose a voice, '
            .'an Arabic dialect or English accent, an emotion and a delivery style, then save the audio. Use when someone '
            .'wants an Arabic voiceover, speech in a particular Arabic dialect, English narration or a two-voice dialogue made with Listen.';
    }

    /** @return array{"$schema": string, skills: list<array<string, string>>} */
    public static function skillsIndex(): array
    {
        return [
            '$schema' => self::SKILLS_SCHEMA,
            'skills' => [[
                'name' => self::SKILL,
                'type' => 'skill-md',
                'description' => self::skillDescription(),
                'url' => '/.well-known/agent-skills/'.self::SKILL.'/SKILL.md',
                'digest' => 'sha256:'.hash('sha256', self::skillMarkdown()),
            ]],
        ];
    }

    /** The skill: what an agent needs to make speech with the API, and where the rest is. */
    public static function skillMarkdown(): string
    {
        $skillDescription = self::skillDescription();
        $base = url('/v1');
        $guide = url('/en/developers');
        $sync = number_format(ApiSpeechService::SYNC_MAX_TEXT);
        $async = number_format(ApiSpeechService::ASYNC_MAX_TEXT);
        $dialogue = number_format(ApiSpeechService::DIALOGUE_MAX_TEXT);
        $perSecond = Credits::PER_SECOND;
        $voices = StudioFacts::voices();
        $range = $voices > 0
            ? $voices.' voices, '.StudioFacts::arabicDialects().' Arabic dialects and '.StudioFacts::englishAccents().' English accents'
            : 'studio voices, Arabic dialects and English accents';

        return <<<MD
---
name: listen-text-to-speech
description: {$skillDescription}
---

# Listen text to speech

Listen turns text into natural Arabic and English speech: {$range}, with emotions, delivery styles and two-voice dialogue.

## Before you start

- The person needs a Listen account on a plan that includes the API, and an API key from the dashboard's Developer API section. Keys start with `lsk_live_`.
- Read the key from an environment variable such as `LISTEN_API_KEY`. Never print it, log it or write it into code.
- Every generation is charged to the account's credits, the same balance as the web studio: {$perSecond} credits per second of audio. Check what is left with `GET /v1/user/subscription` before a long job.

## Connect

- Base URL: `{$base}`
- Send the key as `X-Listen-Api-Key: <key>` or `Authorization: Bearer <key>`.
- Every endpoint and field: `{$base}/openapi.json` (no key needed). Guide: {$guide}

## 1. Choose a voice

```bash
curl -s {$base}/voices -H "X-Listen-Api-Key: \$LISTEN_API_KEY"
curl -s {$base}/voice-options -H "X-Listen-Api-Key: \$LISTEN_API_KEY"
```

`/voices` lists each voice's `voice_id`, name, language and gender. `/voice-options` lists the dialects and accents (such as `ar-EG`, `ar-SA`, `ar-LB`, `en-US`, `en-GB`), the styles and the emotions.

## 2. Make speech

Up to {$sync} characters: wait for the file.

```bash
curl -s -X POST "{$base}/text-to-speech/VOICE_ID?output_format=mp3" \
  -H "X-Listen-Api-Key: \$LISTEN_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"text": "أهلاً بكم في إسمع", "dialect": "ar-EG", "emotion": "warm", "style": "narrative"}' \
  --output speech.mp3
```

- Only `text` is required. `dialect` picks the Arabic dialect or English accent; `emotion` and `style` shape the delivery; with `"translate": true` the text is first translated into `language_code`.
- The answer is the audio itself. Its headers say what it cost and where to find it again: `X-Credits-Used`, `X-Credits-Remaining`, `X-Audio-Duration-Seconds` and `X-History-Item-Id`.
- `mp3` is always accepted; other formats only on plans that include them.
- To retry safely, send an `Idempotency-Key` header of your own: the same key and body within 24 hours return the first result and are not charged again.

## 3. Long texts

Up to {$async} characters: make it in the background.

1. `POST /v1/text-to-speech/VOICE_ID/async` with the same body. It answers at once (202) with a history item.
2. Poll `GET /v1/history/{id}` every few seconds until `status` is `completed` or `failed`.
3. Download it with `GET /v1/history/{id}/audio`.

For a longer text, split it at paragraph ends and join the files.

## Dialogue

On plans that include it, up to {$dialogue} characters: start each line with a speaker's name and a colon, and give each speaker a voice.

```json
{"text": "Sara: Welcome back!\\nOmar: Thanks, it's great to be here.", "dialogue": {"speakers": [{"name": "Sara", "voice_id": "VOICE_ID"}, {"name": "Omar", "voice_id": "OTHER_VOICE_ID"}]}}
```

## When something goes wrong

Errors are JSON: `{"error": {"type": "...", "code": "...", "message": "...", "param": null, "request_id": "..."}}`.

- 401: the key is missing, wrong or revoked.
- 402: not enough credits, or the plan's monthly request limit is reached. Tell the person; they can upgrade or wait for the next period.
- 403 `feature_not_in_plan`: the option needs another plan, and the message names the plans that include it. Leave the option out or tell the person.
- 422: a field is not valid; `param` names it.
- 429: too many requests or background jobs at once. Wait as long as `Retry-After` says, then try again.
- 502: the generation failed and nothing was charged; 503: briefly unavailable. Try again later with the same `Idempotency-Key`.

MD;
    }
}
