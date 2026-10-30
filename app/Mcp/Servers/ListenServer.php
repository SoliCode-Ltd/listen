<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetAccount;
use App\Mcp\Tools\GetSpeech;
use App\Mcp\Tools\GetVoiceOptions;
use App\Mcp\Tools\ListSpeechHistory;
use App\Mcp\Tools\ListVoices;
use App\Mcp\Tools\TextToSpeech;
use App\Support\Credits;
use Laravel\Mcp\Schema\Icon;
use Laravel\Mcp\Server;

/**
 * Listen's MCP server at https://<site>/mcp: AI assistants make speech for a
 * member with their API key, through the developer API's own controllers
 * (the same voices, checks, credits and limits). Described for discovery by
 * App\Mcp\ServerCard.
 */
class ListenServer extends Server
{
    public const NAME = 'Listen';

    public const VERSION = '1.0.0';

    protected string $name = self::NAME;

    protected string $version = self::VERSION;

    protected array $capabilities = [
        self::CAPABILITY_TOOLS => ['listChanged' => false],
    ];

    public const TOOLS = [
        ListVoices::class,
        GetVoiceOptions::class,
        TextToSpeech::class,
        GetSpeech::class,
        ListSpeechHistory::class,
        GetAccount::class,
    ];

    protected array $tools = self::TOOLS;

    protected function boot(): void
    {
        $perSecond = Credits::PER_SECOND;

        $this->instructions = <<<MD
            Listen turns Arabic and English text into natural speech: studio voices, Arabic dialects (such as Egyptian, Saudi, Gulf, Levantine and Moroccan) and English accents, with emotions, delivery styles and dialogue between voices.

            To make speech:
            1. Call list_voices (filter by language) and choose a voice. Its language must match the text.
            2. If the person wants a dialect, accent, style or emotion, find its code with get_voice_options.
            3. Call text_to_speech with the text exactly as it should be spoken. It returns a link the person can play or download, for as many days as their plan keeps audio history: give them the link.
            4. A long text may still be in progress: call get_speech with its history_item_id until it is completed.

            Every recording uses the person's Listen credits ({$perSecond} credits per second of audio), the same balance as the web studio. Make only the speech the person asked for, and check get_account before a long or repeated job.
            MD;
    }

    /**
     * The tools' names, as AI apps see them.
     *
     * @return list<string>
     */
    public static function toolNames(): array
    {
        return array_map(static fn (string $tool): string => app($tool)->name(), self::TOOLS);
    }

    /** @return list<Icon> */
    protected function icons(): array
    {
        return [Icon::from(url('/favicon.png'), 'image/png', ['192x192'])];
    }
}
