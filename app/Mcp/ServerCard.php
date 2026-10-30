<?php

namespace App\Mcp;

use App\Mcp\Servers\ListenServer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Mcp\Enums\ProtocolVersion;

/**
 * The MCP Server Card: what an AI app needs to know before it connects to
 * the MCP server (its name, its address, how to sign in and the protocol
 * versions it speaks). Served at /mcp/server-card (the location MCP reserves
 * for it, listed in the ARD catalog) and, in the earlier draft's shape, at
 * /.well-known/mcp/server-card.json, where some tools still look.
 */
final class ServerCard
{
    public const SCHEMA = 'https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json';

    public const MEDIA_TYPE = 'application/mcp-server-card+json';

    /** @return array<string, mixed> */
    public static function card(): array
    {
        return [
            '$schema' => self::SCHEMA,
            'name' => self::name(),
            'version' => ListenServer::VERSION,
            'title' => ListenServer::NAME,
            'description' => 'Arabic and English text to speech with studio voices, Arabic dialects and accents.',
            'websiteUrl' => url('/en/developers').'#mcp',
            'icons' => [['src' => url('/favicon.png'), 'mimeType' => 'image/png', 'sizes' => ['192x192']]],
            'remotes' => [[
                'type' => 'streamable-http',
                'url' => url('/mcp'),
                // A key, or nothing: apps that support OAuth sign in with Listen instead
                // (the 401 points them to /.well-known/oauth-protected-resource/mcp).
                'headers' => [[
                    'name' => 'Authorization',
                    'description' => 'A Listen API key, made in the dashboard\'s Developer API section. Apps that support OAuth can sign in with Listen instead.',
                    'isRequired' => false,
                    'isSecret' => true,
                    'value' => 'Bearer {api_key}',
                    'variables' => ['api_key' => [
                        'description' => 'Your Listen API key (it starts with lsk_live_).',
                        'isRequired' => true,
                        'isSecret' => true,
                    ]],
                ]],
                'supportedProtocolVersions' => self::protocolVersions(),
            ]],
        ];
    }

    /**
     * The card with the earlier draft's fields as well (serverInfo, the
     * transport and the capabilities), for /.well-known/mcp/server-card.json.
     *
     * @return array<string, mixed>
     */
    public static function wellKnown(): array
    {
        return self::card() + [
            'serverInfo' => ['name' => ListenServer::NAME, 'title' => ListenServer::NAME, 'version' => ListenServer::VERSION],
            'transport' => ['type' => 'streamable-http', 'endpoint' => url('/mcp')],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'authentication' => ['required' => true, 'schemes' => ['bearer', 'oauth2']],
        ];
    }

    /**
     * Public, cacheable, readable from any site, and answered with 304 when
     * the client already has this version.
     *
     * @param  array<string, mixed>  $card
     */
    public static function respond(Request $request, array $card, string $mediaType = self::MEDIA_TYPE): Response
    {
        $json = (string) json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $etag = '"'.substr(hash('sha256', $json), 0, 32).'"';
        $headers = [
            'Content-Type' => $mediaType,
            'Cache-Control' => 'public, max-age=3600',
            'ETag' => $etag,
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET',
            'Access-Control-Allow-Headers' => 'Content-Type, If-None-Match',
            'Access-Control-Expose-Headers' => 'ETag',
        ];

        if (in_array($etag, array_map('trim', explode(',', (string) $request->header('If-None-Match'))), true)) {
            return new Response('', 304, $headers);
        }

        return new Response($json, 200, $headers);
    }

    /** Reverse-DNS name from the site's domain: listenai.studio gives studio.listenai/listen. */
    public static function name(): string
    {
        $host = (string) parse_url(url('/'), PHP_URL_HOST);

        return implode('.', array_reverse(explode('.', $host))).'/listen';
    }

    /** @return list<string> */
    public static function protocolVersions(): array
    {
        return array_values(array_unique([...ProtocolVersion::serverSupported(), ...ProtocolVersion::initializeSupported()]));
    }
}
