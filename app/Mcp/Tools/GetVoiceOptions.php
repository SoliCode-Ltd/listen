<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Api\V1\VoiceController;
use App\Mcp\McpCaller;
use App\Support\Api\ApiScopes;
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
 * The studio's options (GET /v1/voice-options): dialects, styles, emotions,
 * sound environments, the defaults, and what this account's plan includes.
 */
#[Name('get_voice_options')]
#[Title('Get voice options')]
#[Description('List the codes text_to_speech accepts: Arabic dialects and English accents, delivery styles and emotions, with the defaults, the speed range, and the options this account\'s plan includes (such as dialogue between voices).')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetVoiceOptions extends ListenTool
{
    protected function scope(): string
    {
        return ApiScopes::VOICES_READ;
    }

    protected function run(Request $request, McpCaller $caller): Response|ResponseFactory
    {
        $options = $caller->call(VoiceController::class, 'options', 'GET', '/voice-options');

        $codes = static fn (array $rows): string => implode(', ', array_map(static fn (array $row): string => $row['value'].' ('.$row['name'].')', $rows));
        $lines = [
            'Arabic dialects: '.$codes((array) ($options['dialects']['ar'] ?? [])),
            'English accents: '.$codes((array) ($options['dialects']['en'] ?? [])),
            'Styles: '.$codes((array) ($options['styles'] ?? [])),
            'Emotions: '.$codes((array) ($options['emotions'] ?? [])),
            'Speed: '.$options['voice_settings']['speed']['min'].' to '.$options['voice_settings']['speed']['max'].' (default '.$options['voice_settings']['speed']['default'].').',
            'Defaults: Arabic '.implode(', ', array_filter((array) ($options['defaults']['ar'] ?? []))).'; English '.implode(', ', array_filter((array) ($options['defaults']['en'] ?? []))).'.',
            'On this plan: '.implode(', ', array_keys(array_filter((array) ($options['features'] ?? [])))).'.',
        ];

        return Response::make([Response::text(implode("\n", $lines))])->withStructuredContent($options);
    }
}
