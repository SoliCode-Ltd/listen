<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Api\V1\HistoryController;
use App\Mcp\McpCaller;
use App\Mcp\SpeechLinks;
use App\Mcp\SpeechSummary;
use App\Models\AudioGeneration;
use App\Services\Api\ApiSpeechService;
use App\Support\Api\ApiScopes;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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
 * One recording from the history (GET /v1/history/{id}), with a fresh link.
 */
#[Name('get_speech')]
#[Title('Get speech')]
#[Description('Check on speech made earlier, by its history_item_id: whether it is ready, and a fresh link to play or download it (it works for as many days as their plan keeps audio history). Use it when text_to_speech said the speech was still being made, when an old link has expired, or to get the recording in another file type (output_format).')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetSpeech extends ListenTool
{
    protected function scope(): string
    {
        return ApiScopes::HISTORY_READ;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'history_item_id' => $schema->integer()->required()->min(1)
                ->description('The history_item_id that text_to_speech or list_speech_history returned.'),
            'output_format' => $schema->string()
                ->description('File type of the link: mp3 (the default) or another one the plan includes, listed by get_voice_options (such as wav or m4a).'),
        ];
    }

    protected function run(Request $request, McpCaller $caller): Response|ResponseFactory
    {
        $input = $request->validate(['history_item_id' => ['required', 'integer', 'min:1'], 'output_format' => ['nullable', 'string', 'max:10']]);
        $id = (string) (int) $input['history_item_id'];
        $format = strtolower(trim((string) ($input['output_format'] ?? ''))) ?: 'mp3';
        // As the API's download does: a type the plan does not include is refused, naming the plans that do.
        app(ApiSpeechService::class)->assertOutputFormat($format, $caller->plan);
        $item = SpeechLinks::withLink($caller->call(HistoryController::class, 'show', 'GET', '/history/'.$id, [], [$id]), SpeechLinks::daysFor($caller->user), $format);
        $caller->log($this->name(), ['history_item_id' => (int) $id]);

        $text = match ($item['status']) {
            'completed' => SpeechSummary::text($item),
            'failed' => 'This speech failed: '.($item['error']['message'] ?? 'voice generation failed').' No credits were charged for it.',
            default => 'Still being made ('.$item['status'].'). Check again in a few seconds.',
        };

        $contents = [Response::text($text)];
        if ($item['audio_url'] !== null) {
            $path = AudioGeneration::query()->whereKey((int) $id)->value('audio_path');
            $contents[] = Response::resourceLink($item['audio_url'],
                name: SpeechLinks::fileName((int) $id, $path, $format),
                mimeType: SpeechLinks::mimeType($path, $format),
                title: SpeechSummary::title($item),
                description: 'Speech made with Listen; '.SpeechLinks::lasts($item['audio_url_expires_at']).'.');
        }

        return Response::make($contents)->withStructuredContent($item);
    }
}
