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

    // A page built with a page builder carries a megabyte or more of styles and scripts before its
    // words (a society's board page: 1.5 MB, its words at the very end).
    public const MAX_BYTES = 4_000_000;

    public const MAX_CHARS = 24_000;

    private const LIKELY = ['menu', 'about', 'services', 'service', 'pricing', 'prices', 'price', 'contact', 'products', 'shop',
        'faq', 'hours', 'booking', 'book', 'clinic', 'doctors', 'team', 'offers', 'branches', 'location',
        'من-نحن', 'خدماتنا', 'الخدمات', 'الأسعار', 'اتصل-بنا', 'تواصل', 'المنيو', 'العروض', 'الفروع'];

    /** The pages of a website read for its agent to look things up in during a call (site()). */
    public const SITE_PAGES = 40;

    /** The most of one page's words kept for looking things up. */
    public const PAGE_CHARS = 8_000;

    /** The parts of a page a visitor does not read as its words. */
    private const HIDDEN = ['head', 'title', 'script', 'style', 'noscript', 'svg', 'template', 'iframe', 'form', 'nav', 'header', 'footer'];

    /** The parts of a page that hold neither words nor links. */
    private const NOISE = ['script', 'style', 'noscript', 'svg', 'template'];

    /**
     * links: the business's own accounts on social media sites, from every page read (the icons in
     * its header and footer too, which the text leaves out), one on each network (AgentLinks).
     *
     * @return array{ok: bool, text: string, pages: array<int, array{url: string, title: string, chars: int}>, links: list<array{network: string, url: string}>, error: ?string}
     */
    public function read(string $url, ?string $language = null): array
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
        foreach ($this->nextPages($first['html'], $first['url'], $language) as $link) {
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

    /**
     * As much of the website as can be read by $until, at most $max pages: the page given, then
     * the pages it and the others link to and the ones its sitemap lists, the most useful first
     * (prices, services, contact; pages in the agent's language; pages near the top). Only the
     * site's own pages: never sign-in, cart, search, tag or author pages, or files.
     *
     * Each page keeps its words (at most PAGE_CHARS) and the pictures in its main part, for a
     * page whose words are in a picture (PictureReader). done: nothing was left to read.
     *
     * @return array{pages: list<array{url: string, title: string, text: string, pictures: list<string>}>, links: list<array{network: string, url: string}>, done: bool}
     */
    public function site(string $url, ?string $language = null, ?float $until = null, int $max = self::SITE_PAGES): array
    {
        $until ??= microtime(true) + 75;
        $start = self::normalizeUrl($url);
        // The home page of a bare address ("https://clinic.example") is its "/".
        if ($start !== null && (string) parse_url($start, PHP_URL_PATH) === '' && ! str_contains($start, '?')) {
            $start .= '/';
        }
        $first = $start !== null ? $this->fetch($start) : null;
        if ($first === null) {
            return ['pages' => [], 'links' => [], 'done' => true];
        }
        $host = self::bareHost($first['url']);
        $seen = [self::pageKey($start) => true, self::pageKey($first['url']) => true];
        $queue = [];
        $add = function (array $links, int $depth) use (&$queue, &$seen, $host): void {
            foreach ($links as $link) {
                $key = self::pageKey($link['url']);
                if (isset($seen[$key]) || self::bareHost($link['url']) !== $host || self::skippable($link['url'])) {
                    continue;
                }
                if (isset($queue[$key])) {
                    $queue[$key]['score'] = max($queue[$key]['score'], $link['score']);
                    $queue[$key]['linked'] = $queue[$key]['linked'] || ! $link['sitemap'];
                    $queue[$key]['depth'] = min($queue[$key]['depth'], $depth);

                    continue;
                }
                $queue[$key] = ['url' => $link['url'], 'score' => $link['score'], 'linked' => ! $link['sitemap'], 'depth' => $depth];
            }
        };
        $keep = fn (array $page): array => ['url' => $page['url'], 'title' => $page['title'], 'text' => Str::limit($page['text'], self::PAGE_CHARS, ''), 'pictures' => self::pictures($page['html'], $page['url'])];
        $found = AgentLinks::inHtml($first['html'], $first['url']);
        $pages = [$keep($first)];
        $add(self::linksOn($first['html'], $first['url']), 1);
        $add(array_map(fn (string $u): array => ['url' => $u, 'score' => 0, 'sitemap' => true], $this->sitemap($first['url'], $until)), 2);

        while (count($pages) < $max && $queue !== [] && microtime(true) < $until) {
            // Pages in the agent's language first, once the site shows it has them (/ar/…).
            $own = $language !== null && array_filter($queue, fn (array $q): bool => self::languageOf($q['url']) === $language) !== [];
            $best = null;
            $bestScore = PHP_INT_MIN;
            foreach ($queue as $key => $q) {
                $score = self::rank($q, $language, $own);
                if ($score > $bestScore) {
                    [$best, $bestScore] = [$key, $score];
                }
            }
            $next = $queue[$best];
            unset($queue[$best]);
            $page = $this->fetch($next['url']);
            if ($page === null) {
                continue;
            }
            $key = self::pageKey($page['url']);
            // A page that sends the reader on to a sign-in page, or to one already read.
            if (($key !== $best && isset($seen[$key])) || self::bareHost($page['url']) !== $host || self::skippable($page['url'])) {
                continue;
            }
            $seen[$key] = $seen[$best] = true;
            foreach (AgentLinks::inHtml($page['html'], $page['url']) as $k => $account) {
                $found[$k] = isset($found[$k]) ? ['seen' => $found[$k]['seen'] + $account['seen']] + $found[$k] : $account;
            }
            $kept = $keep($page);
            if (trim($kept['text']) !== '' || $kept['pictures'] !== []) {
                $pages[] = $kept;
            }
            $add(self::linksOn($page['html'], $page['url']), $next['depth'] + 1);
        }

        return ['pages' => self::withoutRepeats($pages), 'links' => AgentLinks::pick($found), 'done' => $queue === [] || count($pages) >= $max];
    }

    /**
     * The lines found on most pages (the footer's phone and copyright, a menu the page builder
     * draws without a nav tag) kept on the first page only: every other page keeps its own words.
     *
     * @param  list<array{url: string, title: string, text: string, pictures: list<string>}>  $pages
     * @return list<array{url: string, title: string, text: string, pictures: list<string>}>
     */
    private static function withoutRepeats(array $pages): array
    {
        if (count($pages) < 4) {
            return $pages;
        }
        $count = [];
        foreach ($pages as $page) {
            foreach (array_unique(array_map('trim', preg_split('/\n+/u', $page['text']) ?: [])) as $line) {
                if ($line !== '') {
                    $count[$line] = ($count[$line] ?? 0) + 1;
                }
            }
        }
        $often = max(3, (int) ceil(count($pages) * 0.4));
        $out = [];
        foreach ($pages as $i => $page) {
            if ($i > 0) {
                $lines = array_filter(array_map('trim', preg_split('/\n+/u', $page['text']) ?: []), fn (string $l): bool => $l !== '' && ($count[$l] ?? 0) < $often);
                $page['text'] = implode("\n", $lines);
            }
            if (trim($page['text']) !== '' || $page['pictures'] !== []) {
                $out[] = $page;
            }
        }

        return $out;
    }

    /**
     * The order pages are read in: prices, services and contact pages first, pages the site links
     * to before ones only its sitemap lists, near the top before deep ones, dated posts last, and
     * pages in another language than the agent's last of all.
     *
     * @param  array{url: string, score: int, linked: bool, depth: int}  $q
     */
    private static function rank(array $q, ?string $language, bool $own): int
    {
        $path = (string) parse_url($q['url'], PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', $path), 'strlen'));
        $lang = self::languageOf($q['url']);
        if ($lang !== null) {
            array_shift($segments);
        }
        $score = $q['score'] + ($q['linked'] ? 20 : 0) - 8 * max(0, count($segments) - 1) - 5 * $q['depth'];
        if (preg_match('#/(19|20)\d\d/#', $path) || preg_match('#/(news|blog|posts?|articles?|events?|media)/[^/]+#i', $path)) {
            $score -= 20;
        }
        if ($language !== null) {
            $score += $lang === $language ? 30 : ($lang !== null ? -80 : ($own ? -40 : 0));
        }

        return $score;
    }

    /**
     * The pages read after the first one: those about prices, services, hours and contact, then
     * the site's other own pages in the order its menu lists them, in the agent's language first.
     *
     * @return list<string>
     */
    private function nextPages(string $html, string $base, ?string $language): array
    {
        $links = array_values(array_filter(self::linksOn($html, $base),
            fn (array $l): bool => self::bareHost($l['url']) === self::bareHost($base) && ! self::skippable($l['url'])
                && rtrim($l['url'], '/') !== rtrim(strtok($base, '?'), '/') && ! preg_match('#/(news|blog|posts?|articles?|events?)/[^/]+#i', (string) parse_url($l['url'], PHP_URL_PATH))));
        $own = $language !== null && array_filter($links, fn (array $l): bool => self::languageOf($l['url']) === $language) !== [];
        $order = [];
        foreach ($links as $i => $l) {
            $lang = self::languageOf($l['url']);
            $wrong = $language !== null && ($lang !== null ? $lang !== $language : $own);
            $order[$l['url']] = max($order[$l['url']] ?? PHP_INT_MIN, ($l['score'] > 0 ? 1000 + $l['score'] : 500 - $i) - ($wrong ? 2000 : 0));
        }
        arsort($order);

        return array_slice(array_keys($order), 0, self::MAX_PAGES - 1);
    }

    /**
     * The page's links with how likely each holds what callers ask (prices, services, contact).
     *
     * @return list<array{url: string, score: int, sitemap: bool}>
     */
    private static function linksOn(string $html, string $base): array
    {
        if (! preg_match_all('/<a\s[^>]*href\s*=\s*["\']([^"\'#]+)["\'][^>]*>(.*?)<\/a>/isu', self::lean($html), $m, PREG_SET_ORDER)) {
            return [];
        }
        $out = [];
        foreach ($m as $a) {
            $abs = self::absolute(html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $base);
            if ($abs === null || ! preg_match('#^https?://#i', $abs)) {
                continue;
            }
            $abs = self::withoutTracking($abs);
            // The page's path and its link text, not the host (a clinic's own name would match every page).
            $hay = mb_strtolower(rawurldecode((string) parse_url($abs, PHP_URL_PATH)).' '.strip_tags($a[2]));
            $score = 0;
            foreach (self::LIKELY as $i => $word) {
                if (str_contains($hay, $word)) {
                    $score = max($score, 100 - $i);
                }
            }
            $out[] = ['url' => $abs, 'score' => $score, 'sitemap' => false];
        }

        return $out;
    }

    /**
     * The pages the website's sitemap lists (sitemap.xml, a sitemap index's page sitemaps first),
     * at most 400.
     *
     * @return list<string>
     */
    private function sitemap(string $base, float $until): array
    {
        $root = strtolower((string) parse_url($base, PHP_URL_SCHEME)).'://'.parse_url($base, PHP_URL_HOST);
        foreach (['/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml'] as $path) {
            if (microtime(true) > $until - 30) {
                break;
            }
            $doc = $this->download($root.$path, ['xml'], 'application/xml,text/xml;q=0.9', 'ar,en;q=0.8', 3_000_000);
            if ($doc === null) {
                continue;
            }
            $locs = self::locs($doc['body']);
            if (! str_contains($doc['body'], '<sitemapindex')) {
                if ($locs !== []) {
                    return array_slice($locs, 0, 400);
                }

                continue;
            }
            usort($locs, fn (string $a, string $b): int => (str_contains($a, 'page') ? 0 : (str_contains($a, 'post') ? 2 : 1)) <=> (str_contains($b, 'page') ? 0 : (str_contains($b, 'post') ? 2 : 1)));
            $urls = [];
            foreach (array_slice($locs, 0, 4) as $child) {
                if (microtime(true) > $until - 30 || count($urls) >= 400) {
                    break;
                }
                $sub = $this->download($child, ['xml'], 'application/xml,text/xml;q=0.9', 'ar,en;q=0.8', 3_000_000);
                $urls = array_merge($urls, $sub !== null ? self::locs($sub['body']) : []);
            }

            return array_slice($urls, 0, 400);
        }

        return [];
    }

    /** @return list<string> the addresses a sitemap lists */
    private static function locs(string $xml): array
    {
        preg_match_all('#<loc>\s*(?:<!\[CDATA\[)?\s*(https?://[^<\s\]]+)#i', $xml, $m);

        return array_values(array_unique(array_map(fn (string $u): string => html_entity_decode($u, ENT_QUOTES | ENT_XML1, 'UTF-8'), $m[1])));
    }

    /**
     * The pictures in the main part of a page (not its header, menu or footer), biggest-looking
     * first: never logos, icons, flags or drawings. At most three.
     *
     * @return list<string>
     */
    public static function pictures(string $html, string $base): array
    {
        $main = self::withoutComments(self::withoutBlocks(self::utf8($html), self::HIDDEN));
        if (! preg_match_all('/<img\b[^>]*>/i', $main, $tags)) {
            return [];
        }
        $out = [];
        foreach ($tags[0] as $tag) {
            $attr = function (string $name) use ($tag): string {
                return preg_match('/\s'.$name.'\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $m) ? html_entity_decode(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
            };
            $src = '';
            foreach (['data-src', 'data-lazy-src', 'data-original', 'src'] as $name) {
                $value = trim($attr($name));
                if ($value !== '' && ! str_starts_with($value, 'data:')) {
                    $src = $value;
                    break;
                }
            }
            // The largest picture a srcset offers.
            if (($set = $attr('srcset')) !== '' && preg_match_all('/(\S+)\s+(\d+)w/', $set, $c, PREG_SET_ORDER)) {
                usort($c, fn ($a, $b) => (int) $b[2] <=> (int) $a[2]);
                $src = $c[0][1];
            }
            $abs = $src !== '' ? self::absolute($src, $base) : null;
            $hay = strtolower(rawurldecode((string) $abs).' '.$attr('class').' '.$attr('alt').' '.$attr('id'));
            $small = fn (string $n): bool => ($v = (int) $attr($n)) > 0 && $v < 250;
            if ($abs === null || ! preg_match('#^https?://#i', $abs) || preg_match('/\.(svg|gif|ico)(\?|$)/i', $abs)
                || preg_match('/logo|icon|favicon|flag|avatar|sprite|placeholder|spinner|loader|emoji|badge|payment|visa|mastercard|شعار/u', $hay)
                || $small('width') || $small('height')) {
                continue;
            }
            $out[$abs] = true;
            if (count($out) >= 3) {
                break;
            }
        }

        return array_keys($out);
    }

    /** The language a page's address says it is in (/ar/…, /en-gb/…), or null. */
    public static function languageOf(string $url): ?string
    {
        $first = strtolower((string) (array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH)), 'strlen'))[0] ?? ''));

        return preg_match('/^(ar|en|fr|es|de|tr|ur|fa|it|pt|ru|zh|hi|id)(?:[-_][a-z]{2})?$/', $first, $m) ? $m[1] : null;
    }

    /** Never read: sign-in, cart, search, tag, author and feed pages, numbered lists, and files. */
    private static function skippable(string $url): bool
    {
        $path = strtolower(rawurldecode((string) parse_url($url, PHP_URL_PATH)));
        $query = strtolower((string) parse_url($url, PHP_URL_QUERY));

        return (bool) preg_match('/\.(pdf|jpe?g|png|gif|webp|svg|ico|zip|rar|mp4|mp3|wav|webm|mov|avi|docx?|xlsx?|pptx?|ics|xml|json|css|js|rss|txt|woff2?)$/', $path)
            || (bool) preg_match('#/(wp-admin|wp-login|wp-json|wp-content|wp-includes|xmlrpc|feed|cdn-cgi|cart|checkout|basket|my-account|account|login|log-in|signin|sign-in|signup|sign-up|register|logout|lost-password|reset-password|forgot-password|change-password|edit-profile|profile|dashboard|search)(/|$|\.)#', $path)
            || (bool) preg_match('#/(tag|tags|category|categories|author)/#', $path)
            || (bool) preg_match('#/page/\d+#', $path)
            || (bool) preg_match('/(^|&)(s|q|search|replytocom|add-to-cart|orderby|share|print|lang|filter_[a-z_]+)=/', $query);
    }

    /** The host without its "www.", in lower case: the same website either way. */
    private static function bareHost(string $url): string
    {
        return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST))) ?? '';
    }

    /** One key for one page, however it is linked (http or https, www or not, a trailing slash). */
    private static function pageKey(string $url): string
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);

        return self::bareHost($url).rtrim(strtolower(rawurldecode((string) parse_url($url, PHP_URL_PATH))), '/').($query !== '' ? '?'.$query : '');
    }

    /** The address without what only counts visits (utm_…, fbclid) and without its #part. */
    private static function withoutTracking(string $url): string
    {
        $url = strtok($url, '#') ?: $url;
        if (! str_contains($url, '?')) {
            return $url;
        }
        [$path, $query] = explode('?', $url, 2);
        $kept = array_filter(explode('&', $query), fn (string $p): bool => $p !== '' && ! preg_match('/^(utm_[a-z]+|fbclid|gclid|mc_[a-z]+|ref)=/i', $p));

        return $path.($kept !== [] ? '?'.implode('&', $kept) : '');
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

            $body = $response->body();
            // A web page becomes UTF-8 and is cut between letters, never inside one: a cut Arabic
            // letter left the whole page unreadable (every step that reads it gave up).
            $body = str_contains($type, 'html') ? mb_strcut(self::utf8($body, $type), 0, $maxBytes, 'UTF-8') : substr($body, 0, $maxBytes);

            return ['url' => $url, 'type' => $type, 'body' => $body];
        }

        return null;
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
        $html = self::withoutComments(self::withoutBlocks(self::utf8($html), self::HIDDEN));
        $html = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6]|\/tr|\/section|\/article)\b[^>]*>/iu', "\n", $html) ?? '';
        $html = preg_replace('/<(li)\b[^>]*>/iu', "\n- ", $html) ?? '';
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? '';
        $text = preg_replace('/\n\s*\n+/u', "\n", $text) ?? '';

        return trim($text);
    }

    /** The page without its scripts, styles, drawings and comments: its words and links only. */
    public static function lean(string $html): string
    {
        return self::withoutComments(self::withoutBlocks(self::utf8($html), self::NOISE));
    }

    /**
     * Text as UTF-8: a page in another character set (an older Arabic site's windows-1256) is
     * converted, and anything else that is not UTF-8 is replaced, so no later step gives up on it.
     */
    public static function utf8(string $text, string $type = ''): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        $charset = preg_match('/charset\s*=\s*["\']?([\w.:-]+)/i', $type, $m) || preg_match('/<meta[^>]+charset\s*=\s*["\']?([\w.:-]+)/i', substr($text, 0, 4096), $m)
            ? strtolower($m[1]) : '';
        if ($charset !== '' && ! in_array($charset, ['utf-8', 'utf8'], true)) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return mb_scrub($text, 'UTF-8');
    }

    /**
     * The HTML without these elements and all they hold. One pass from start to end, so a page of
     * any size is read in the same short time (a pattern that searched back and forth gave up on
     * pages of a megabyte, and the page then had no words).
     *
     * @param  list<string>  $tags
     */
    private static function withoutBlocks(string $html, array $tags): string
    {
        $out = '';
        $pos = 0;
        $open = '/<('.implode('|', $tags).')(?=[\s>\/])/i';
        while (preg_match($open, $html, $m, PREG_OFFSET_CAPTURE, $pos) === 1) {
            $start = $m[0][1];
            $name = strtolower($m[1][0]);
            $from = $start + strlen($m[0][0]);
            $end = false;
            while (($close = stripos($html, '</'.$name, $from)) !== false) {
                $next = $html[$close + 2 + strlen($name)] ?? '>';
                if ($next === '>' || ctype_space($next)) {
                    $end = strpos($html, '>', $close);
                    break;
                }
                $from = $close + 2;
            }
            if ($end === false) {
                // Never closed: the tag itself goes, what follows stays.
                $out .= substr($html, $pos, $start - $pos).' ';
                $pos = $start + strlen($m[0][0]);

                continue;
            }
            $out .= substr($html, $pos, $start - $pos).' ';
            $pos = $end + 1;
        }

        return $out.substr($html, $pos);
    }

    private static function withoutComments(string $html): string
    {
        $out = '';
        $pos = 0;
        while (($start = strpos($html, '<!--', $pos)) !== false) {
            $out .= substr($html, $pos, $start - $pos).' ';
            $end = strpos($html, '-->', $start + 4);
            if ($end === false) {
                return $out;
            }
            $pos = $end + 3;
        }

        return $out.substr($html, $pos);
    }
}
