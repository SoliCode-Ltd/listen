<?php

namespace App\Services\Agents;

use App\Support\Agents\AgentLinks;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Reads a business's website so its agent can answer from it: the page the
 * owner gave, then a few of its own pages that usually hold prices, services,
 * hours and contact details (menu, about, services, pricing, contact ...).
 *
 * Only public web addresses are read (never this server, a private network or
 * a link-local address, checked on every hop), pages are small and few, and
 * what comes back is plain text: no scripts, no menus, no cookie banners.
 */
class SiteReader
{
    public const MAX_PAGES = 6;

    public const MAX_BYTES = 1_500_000;

    public const MAX_CHARS = 24_000;

    private const LIKELY = ['menu', 'about', 'services', 'service', 'pricing', 'prices', 'price', 'contact', 'products', 'shop',
        'faq', 'hours', 'booking', 'book', 'clinic', 'doctors', 'team', 'offers', 'branches', 'location',
        'من-نحن', 'خدماتنا', 'الخدمات', 'الأسعار', 'اتصل-بنا', 'تواصل', 'المنيو', 'العروض', 'الفروع'];

    /**
     * links: the business's own accounts on social media sites, from every page read (the icons in
     * its header and footer too, which the text leaves out), one on each network (AgentLinks).
     *
     * @return array{ok: bool, text: string, pages: array<int, array{url: string, title: string, chars: int}>, links: list<array{network: string, url: string}>, error: ?string}
     */
    public function read(string $url): array
    {
        $start = self::normalizeUrl($url);
        if ($start === null) {
            return ['ok' => false, 'text' => '', 'pages' => [], 'links' => [], 'error' => 'invalid_url'];
        }

        $first = $this->fetch($start);
        if ($first === null) {
            return ['ok' => false, 'text' => '', 'pages' => [], 'links' => [], 'error' => 'unreachable'];
        }

        $found = AgentLinks::inHtml($first['html'], $first['url']);
        $pages = [['url' => $first['url'], 'title' => $first['title'], 'text' => $first['text']]];
        foreach ($this->likelyLinks($first['html'], $first['url']) as $link) {
            if (count($pages) >= self::MAX_PAGES) {
                break;
            }
            $page = $this->fetch($link);
            if ($page === null) {
                continue;
            }
            foreach (AgentLinks::inHtml($page['html'], $page['url']) as $key => $account) {
                if (isset($found[$key])) {
                    $found[$key]['seen'] += $account['seen'];
                } else {
                    $found[$key] = $account;
                }
            }
            if (trim($page['text']) !== '') {
                $pages[] = ['url' => $page['url'], 'title' => $page['title'], 'text' => $page['text']];
            }
        }

        $text = '';
        $seen = [];
        foreach ($pages as $p) {
            $lines = [];
            foreach (preg_split('/\n+/u', $p['text']) as $line) {
                $line = trim($line);
                // The same line on every page (menus, footers) once only.
                if ($line === '' || isset($seen[$line])) {
                    continue;
                }
                $seen[$line] = true;
                $lines[] = $line;
            }
            if ($lines !== []) {
                $text .= '## '.($p['title'] !== '' ? $p['title'] : $p['url'])."\n".implode("\n", $lines)."\n\n";
            }
        }
        $text = Str::limit(trim($text), self::MAX_CHARS, '');

        return [
            'ok' => $text !== '',
            'text' => $text,
            'pages' => array_map(fn ($p) => ['url' => $p['url'], 'title' => $p['title'], 'chars' => mb_strlen($p['text'])], $pages),
            'links' => AgentLinks::pick($found),
            'error' => $text === '' ? 'empty' : null,
        ];
    }

