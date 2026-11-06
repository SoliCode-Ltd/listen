<?php

namespace App\Support\Agents;

use App\Models\Agent;
use App\Services\Agents\SiteReader;
use Illuminate\Support\Str;

/**
 * The business's own links that its agent writes in the call's chat when a caller asks for them:
 * its accounts on social media sites (and its chat number) and its website. They are found on its
 * website, the icons in its header and footer too (SiteReader), or added by the owner (Knowledge →
 * Social media links), and kept on the agent (settings.links).
 *
 * Only an account's own page is written in the chat, never a share button, a single post or a
 * video: an article links to those far more often than to the business's own account. The owner
 * may also add a page of another website where its news is (accept()): the agent reads it
 * (AgentLinkReads) but never writes it in the chat, so the chat never carries a link to anywhere
 * else.
 */
final class AgentLinks
{
    public const MAX = 10;

    /** The network's name, for the agent (its instructions are in English), in the order links are listed. */
    public const NAMES = [
        'x' => 'X (Twitter)', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'youtube' => 'YouTube',
        'tiktok' => 'TikTok', 'snapchat' => 'Snapchat', 'linkedin' => 'LinkedIn', 'threads' => 'Threads',
        'telegram' => 'Telegram', 'whatsapp' => 'WhatsApp', 'website' => 'Website',
    ];

    /** A link's domain => its network. */
    private const DOMAINS = [
        'facebook.com' => 'facebook', 'fb.com' => 'facebook', 'fb.me' => 'facebook',
        'instagram.com' => 'instagram', 'instagr.am' => 'instagram',
        'x.com' => 'x', 'twitter.com' => 'x',
        'linkedin.com' => 'linkedin',
        'youtube.com' => 'youtube',
        'tiktok.com' => 'tiktok',
        'snapchat.com' => 'snapchat',
        't.me' => 'telegram', 'telegram.me' => 'telegram',
        'wa.me' => 'whatsapp', 'whatsapp.com' => 'whatsapp',
        'threads.net' => 'threads', 'threads.com' => 'threads',
    ];

    /** The first part of a path that is never an account (a share button, a post, a help page). */
    private const NOT_ACCOUNTS = [
        'facebook' => ['sharer', 'sharer.php', 'share', 'share.php', 'dialog', 'plugins', 'tr', 'login', 'login.php', 'home.php', 'watch',
            'events', 'photo', 'photo.php', 'photos', 'story.php', 'permalink.php', 'hashtag', 'help', 'policies', 'privacy', 'legal',
            'business', 'ads', 'marketplace', 'gaming', 'reel', 'reels', 'video.php', 'videos', 'notes', 'l.php', 'search', 'settings',
            'messages', 'friends', 'bookmarks', 'fundraisers', 'media', 'stories', 'pg', 'pages', 'people', 'groups', 'profile.php', 'about'],
        'instagram' => ['p', 'reel', 'reels', 'explore', 'stories', 'tv', 'accounts', 'direct', 'about', 'legal', 'developer', 'web', 's', 'ar', 'tags', 'locations'],
        'x' => ['intent', 'share', 'home', 'hashtag', 'search', 'i', 'settings', 'login', 'signup', 'explore', 'messages', 'notifications', 'tos', 'privacy', 'compose', 'about', 'download'],
        'youtube' => ['watch', 'embed', 'shorts', 'playlist', 'results', 'feed', 'redirect', 'live', 'hashtag', 'post', 'premium', 'kids', 'about',
            't', 'account', 'signin', 'logout', 'upload', 'gaming', 'music', 'tv', 'attribution_link', 'iframe_api', 'yt', 's', 'channel', 'c', 'user', 'v', 'e'],
        'telegram' => ['share', 'addstickers', 'iv', 'proxy', 'socks', 'login', 'setlanguage', 'addtheme', 'joinchat', 's'],
    ];

