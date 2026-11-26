<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\V1\TextToSpeechController;
use App\Mcp\SpeechLinks;
use App\Models\AudioGeneration;
use App\Support\AudioFormats;
use App\Support\HiddenAudio;
use App\Support\SignedLinks;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\RateLimiter;
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

        $made = HiddenAudio::visible(AudioGeneration::query())->whereKey((int) $generation)->where('status', 'done')->first();
        if (! $made instanceof AudioGeneration || blank($made->audio_path)) {
            abort(404);
        }

        // A link signed for another file type (text_to_speech's output_format): the recording in that type.
        $format = strtolower((string) $request->query('format', ''));
        if ($format !== '' && isset(AudioFormats::ALL[$format]) && TextToSpeechController::needsConversion($made, $format)) {
            // Turning a file into another type takes a processor: a few times a minute for one recording is plenty.
            $key = 'mcp-audio-convert:'.$made->id;
            if (RateLimiter::tooManyAttempts($key, 6)) {
                abort(429);
            }
            RateLimiter::hit($key, 60);

            $audio = TextToSpeechController::audioBytes($made, $format);
            if ($audio === null) {
                abort(404);
            }

            // When the server cannot make that type just now, the stored one comes back, named as what it is.
            return response($audio['bytes'], 200, [
                'Content-Type' => AudioFormats::ALL[$audio['format']]['mime'],
                'Content-Length' => (string) strlen($audio['bytes']),
                'Content-Disposition' => 'inline; filename="listen-'.$made->id.'.'.$audio['format'].'"',
                'Cache-Control' => 'private, max-age=3600',
                'X-Robots-Tag' => 'noindex',
            ]);
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
