<?php

use App\Http\Controllers\McpAudioController;
use App\Http\Middleware\ApiRequestContext;
use App\Mcp\ServerCard;
use App\Mcp\Servers\ListenServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP server for AI assistants (https://<site>/mcp)
|--------------------------------------------------------------------------
|
| Loaded by laravel/mcp, so none of this exists until the package is
| installed (composer install on the server). Signed in with an API key,
| as the developer API: the same request id, request log, key checks and
| rate limits (App\Mcp\McpErrors answers refusals as JSON-RPC errors).
|
*/

Mcp::web('/mcp', ListenServer::class)
    ->middleware(['api', ApiRequestContext::class, 'api.key', 'throttle:public-api'])
    ->name('mcp');

Route::get('/mcp/server-card', fn (Request $request) => ServerCard::respond($request, ServerCard::card()))
    ->name('mcp.server-card');
Route::get('/.well-known/mcp/server-card.json', fn (Request $request) => ServerCard::respond($request, ServerCard::wellKnown(), 'application/json'))
    ->name('mcp.server-card.well-known');

// The recordings the tools make, for the person to play (App\Mcp\SpeechLinks).
Route::get('/mcp/audio/{generation}', McpAudioController::class)
    ->whereNumber('generation')
    ->name('mcp.audio');
