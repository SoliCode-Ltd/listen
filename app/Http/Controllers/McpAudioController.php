<?php

namespace App\Http\Controllers;

use App\Mcp\SpeechLinks;
use App\Models\AudioGeneration;
use App\Support\SignedLinks;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /mcp/audio/{generation}?expires=…&signature=…: the recording an AI
 * assistant made with the MCP server, for the person to play or download
 * (App\Mcp\SpeechLinks). Only a signed, unexpired link opens it.
 */
class McpAudioController extends Controller
{
    public function __invoke(Request $request, string $generation): Response
    {
        if (! SignedLinks::valid($request)) {
            throw new InvalidSignatureException;
        }

        $made = AudioGeneration::query()->whereKey((int) $generation)->where('status', 'done')->first();
        if (! $made instanceof AudioGeneration || blank($made->audio_path)) {
            abort(404);
        }

        // The site's own file streaming (with seeking), under the recording's name.
        $response = app(MediaController::class)->show($request, (string) $made->audio_path);
        $response->headers->set('Content-Type', SpeechLinks::mimeType($made->audio_path));
        $response->headers->set('Content-Disposition', 'inline; filename="'.SpeechLinks::fileName((int) $made->id, $made->audio_path).'"');
        $response->headers->set('Cache-Control', 'private, max-age=3600');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
