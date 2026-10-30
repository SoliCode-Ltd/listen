<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Api\V1\HistoryController;
use App\Mcp\McpCaller;
use App\Mcp\SpeechLinks;
use App\Support\Api\ApiScopes;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The member's recent recordings, newest first (GET /v1/history): the ones
 * made here, in the web studio and with the browser extension.
 */
#[Name('list_speech_history')]
#[Title('List recent speech')]
#[Description('List the person\'s recent Listen recordings, newest first, with links to play the finished ones (they work for as many days as their plan keeps audio history). Includes recordings made in the web studio.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ListSpeechHistory extends ListenTool
{
    public const MAX = 20;

    protected function scope(): string
    {
        return ApiScopes::HISTORY_READ;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(1)->max(self::MAX)
                ->description('How many recordings to list, up to '.self::MAX.'. Default 10.'),
            'before' => $schema->integer()->min(1)
                ->description('For the next page: the next_cursor from the previous answer.'),
        ];
    }

    protected function run(Request $request, McpCaller $caller): Response|ResponseFactory
    {
        $input = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX],
            'before' => ['nullable', 'integer', 'min:1'],
        ]);
        $page = $caller->call(HistoryController::class, 'index', 'GET', '/history', array_filter([
            'page_size' => (int) ($input['limit'] ?? 10),
            'before' => $input['before'] ?? null,
        ]));

        $days = SpeechLinks::daysFor($caller->user);
        $items = array_map(static fn (array $item): array => SpeechLinks::withLink($item, $days), (array) $page['history']);
        $lines = array_map(static fn (array $item): string => '- #'.$item['history_item_id'].' '.($item['created_at'] ?? '').' '.$item['status']
            .($item['duration_seconds'] !== null ? ', '.$item['duration_seconds'].' s' : '')
            .($item['voice_name'] !== null ? ', '.$item['voice_name'] : '')
            .': "'.Str::limit(preg_replace('/\s+/u', ' ', (string) ($item['title'] ?? $item['text'])) ?? '', 60).'"'
            .($item['audio_url'] !== null ? "\n  ".$item['audio_url'] : ''), $items);

        $text = $items === []
            ? 'No recordings yet.'
            : implode("\n", $lines).($page['has_more'] ? "\nMore: call again with before = ".$page['next_cursor'].'.' : '');

        return Response::make([Response::text($text)])->withStructuredContent([
            'history' => $items,
            'has_more' => (bool) $page['has_more'],
            'next_cursor' => $page['next_cursor'],
        ]);
    }
}