    /**
     * A link cleaned up to the account's own page (https, no tracking or share parts), with its
     * network. $site is the business's website: its own pages count too. Null for anything else.
     *
     * @return array{network: string, url: string}|null
     */
    public static function clean(string $url, ?string $site = null): ?array
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || strlen($url) > 500 || preg_match('/[\s<>"\'\\\\`]/u', $url)) {
            return null;
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (! preg_match('#^https?://#i', $url)) {
            // Pasted without its start: "instagram.com/sos_ksa", "www.x.com/sos".
            if (! preg_match('#^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(?:[/?]|$)#i', $url)) {
                return null;
            }
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        $segments = array_values(array_filter(explode('/', (string) ($parts['path'] ?? '')), fn ($s) => $s !== ''));
        parse_str((string) ($parts['query'] ?? ''), $query);

        foreach (self::DOMAINS as $domain => $network) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                if (isset($parts['port'])) {
                    return null;
                }
                $sub = $host === $domain ? '' : substr($host, 0, -strlen($domain) - 1);
                $account = self::account($network, $domain, $sub, $segments, $query);

                return $account === null ? null : ['network' => $network, 'url' => $account];
            }
        }

        return self::websitePage($parts, $host, $segments, $query, $site);
    }

    /**
     * A link the owner adds: an account or a page of their own website (written in the chat when a
     * caller asks: shown), the account of a single X post (its newest posts are read with the
     * account's), or a page of another website anyone can open (only read, never written in the
     * chat). Null for anything else, a single post or video on another social media site too.
     *
     * @return array{network: string, url: string, shown: bool}|null
     */
    public static function accept(string $url, ?string $site = null): ?array
    {
        if (($link = self::clean($url, $site)) !== null) {
            return $link + ['shown' => true];
        }
        if (preg_match('#^(?:https?://)?(?:www\.|mobile\.|m\.)?(?:x|twitter)\.com/([A-Za-z0-9_]{1,15})/status(?:es)?/\d{1,25}(?:[/?\#].*)?$#i', trim($url), $post)
            && ($account = self::clean('https://x.com/'.$post[1])) !== null) {
            return $account + ['shown' => true];
        }
        $page = self::page($url);

        return $page !== null ? $page + ['shown' => false] : null;
    }

    /**
     * A page of another website, written one way (no tracking, no fragment). Never a social media
     * site (only its accounts are kept), an address with a port, a password or a bare IP, or a
     * name that is not on the internet (localhost, .local). Whether it may be read is checked
     * again on every read (SiteReader::download).
     *
     * @return array{network: string, url: string}|null
     */
    public static function page(string $url): ?array
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || strlen($url) > 500 || preg_match('/[\s<>"\'\\\\`]/u', $url)) {
            return null;
        }
        if (! preg_match('#^https?://#i', $url)) {
            if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) || ! preg_match('#^[\p{L}\p{N}][\p{L}\p{N}.-]*\.(?:[\p{L}]{2,}|xn--[a-z0-9-]+)(?:[/?]|$)#iu', $url)) {
                return null;
            }
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        $host = mb_strtolower(rtrim($parts['host'], '.'));
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) || ! preg_match('/^[\p{L}\p{N}][\p{L}\p{N}.-]*\.(?:[\p{L}]{2,}|xn--[a-z0-9-]+)$/u', $host)
            || preg_match('/(^|\.)(localhost|local|internal|lan|home|corp|test|invalid|example)$/', $host)) {
            return null;
        }
        foreach (array_keys(self::DOMAINS) as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return null;
            }
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        $kept = array_filter($query, fn ($v, $k) => is_string($v) && ! preg_match('/^(utm_|fbclid$|gclid$|mc_|igshid$)/i', (string) $k), ARRAY_FILTER_USE_BOTH);
        $segments = array_values(array_filter(explode('/', (string) ($parts['path'] ?? '')), fn ($s) => $s !== ''));
        $path = implode('/', array_map(fn (string $s): string => rawurlencode(rawurldecode($s)), $segments));
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https')) === 'http' ? 'http' : 'https';

        return ['network' => 'page', 'url' => $scheme.'://'.$host.($path !== '' ? '/'.$path : '').($kept !== [] ? '?'.http_build_query($kept) : '')];
    }

    /**
     * The account page a link points to, written one way (https, the main host, no tracking), or
     * null when it is not an account (a post, a video, a share button, another part of the site).
     *
     * @param  list<string>  $segments  the path's parts, as written
     * @param  array<array-key, mixed>  $query
     */
    private static function account(string $network, string $domain, string $sub, array $segments, array $query): ?string
    {
        $count = count($segments);
        $first = $segments[0] ?? '';
        $lower = strtolower(rawurldecode($first));
        $second = $segments[1] ?? '';
        $name = '/^[\p{L}\p{N}._\-]{2,100}$/u';
        $plain = fn (string $s): string => rawurldecode($s);
        $part = fn (string $s): string => rawurlencode(rawurldecode($s));
        $not = fn (string $s): bool => in_array(strtolower($plain($s)), self::NOT_ACCOUNTS[$network] ?? [], true);

        switch ($network) {
            case 'facebook':
                if ($domain === 'fb.me') {
                    return $sub === '' && $count === 1 && preg_match('/^[A-Za-z0-9._\-]{2,100}$/', $first) ? 'https://fb.me/'.$first : null;
                }
                if (! in_array($sub, ['', 'www', 'm', 'mobile', 'web', 'touch', 'mbasic'], true) && ! preg_match('/^[a-z]{2}-[a-z]{2}$/', $sub)) {
                    return null;
                }
                if ($lower === 'profile.php') {
                    $id = (string) ($query['id'] ?? '');

                    return $count === 1 && preg_match('/^\d{5,25}$/', $id) ? 'https://www.facebook.com/profile.php?id='.$id : null;
                }
                if ($lower === 'groups' && $count === 2 && preg_match($name, $plain($second))) {
                    return 'https://www.facebook.com/groups/'.$part($second);
                }
                if (in_array($lower, ['pages', 'people'], true) && $count === 3 && preg_match($name, $plain($second)) && preg_match('/^\d{5,25}$/', $segments[2])) {
                    return 'https://www.facebook.com/'.$lower.'/'.$part($second).'/'.$segments[2];
                }
                $tab = $count === 2 && in_array(strtolower($second), ['about', 'reviews', 'community', 'about_contact_and_basic_info'], true);
                if (($count === 1 || $tab) && ! $not($first) && preg_match($name, $plain($first))) {
                    return 'https://www.facebook.com/'.$part($first);
                }

                return null;

            case 'instagram':
                $ok = $domain === 'instagr.am' ? $sub === '' : in_array($sub, ['', 'www', 'm'], true);
                $tab = $count === 2 && in_array(strtolower($second), ['reels', 'tagged', 'guides'], true);

                return $ok && ($count === 1 || $tab) && preg_match('/^[A-Za-z0-9._]{1,30}$/', $first) && ! $not($first) ? 'https://www.instagram.com/'.$first : null;

            case 'x':
                $handle = ltrim($plain($first), '@');

                return in_array($sub, ['', 'www', 'mobile', 'm'], true) && $count === 1 && preg_match('/^[A-Za-z0-9_]{1,15}$/', $handle) && ! $not($handle)
                    ? 'https://x.com/'.$handle : null;

            case 'linkedin':
                if (! in_array($sub, ['', 'www', 'm'], true) && ! preg_match('/^[a-z]{2}$/', $sub)) {
                    return null;
                }
                $tab = $count === 3 && in_array(strtolower($segments[2]), ['about', 'mycompany', 'posts'], true);

                return in_array($lower, ['company', 'in', 'school', 'showcase'], true) && ($count === 2 || $tab) && preg_match($name, $plain($second))
                    ? 'https://www.linkedin.com/'.$lower.'/'.$part($second) : null;

            case 'youtube':
                if (! in_array($sub, ['', 'www', 'm'], true)) {
                    return null;
                }
                $tabs = ['featured', 'videos', 'about', 'streams', 'shorts', 'playlists', 'community'];
                if (str_starts_with($plain($first), '@')) {
                    $tab = $count === 2 && in_array(strtolower($second), $tabs, true);

                    return ($count === 1 || $tab) && preg_match('/^@[\p{L}\p{N}._\-]{2,100}$/u', $plain($first)) ? 'https://www.youtube.com/@'.$part(substr($plain($first), 1)) : null;
                }
                if (in_array($lower, ['channel', 'c', 'user'], true)) {
                    $tab = $count === 3 && in_array(strtolower($segments[2]), $tabs, true);

                    return ($count === 2 || $tab) && preg_match($name, $plain($second)) ? 'https://www.youtube.com/'.$lower.'/'.$part($second) : null;
                }

                return $count === 1 && ! $not($first) && preg_match('/^[A-Za-z0-9._\-]{2,100}$/', $first) ? 'https://www.youtube.com/'.$first : null;

            case 'tiktok':
                return in_array($sub, ['', 'www', 'm'], true) && $count === 1 && preg_match('/^@[A-Za-z0-9._]{2,24}$/', $plain($first))
                    ? 'https://www.tiktok.com/'.$plain($first) : null;

            case 'snapchat':
                if (! in_array($sub, ['', 'www'], true)) {
                    return null;
                }
                if ($lower === 'add' && $count === 2 && preg_match('/^[A-Za-z0-9._\-]{2,30}$/', $second)) {
                    return 'https://www.snapchat.com/add/'.$second;
                }
                // The app's "share my profile" link.
                if ($lower === 't' && $count === 2 && preg_match('/^[A-Za-z0-9_\-]{4,32}$/', $second)) {
                    return 'https://www.snapchat.com/t/'.$second;
                }

                return $count === 1 && preg_match('/^@[A-Za-z0-9._\-]{2,30}$/', $plain($first)) ? 'https://www.snapchat.com/'.$plain($first) : null;

            case 'telegram':
                if (! in_array($sub, ['', 'www'], true)) {
                    return null;
                }
                if ($lower === 'joinchat' && $count === 2 && preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $second)) {
                    return 'https://t.me/joinchat/'.$second;
                }
                $handle = $lower === 's' && $count === 2 ? $second : ($count === 1 ? $first : '');
                $invite = preg_match('/^\+[A-Za-z0-9_\-]{8,64}$/', $plain($handle));

                return $handle !== '' && ($invite || (preg_match('/^[A-Za-z0-9_]{4,64}$/', $handle) && ! $not($handle))) ? 'https://t.me/'.$plain($handle) : null;

            case 'whatsapp':
                if ($domain === 'wa.me') {
                    if (! in_array($sub, ['', 'www'], true)) {
                        return null;
                    }
                    if ($count === 1 && preg_match('/^\+?\d{7,15}$/', $plain($first))) {
                        return 'https://wa.me/'.ltrim($plain($first), '+');
                    }

                    return $lower === 'message' && $count === 2 && preg_match('/^[A-Za-z0-9]{6,32}$/', $second) ? 'https://wa.me/message/'.$second : null;
                }
                // A "chat with us" button: api.whatsapp.com/send?phone=966…, written as the short link.
                $phone = preg_replace('/\D/', '', (string) ($query['phone'] ?? '')) ?? '';
                if (in_array($sub, ['api', 'web', 'www', ''], true) && $lower === 'send' && $count === 1 && strlen($phone) >= 7 && strlen($phone) <= 15) {
                    return 'https://wa.me/'.$phone;
                }
                if ($sub === 'chat' && $count === 1 && preg_match('/^[A-Za-z0-9]{10,40}$/', $first)) {
                    return 'https://chat.whatsapp.com/'.$first;
                }

                return in_array($sub, ['', 'www'], true) && $lower === 'channel' && $count === 2 && preg_match('/^[A-Za-z0-9]{10,40}$/', $second)
                    ? 'https://whatsapp.com/channel/'.$second : null;

            case 'threads':
                return in_array($sub, ['', 'www'], true) && $count === 1 && preg_match('/^@[A-Za-z0-9._]{1,30}$/', $plain($first))
                    ? 'https://www.'.$domain.'/'.$plain($first) : null;
        }

        return null;
    }

    /**
     * A page of the business's own website (its home page, its booking or registration page), with
     * the tracking taken off. Null for any other website.
     *
     * @param  array<string, mixed>  $parts
     * @param  list<string>  $segments
     * @param  array<array-key, mixed>  $query
     * @return array{network: string, url: string}|null
     */
    private static function websitePage(array $parts, string $host, array $segments, array $query, ?string $site): ?array
    {
        $site = $site !== null ? SiteReader::normalizeUrl($site) : null;
        $own = $site !== null ? strtolower(rtrim((string) parse_url($site, PHP_URL_HOST), '.')) : '';
        $bare = fn (string $h): string => (string) preg_replace('/^www\./', '', $h);
        if ($own === '' || $bare($host) !== $bare($own) || (isset($parts['port']) && $parts['port'] !== parse_url($site, PHP_URL_PORT))) {
            return null;
        }
        $kept = array_filter($query, fn ($v, $k) => is_string($v) && ! preg_match('/^(utm_|fbclid$|gclid$|mc_|ref$|igshid$)/i', (string) $k), ARRAY_FILTER_USE_BOTH);
        $path = implode('/', array_map(fn (string $s): string => rawurlencode(rawurldecode($s)), $segments));
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https')) === 'http' && ! str_starts_with((string) $site, 'https:') ? 'http' : 'https';

        return ['network' => 'website', 'url' => $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($path !== '' ? '/'.$path : '').($kept !== [] ? '?'.http_build_query($kept) : '')];
    }

    /**
     * The accounts a page links to, each with how often: the icons in its header and footer, and
     * the accounts its structured data names as its own ("sameAs"), which count twice.
     *
     * @return array<string, array{network: string, url: string, seen: int}> keyed by the link in lower case
     */
    public static function inHtml(string $html, string $base): array
    {
        $found = [];
        $add = function (string $href, int $weight) use (&$found, $base): void {
            $absolute = SiteReader::absolute(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $base);
            $link = $absolute !== null ? self::clean($absolute) : null;
            if ($link === null) {
                return;
            }
            $key = strtolower($link['url']);
            $found[$key] ??= $link + ['seen' => 0];
            $found[$key]['seen'] += $weight;
        };
        if (preg_match_all('/<a\b[^>]*?\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $html, $anchors, PREG_SET_ORDER)) {
            foreach ($anchors as $a) {
                $add(($a[1] ?? '') !== '' ? $a[1] : ((($a[2] ?? '') !== '') ? $a[2] : ($a[3] ?? '')), 1);
            }
        }
        if (preg_match_all('/"sameAs"\s*:\s*(\[[^\]]*\]|"[^"]*")/i', $html, $same)) {
            foreach ($same[1] as $list) {
                preg_match_all('#https?:(?:\\\\?/){2}[^"\s,\]]+#i', $list, $urls);
                foreach ($urls[0] as $url) {
                    $add(str_replace('\/', '/', $url), 2);
                }
            }
        }

        return $found;
    }

    /**
     * One account on each network, the one the site links to most (a footer icon is on every page,
     * an article's link is on one), listed in the usual order.
     *
     * @param  array<string, array{network: string, url: string, seen: int}>  $found
     * @return list<array{network: string, url: string}>
     */
    public static function pick(array $found): array
    {
        $best = [];
        foreach ($found as $link) {
            $network = $link['network'];
            if (! isset($best[$network]) || $link['seen'] > $best[$network]['seen']) {
                $best[$network] = $link;
            }
        }
        $order = array_flip(array_keys(self::NAMES));
        uksort($best, fn (string $a, string $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

        return array_slice(array_values(array_map(fn (array $l): array => ['network' => $l['network'], 'url' => $l['url']], $best)), 0, self::MAX);
    }

    /**
     * The agent's links as kept, each checked again (a link kept under older rules, or a page of a
     * website the agent no longer has, is left out).
     *
     * @return list<array{network: string, url: string}>
     */
    public static function of(Agent $agent): array
    {
        $out = [];
        foreach ((array) $agent->setting('links', []) as $link) {
            $url = is_array($link) ? (string) ($link['url'] ?? '') : (is_string($link) ? $link : '');
            $clean = $url !== '' ? self::clean($url, $agent->website_url) : null;
            if ($clean !== null) {
                $out[strtolower($clean['url'])] ??= $clean;
            }
        }

        return array_slice(array_values($out), 0, self::MAX);
    }

    /**
     * Every link the agent keeps, in the owner's order: those written in the chat (shown), and
     * the pages of other websites it only reads.
     *
     * @return list<array{network: string, url: string, shown: bool}>
     */
    public static function all(Agent $agent): array
    {
        $out = [];
        foreach ((array) $agent->setting('links', []) as $link) {
            $url = is_array($link) ? (string) ($link['url'] ?? '') : (is_string($link) ? $link : '');
            $kept = $url !== '' ? self::accept($url, $agent->website_url) : null;
            if ($kept !== null) {
                $out[strtolower($kept['url'])] ??= $kept;
            }
        }

        return array_slice(array_values($out), 0, self::MAX);
    }

    /**
     * Links added to the ones already there, without twins, at most MAX.
     *
     * @param  list<array{network: string, url: string}>  $links
     * @param  list<array{network: string, url: string}>  $more
     * @return array{0: list<array{network: string, url: string}>, 1: int} the links, and how many were added
     */
    public static function merge(array $links, array $more): array
    {
        $out = [];
        foreach ($links as $link) {
            $out[strtolower($link['url'])] ??= $link;
        }
        $before = count($out);
        foreach ($more as $link) {
            if (count($out) >= self::MAX) {
                break;
            }
            $out[strtolower($link['url'])] ??= $link;
        }

        return [array_values($out), count($out) - $before];
    }

    /**
     * The website was read: the accounts found on it, and the website itself, become the agent's
     * links, unless the owner has set them in Knowledge (theirs are kept as they are).
     *
     * @param  list<array{network: string, url: string}>  $found
     */
    public static function keepFound(Agent $agent, array $found): void
    {
        $settings = (array) ($agent->settings ?? []);
        $settings['links_found_at'] = now()->toIso8601String();
        if (empty($settings['links_edited'])) {
            $site = $agent->website_url ? self::clean($agent->website_url, $agent->website_url) : null;
            $settings['links'] = self::merge($found, $site !== null ? [$site] : [])[0];
        }
        $agent->settings = $settings;
    }

    /**
     * What a caller may ask a page by, in a few words (show_links' page): the words of each kind of
     * page in English and Arabic, so "the prices page", "صفحة الأسعار" and "pricing" find the same one.
     */
    private const PAGE_WORDS = [
        'pricing' => ['pricing', 'price', 'prices', 'plan', 'plans', 'package', 'packages', 'cost', 'fees', 'سعر', 'أسعار', 'الأسعار', 'اسعار', 'باقة', 'باقات', 'الباقات', 'رسوم', 'تسعير'],
        'contact' => ['contact', 'support', 'help', 'call', 'تواصل', 'اتصال', 'اتصل', 'الدعم', 'دعم'],
        'register' => ['register', 'signup', 'sign', 'account', 'join', 'تسجيل', 'سجل', 'حساب', 'اشتراك', 'اشترك'],
        'agents' => ['agent', 'agents', 'وكيل', 'وكلاء', 'الوكلاء'],
        'samples' => ['sample', 'samples', 'voices', 'نماذج', 'عينات', 'أصوات', 'الأصوات'],
        'demo' => ['demo', 'try', 'trial', 'تجربة', 'جرب', 'تجريب'],
        'dialects' => ['dialect', 'dialects', 'لهجة', 'لهجات', 'اللهجات'],
        'accents' => ['accent', 'accents', 'لكنة', 'لكنات'],
        'booking' => ['book', 'booking', 'appointment', 'reserve', 'حجز', 'احجز', 'موعد', 'مواعيد'],
        'about' => ['about', 'من نحن'],
    ];

    /** Listen's own pages, for its own agent (HouseAgent): the kind of page and its address. */
    private const HOUSE_PAGES = ['pricing' => '/pricing', 'contact' => '/contact-us', 'register' => '/register', 'agents' => '/voice-agents',
        'samples' => '/voice-samples', 'demo' => '/demo', 'dialects' => '/arabic-dialects', 'accents' => '/english-accents'];

    /**
     * One page of the business's own website a caller asked for (show_links' page: "pricing",
     * "صفحة الحجز"): Listen's own pages for its own agent, else the best match among the pages read
     * from the website (their titles and addresses). Null when none fits: never a guess.
     *
     * @return array{network: string, url: string}|null
     */
    public static function askedPage(Agent $agent, string $asked): ?array
    {
        $asked = mb_strtolower(trim($asked));
        if ($asked === '') {
            return null;
        }
        $kinds = [];
        foreach (self::PAGE_WORDS as $kind => $words) {
            foreach ($words as $w) {
                // A word on its own, or with the Arabic "the" before it (الرسوم, بالأسعار).
                if (preg_match('/(?<![\p{L}])(?:ال|وال|بال|لل)?'.preg_quote($w, '/').'/u', $asked)) {
                    $kinds[$kind] = true;
                }
            }
        }
        if ($agent->setting('house')) {
            foreach (array_keys($kinds) as $kind) {
                if (isset(self::HOUSE_PAGES[$kind])) {
                    return ['network' => 'website', 'url' => url('/'.($agent->language === 'en' ? 'en' : 'ar').self::HOUSE_PAGES[$kind])];
                }
            }

            return null;
        }
        // The words to look for: what was asked, and every word of the kinds of page it names.
        $look = preg_split('/[\s,،\-\/]+/u', $asked, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (array_keys($kinds) as $kind) {
            $look = array_merge($look, self::PAGE_WORDS[$kind]);
        }
        $look = array_values(array_unique(array_filter($look, fn ($w) => mb_strlen($w) >= 3)));
        $best = null;
        $score = 0;
        foreach ($agent->sources()->where('kind', \App\Models\AgentSource::PAGE)->whereNotNull('url')->get(['title', 'url']) as $page) {
            $clean = self::clean((string) $page->url, $agent->website_url);
            if ($clean === null || $clean['network'] !== 'website') {
                continue;
            }
            $hay = mb_strtolower(urldecode((string) $page->url).' '.$page->title);
            $s = 0;
            foreach ($look as $w) {
                if (str_contains($hay, $w)) {
                    $s++;
                }
            }
            // A shorter address wins a tie: the section's own page rather than one inside it.
            if ($s > $score || ($s === $score && $s > 0 && $best && strlen($clean['url']) < strlen($best['url']))) {
                [$best, $score] = [$clean, $s];
            }
        }

        return $score > 0 ? $best : null;
    }

    /**
     * What a person sees for each link: where it goes, written short ("instagram.com/sos_ksa").
     *
     * @param  list<array{network: string, url: string}>  $links
     * @return list<array{network: string, url: string, label: string}>
     */
    public static function shown(array $links): array
    {
        return array_map(fn (array $l): array => ['network' => $l['network'], 'url' => $l['url'], 'label' => self::label($l['url'])], $links);
    }

    public static function label(string $url): string
    {
        $parts = parse_url($url) ?: [];
        $host = (string) preg_replace('/^(www|m|mobile)\./', '', strtolower((string) ($parts['host'] ?? '')));
        $path = rtrim(rawurldecode((string) ($parts['path'] ?? '')), '/');
        $query = str_ends_with($path, 'profile.php') && isset($parts['query']) ? '?'.$parts['query'] : '';

        return Str::limit($host.$path.$query, 64, '…');
    }

    /** How the agent knows a link: "Instagram: @sos_ksa", "WhatsApp: +966501234567", "Website: example.org". */
    public static function describe(array $link): string
    {
        $parts = parse_url((string) $link['url']) ?: [];
        $segments = array_values(array_filter(explode('/', rawurldecode((string) ($parts['path'] ?? ''))), fn ($s) => $s !== ''));
        $last = (string) (end($segments) ?: '');
        $handle = match ($link['network']) {
            'instagram', 'x' => '@'.ltrim($last, '@'),
            'youtube' => str_starts_with($segments[0] ?? '', '@') ? $segments[0] : $last,
            'telegram' => ($segments[0] ?? '') === 'joinchat' || str_starts_with($last, '+') ? 'an invite to its channel' : '@'.$last,
            'whatsapp' => ($parts['host'] ?? '') === 'wa.me' && preg_match('/^\d{7,15}$/', $last) ? '+'.$last
                : (str_contains((string) $link['url'], '/channel/') ? 'its channel' : (str_contains((string) $link['url'], 'chat.whatsapp.com') ? 'its group' : 'a chat with it')),
            'website' => self::label((string) $link['url']),
            'facebook' => $last === 'profile.php' ? 'its page' : (in_array($segments[0] ?? '', ['pages', 'people', 'groups'], true) ? (string) ($segments[1] ?? $last) : $last),
            default => $last !== '' ? $last : self::label((string) $link['url']),
        };

        return (self::NAMES[$link['network']] ?? ucfirst((string) $link['network'])).': '.$handle;
    }
}
