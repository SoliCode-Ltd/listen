<?php

namespace App\Services\Agents;

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
     * @return array{ok: bool, text: string, pages: array<int, array{url: string, title: string, chars: int}>, error: ?string}
     */
    public function read(string $url): array
    {
        $start = self::normalizeUrl($url);
        if ($start === null) {
            return ['ok' => false, 'text' => '', 'pages' => [], 'error' => 'invalid_url'];
        }

        $first = $this->fetch($start);
        if ($first === null) {
            return ['ok' => false, 'text' => '', 'pages' => [], 'error' => 'unreachable'];
        }

        $pages = [['url' => $first['url'], 'title' => $first['title'], 'text' => $first['text']]];
        foreach ($this->likelyLinks($first['html'], $first['url']) as $link) {
            if (count($pages) >= self::MAX_PAGES) {
                break;
            }
            $page = $this->fetch($link);
            if ($page !== null && trim($page['text']) !== '') {
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
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(
            array_column(@dns_get_record($host, DNS_A) ?: [], 'ip'),
            array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        );
        if ($ips === []) {
            $ip = gethostbyname($host);
            $ips = $ip !== $host ? [$ip] : [];
        }
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    protected function hostAllowed(string $host): bool
    {
        return self::isPublicHost($host);
    }

    /** @return array{url: string, title: string, text: string, html: string}|null */
    protected function fetch(string $url): ?array
    {
        for ($hop = 0; $hop < 4; $hop++) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            if (! $this->hostAllowed($host)) {
                return null;
            }
            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'ListenAgentReader/1.0 (+https://listenai.studio)',
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'ar,en;q=0.8',
                ])->withOptions(['allow_redirects' => false, 'stream' => false])
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
            if (! $response->successful() || ! str_contains(strtolower($response->header('Content-Type')), 'html')) {
                return null;
            }
            $html = substr($response->body(), 0, self::MAX_BYTES);

            return ['url' => $url, 'title' => self::title($html), 'text' => self::textOf($html), 'html' => $html];
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
