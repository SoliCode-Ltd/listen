<?php

namespace App\Http\Middleware;

use App\Support\HtmlToMarkdown;
use App\Support\SeoHelper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public pages (the ones in the sitemap), for AI agents as well as people:
 *
 * - Link headers point agents to the API catalog, the API's description and
 *   guide, llms.txt, the sitemap, and the page's Markdown version (RFC 8288).
 * - An agent that asks for Markdown (Accept: text/markdown ahead of text/html)
 *   gets the page as Markdown; browsers keep getting HTML.
 */
class ServePagesToAgents
{
    private const PAGES = [
        'home', 'pricing', 'samples', 'seo.*', 'legal.*', 'browsers-extension.page', 'developers', 'contact',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! in_array($request->method(), ['GET', 'HEAD'], true)
            || ! $request->routeIs(...self::PAGES)
            || $response->getStatusCode() !== 200
            || ! str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }

        $markdown = self::wantsMarkdown($request);
        foreach (self::links($request, $markdown) as $link) {
            $response->headers->set('Link', $link, false);
        }
        // The same address answers in two formats.
        $response->setVary('Accept', false);

        if (! $markdown) {
            return $response;
        }

        $text = HtmlToMarkdown::convert((string) $response->getContent(), $request->url());
        $response->setContent($text);
        $response->headers->set('Content-Type', 'text/markdown; charset=UTF-8');
        // A rough size for the agent's budget: about four bytes a token.
        $response->headers->set('X-Markdown-Tokens', (string) (int) ceil(strlen($text) / 4));

        return $response;
    }

    /** Markdown only when the agent ranks it above HTML; a browser, which asks for HTML or anything, keeps HTML. */
    public static function wantsMarkdown(Request $request): bool
    {
        foreach ($request->getAcceptableContentTypes() as $type) {
            $type = strtolower($type);
            if ($type === 'text/markdown' || $type === 'text/x-markdown') {
                return true;
            }
            if ($type === 'text/html' || $type === '*/*' || $type === 'text/*') {
                return false;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function links(Request $request, bool $markdown = false): array
    {
        $links = [
            '<'.url('/.well-known/api-catalog').'>; rel="api-catalog"',
            '<'.url('/v1/openapi.json').'>; rel="service-desc"; type="application/vnd.oai.openapi+json"',
            '<'.route('developers').'>; rel="service-doc"; type="text/html"',
        ];
        if (SeoHelper::llmsTxtEnabled()) {
            $links[] = '<'.url('/llms.txt').'>; rel="describedby"; type="text/plain"';
        }
        $links[] = '<'.url('/sitemap.xml').'>; rel="sitemap"; type="application/xml"';
        $links[] = '<'.$request->url().'>; rel="alternate"; type="'.($markdown ? 'text/html' : 'text/markdown').'"';

        return $links;
    }
}