    public static function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) && ! preg_match('#^https?://#i', $url)) {
            return null;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        if (! $parts || empty($parts['host']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }

    /** Whether a host may be read: a public name or address only. */
    public static function isPublicHost(string $host): bool
    {
        return self::publicAddress($host) !== null;
    }

    /**
     * The address a host is read from: when every address it resolves to is public, one of them
     * (IPv4 first), else null. The reader connects to this very address (options()), so a name
     * that answers a public address to this check and a private one to a second lookup (DNS
     * rebinding) still never reaches this server's own network.
     */
    public static function publicAddress(string $host): ?string
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host) ? $host : null;
        }
        $v4 = array_column(@dns_get_record($host, DNS_A) ?: [], 'ip');
        $v6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');
        if ($v4 === [] && $v6 === []) {
            $ip = gethostbyname($host);
            $v4 = $ip !== $host ? [$ip] : [];
        }
        $ips = array_merge($v4, $v6);
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp((string) $ip)) {
                return null;
            }
        }

        return (string) ($v4[0] ?? $ips[0]);
    }

    /** A public internet address: not private, reserved, shared (100.64/10), link-local or loopback. */
    public static function isPublicIp(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE);
    }

    /** The address $host is read from, or null when it may not be read. */
    protected function addressFor(string $host): ?string
    {
        return self::publicAddress($host);
    }

    /**
     * The request's options: no redirects followed on their own (each hop is checked), and the
     * connection pinned to the address that was checked.
     *
     * @return array<string, mixed>
     */
    public static function options(string $url, string $address): array
    {
        $options = ['allow_redirects' => false, 'stream' => false];
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if (filter_var($address, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP) && defined('CURLOPT_RESOLVE')) {
            $port = (int) (parse_url($url, PHP_URL_PORT) ?: (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'http' ? 80 : 443));
            $pinned = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$address}]" : $address;
            $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinned}"]];
        }

        return $options;
    }

    /** @return array{url: string, title: string, text: string, html: string}|null */
    protected function fetch(string $url): ?array
    {
        $page = $this->download($url);
        if ($page === null) {
            return null;
        }
        $html = $page['body'];

        return ['url' => $page['url'], 'title' => self::title($html), 'text' => self::textOf($html), 'html' => $html];
    }

    /**
     * A public page or feed (also for LinkReader): every hop checked and pinned to the address
     * that was checked, at most $maxBytes of it. Null when it may not be read, does not answer,
     * or is not one of $types (parts of its Content-Type: html, xml, json).
     *
     * @param  list<string>  $types
     * @return array{url: string, type: string, body: string}|null
     */
    public function download(string $url, array $types = ['html'], string $accept = 'text/html,application/xhtml+xml', string $language = 'ar,en;q=0.8', int $maxBytes = self::MAX_BYTES): ?array
    {
        for ($hop = 0; $hop < 4; $hop++) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            $address = $this->addressFor($host);
            if ($address === null) {
                return null;
            }
            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'ListenAgentReader/1.0 (+https://listenai.studio)',
                    'Accept' => $accept,
                    'Accept-Language' => $language,
                ])->withOptions(self::options($url, $address))
                    ->timeout(12)->connectTimeout(6)->get($url);
            } catch (\Throwable) {
                return null;
            }
            if ($response->redirect()) {
                $next = $response->header('Location');
                if ($next === '') {
                    return null;
                }
                $url = self::absolute($next, $url) ?? '';
                if ($url === '') {
                    return null;
                }

                continue;
            }
            $type = strtolower($response->header('Content-Type'));
            if (! $response->successful() || ! Str::contains($type, $types)) {
                return null;
            }

            return ['url' => $url, 'type' => $type, 'body' => substr($response->body(), 0, $maxBytes)];
        }

        return null;
    }

    /** @return array<int, string> */
    protected function likelyLinks(string $html, string $base): array
    {
        $baseHost = strtolower((string) parse_url($base, PHP_URL_HOST));
        preg_match_all('/<a\s[^>]*href\s*=\s*["\']([^"\'#]+)["\'][^>]*>(.*?)<\/a>/isu', $html, $m, PREG_SET_ORDER);
        $scored = [];
        foreach ($m as $a) {
            $abs = self::absolute(html_entity_decode($a[1]), $base);
            if ($abs === null || strtolower((string) parse_url($abs, PHP_URL_HOST)) !== $baseHost) {
                continue;
            }
            $abs = strtok($abs, '?');
            if (preg_match('/\.(pdf|jpe?g|png|gif|webp|svg|zip|mp4|mp3)$/i', $abs) || rtrim($abs, '/') === rtrim(strtok($base, '?'), '/')) {
                continue;
            }
            // The page's path and its link text, not the host (a clinic's own name would match every page).
            $hay = mb_strtolower(rawurldecode((string) parse_url($abs, PHP_URL_PATH)).' '.strip_tags($a[2]));
            $score = 0;
            foreach (self::LIKELY as $i => $word) {
                if (str_contains($hay, $word)) {
                    $score = max($score, 100 - $i);
                }
            }
            if ($score > 0) {
                $scored[$abs] = max($scored[$abs] ?? 0, $score);
            }
        }
        arsort($scored);

        return array_slice(array_keys($scored), 0, self::MAX_PAGES - 1);
    }

    public static function absolute(string $href, string $base): ?string
    {
        $href = trim($href);
        if ($href === '' || preg_match('#^(mailto|tel|javascript|data):#i', $href)) {
            return null;
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $b = parse_url($base);
        if (! $b || empty($b['host'])) {
            return null;
        }
        $root = ($b['scheme'] ?? 'https').'://'.$b['host'].(isset($b['port']) ? ':'.$b['port'] : '');
        if (str_starts_with($href, '//')) {
            return ($b['scheme'] ?? 'https').':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $root.$href;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');

        return $root.$dir.$href;
    }

    public static function title(string $html): string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/isu', $html, $m)) {
            return Str::limit(trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 120, '');
        }

        return '';
    }

    /** The words a visitor reads: no scripts, styles, menus, headers, footers or forms. */
    public static function textOf(string $html): string
    {
        $html = preg_replace('/<(head|title|script|style|noscript|svg|template|iframe|form|nav|header|footer)\b[^>]*>.*?<\/\1>/isu', ' ', $html) ?? '';
        $html = preg_replace('/<!--.*?-->/su', ' ', $html) ?? '';
        $html = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6]|\/tr|\/section|\/article)\b[^>]*>/iu', "\n", $html) ?? '';
        $html = preg_replace('/<(li)\b[^>]*>/iu', "\n- ", $html) ?? '';
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? '';
        $text = preg_replace('/\n\s*\n+/u', "\n", $text) ?? '';

        return trim($text);
    }
}
