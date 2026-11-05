<?php

namespace App\Services\Agents;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Reads what is new behind the links in an agent's Knowledge → Social media links, so the agent
 * knows the business's latest news: the newest posts of an account anyone can see without
 * signing in (X, Telegram, YouTube), a website's news feed, or a page.
 *
 * Every request goes through SiteReader::download (public addresses only, each hop checked and
 * pinned). What comes back is plain text with its date, never a link: a post is a fact to answer
 * from. Sites that show nothing without signing in are never asked, and a chat number has
 * nothing to read.
 */
class LinkReader
{
    /** An account's newest posts. */
    public const POSTS = 'posts';

    /** A page, or the news feed it links to. */
    public const PAGE = 'page';

    /** The agent's own website: read with the website, so only its news feed is read here. */
    public const SITE = 'site';

    /** Shows nothing without signing in. */
    public const CLOSED = 'closed';

    /** A chat number or group: nothing to read. */
    public const NONE = 'none';

    /** Posts kept from one link: the newest, from the last DAYS days. */
    public const MAX_POSTS = 10;

    public const DAYS = 180;

    public const POST_CHARS = 400;

    /** What one link gives the agent at most. */
    public const MAX_CHARS = 2_000;

    /** A page with fewer words than this has its pictures read too (PictureReader). */
    private const PICTURES_BELOW = 1_500;

    private const SIGN_IN = ['instagram', 'facebook', 'tiktok', 'linkedin', 'snapchat', 'threads'];

    public function __construct(private SiteReader $site, private PictureReader $pictures) {}

    /**
     * How a link is read: POSTS, PAGE, SITE, CLOSED or NONE. $website is the agent's website:
     * its home page is read with it.
     *
     * @param  array{network: string, url: string}  $link
     */
    public static function way(array $link, ?string $website = null): string
    {
        $path = trim((string) parse_url($link['url'], PHP_URL_PATH), '/');

        return match (true) {
            in_array($link['network'], self::SIGN_IN, true) => self::CLOSED,
            $link['network'] === 'whatsapp' => self::NONE,
            // A private channel's invite: nothing shows without joining.
            $link['network'] === 'telegram' => preg_match('#^(joinchat/|\+)#', $path) ? self::CLOSED : self::POSTS,
            in_array($link['network'], ['x', 'youtube'], true) => self::POSTS,
            $link['network'] === 'website' && self::isHome($link['url'], $website) => self::SITE,
            default => self::PAGE,
        };
    }

    /**
     * What is new behind a link. kind: posts (dated items, newest first), page (its text), or
     * none (the website's home page has no news feed: the website itself is read with the agent).
     *
     * @param  array{network: string, url: string}  $link
     * @return array{ok: bool, kind: string, title: string, items: list<array{at: string, about: bool, text: string}>, text: string, error: ?string}
     */
    public function read(array $link, ?string $website = null): array
    {
        try {
            return match (self::way($link, $website)) {
                self::POSTS => match ($link['network']) {
                    'x' => $this->x($link['url']),
                    'telegram' => $this->telegram($link['url']),
                    default => $this->youtube($link['url']),
                },
                self::PAGE => $this->page($link['url'], false),
                self::SITE => $this->page($link['url'], true),
                default => self::failed('closed'),
            };
        } catch (\Throwable $e) {
            // A page that changed its shape is no reason to stop reading the others.
            Log::warning('Agent link not read', ['url' => $link['url'], 'error' => $e->getMessage()]);

            return self::failed('error');
        }
    }

    /** An X account's newest posts, from the timeline X shows on other websites. */
    private function x(string $url): array
    {
        $handle = ltrim(rawurldecode(trim((string) parse_url($url, PHP_URL_PATH), '/')), '@');
        if (! preg_match('/^[A-Za-z0-9_]{1,15}$/', $handle)) {
            return self::failed('invalid');
        }
        $page = $this->site->download('https://syndication.twitter.com/srv/timeline-profile/screen-name/'.$handle, ['html'], 'text/html', 'en', 3_000_000);
        if ($page === null) {
            return self::failed('unreachable');
        }
        $body = $page['body'];
        $tag = strpos($body, 'id="__NEXT_DATA__"');
        $json = $tag === false ? '' : self::between(substr($body, $tag), '>', '</script>');
        $entries = data_get(json_decode($json, true), 'props.pageProps.timeline.entries');
        if (! is_array($entries)) {
            return self::failed('changed');
        }
        $items = [];
        $name = '';
        foreach ($entries as $entry) {
            $post = data_get($entry, 'content.tweet');
            if (! is_array($post)) {
                continue;
            }
            // Its own posts only: never another account's post it shared, or its replies to others.
            $author = (string) data_get($post, 'user.screen_name', '');
            $to = (string) ($post['in_reply_to_screen_name'] ?? '');
            if (isset($post['retweeted_status']) || strcasecmp($author, $handle) !== 0 || ($to !== '' && strcasecmp($to, $handle) !== 0)) {
                continue;
            }
            $name = $name !== '' ? $name : (string) data_get($post, 'user.name', '');
            $items[] = ['at' => (string) ($post['created_at'] ?? ''), 'text' => (string) ($post['full_text'] ?? $post['text'] ?? '')];
        }

        return self::posts($items, $name !== '' ? $name : '@'.$handle);
    }

    /** A public Telegram channel's newest posts, from its web preview. */
    private function telegram(string $url): array
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $name = str_starts_with($path, 's/') ? substr($path, 2) : $path;
        if (! preg_match('/^[A-Za-z0-9_]{4,64}$/', $name)) {
            return self::failed('closed');
        }
        $page = $this->site->download('https://t.me/s/'.$name, ['html'], 'text/html', 'en', 2_000_000);
        if ($page === null) {
            return self::failed('unreachable');
        }
        $html = $page['body'];
        $items = [];
        foreach (array_slice(explode('<div class="tgme_widget_message_wrap', $html), 1) as $block) {
            // Posts forwarded from other channels are theirs, not the business's.
            if (str_contains($block, 'tgme_widget_message_forwarded_from')) {
                continue;
            }
            if (preg_match('/<time[^>]*\bdatetime="([^"]+)"/', $block, $at) && preg_match('/<div class="tgme_widget_message_text[^"]*"[^>]*>(.*?)<\/div>/s', $block, $text)) {
                $items[] = ['at' => $at[1], 'text' => $text[1]];
            }
        }
        if ($items === [] && ! str_contains($html, 'tgme_channel_info')) {
            // A person or a private group: there is no public channel to read.
            return self::failed('closed');
        }

        return self::posts($items, self::meta($html, 'og:title') ?: '@'.$name);
    }

    /**
     * A YouTube channel's newest videos: their titles, and how long ago each came out (YouTube
     * gives no exact date there, so the date is "around").
     */
    private function youtube(string $url): array
    {
        $page = $this->site->download(rtrim($url, '/').'/videos', ['html'], 'text/html', 'en', 4_000_000);
        if ($page === null) {
            return self::failed('unreachable');
        }
        $data = json_decode(self::between($page['body'], 'var ytInitialData = ', ';</script>'), true);
        if (! is_array($data)) {
            return self::failed('changed');
        }
        $items = [];
        $walk = function (mixed $node) use (&$walk, &$items): void {
            if (! is_array($node) || count($items) >= 40) {
                return;
            }
            if (is_array($node['lockupViewModel'] ?? null)) {
                $meta = (array) data_get($node['lockupViewModel'], 'metadata.lockupMetadataViewModel', []);
                $parts = [];
                foreach ((array) data_get($meta, 'metadata.contentMetadataViewModel.metadataRows', []) as $row) {
                    foreach ((array) ($row['metadataParts'] ?? []) as $part) {
                        $parts[] = (string) data_get($part, 'text.content', '');
                    }
                }
                $items[] = ['at' => self::ago(implode(' ', $parts)), 'about' => true, 'text' => (string) data_get($meta, 'title.content', '')];

                return;
            }
            if (is_array($node['videoRenderer'] ?? null)) {
                $video = $node['videoRenderer'];
                $title = implode('', array_map(fn ($run) => (string) ($run['text'] ?? ''), (array) data_get($video, 'title.runs', [])));
                $items[] = ['at' => self::ago((string) data_get($video, 'publishedTimeText.simpleText', '')), 'about' => true, 'text' => $title];

                return;
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk(data_get($data, 'contents', $data));

        return self::posts($items, (string) data_get($data, 'metadata.channelMetadataRenderer.title', ''));
    }

    /**
     * A page: the news feed it links to when it has one (dated posts), else its words. $feedOnly:
     * the agent's own home page, whose words the agent already has from its website.
     */
    private function page(string $url, bool $feedOnly): array
    {
        $got = $this->site->download($url, ['html', 'xml'], 'text/html,application/xhtml+xml,application/rss+xml,application/atom+xml;q=0.9');
        if ($got === null) {
            return self::failed('unreachable');
        }
        if (str_contains($got['type'], 'xml') && ! str_contains($got['type'], 'xhtml')) {
            return $this->feed($got['body'], '');
        }
        $html = $got['body'];
        $title = SiteReader::title($html);
        $feed = self::feedLink($html, $got['url']);
        if ($feed !== null) {
            $doc = $this->site->download($feed, ['xml'], 'application/rss+xml,application/atom+xml,application/xml;q=0.9');
            $read = $doc !== null ? $this->feed($doc['body'], $title) : null;
            if ($read !== null && $read['ok'] && $read['items'] !== []) {
                return $read;
            }
        }
        if ($feedOnly) {
            return ['ok' => true, 'kind' => 'none', 'title' => $title, 'items' => [], 'text' => '', 'error' => null];
        }
        $text = SiteReader::textOf($html);
        // A page whose words are in a picture (a board of directors, a poster of fees): the
        // picture's words come first, then the page's own.
        $pictures = mb_strlen($text) < self::PICTURES_BELOW ? SiteReader::pictures($html, $got['url']) : [];
        $seen = $pictures !== [] ? $this->pictures->words($pictures, $title, null) : '';
        $text = Str::limit(trim($seen."\n".$text), self::MAX_CHARS, '');
        if (mb_strlen($text) < 40) {
            return self::failed('empty');
        }

        return ['ok' => true, 'kind' => 'page', 'title' => $title, 'items' => [], 'text' => $text, 'error' => null];
    }

    /** A news feed's newest items (RSS or Atom). External entities are never loaded. */
    private function feed(string $xml, string $title): array
    {
        $before = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($before);
        }
        if ($doc === false) {
            return self::failed('changed');
        }
        $items = [];
        if (isset($doc->channel)) {
            foreach ($doc->channel->item as $item) {
                $items[] = ['at' => (string) $item->pubDate, 'text' => self::joined((string) $item->title, (string) $item->description)];
            }
            $title = (string) $doc->channel->title ?: $title;
        } else {
            foreach ($doc->entry as $entry) {
                $items[] = ['at' => (string) ($entry->published ?: $entry->updated), 'text' => self::joined((string) $entry->title, (string) ($entry->summary ?: $entry->content))];
            }
            $title = (string) $doc->title ?: $title;
        }

        return self::posts($items, $title);
    }

    /**
     * The newest posts, cleaned: dated, from the last DAYS days, newest first, each short, all of
     * them at most MAX_CHARS.
     *
     * @param  list<array{at: ?string, text: string, about?: bool}>  $raw
     */
    private static function posts(array $raw, string $title): array
    {
        $oldest = now()->subDays(self::DAYS);
        $items = [];
        foreach ($raw as $post) {
            $at = self::when((string) ($post['at'] ?? ''));
            $text = self::plain($post['text']);
            if ($at === null || $text === '' || $at->lt($oldest) || $at->gt(now()->addDay())) {
                continue;
            }
            $items[strtolower($text)] ??= ['at' => $at->utc()->toIso8601String(), 'about' => (bool) ($post['about'] ?? false), 'text' => $text];
        }
        $items = array_values($items);
        usort($items, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));
        $kept = [];
        $left = self::MAX_CHARS;
        foreach ($items as $item) {
            if (count($kept) >= self::MAX_POSTS || $left < 80) {
                break;
            }
            $item['text'] = Str::limit($item['text'], min(self::POST_CHARS, $left), '…');
            $left -= mb_strlen($item['text']) + 20;
            $kept[] = $item;
        }

        return ['ok' => true, 'kind' => 'posts', 'title' => Str::limit(self::plain($title), 120, ''), 'items' => $kept, 'text' => '', 'error' => null];
    }

    /** @return array{ok: false, kind: string, title: string, items: array{}, text: string, error: string} */
    private static function failed(string $error): array
    {
        return ['ok' => false, 'kind' => '', 'title' => '', 'items' => [], 'text' => '', 'error' => $error];
    }

    /** Words only: no tags, no links (a post's link is never read aloud), one line. */
    public static function plain(string $html): string
    {
        $html = preg_replace('/<br\s*\/?>|<\/p>/i', ' ', $html) ?? '';
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('#\b(?:https?://|www\.|pic\.twitter\.com/)\S+#iu', ' ', $text) ?? '';

        return trim((string) preg_replace('/[\s\x{00A0}\x{200B}]+/u', ' ', $text));
    }

    private static function joined(string $title, string $body): string
    {
        $title = self::plain($title);
        $body = self::plain($body);
        if ($body === '' || str_starts_with($body, $title)) {
            return $body !== '' ? $body : $title;
        }

        return $title !== '' ? $title.': '.$body : $body;
    }

    /** A date as feeds write it; a weekday that does not match the date is ignored. */
    private static function when(string $value): ?Carbon
    {
        $value = trim((string) preg_replace('/^\s*(mon|tue|wed|thu|fri|sat|sun)[a-z]*\.?,?\s+/i', '', trim($value)));
        if ($value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** "3 weeks ago", "1mo ago", "Streamed 2 days ago" → around then. */
    private static function ago(string $text): ?string
    {
        if (! preg_match('/(\d+)\s*(years?|yrs?|y|months?|mos?|weeks?|wks?|w|days?|d|hours?|hrs?|h|minutes?|mins?|m|seconds?|secs?|s)\s+ago\b/i', $text, $m)) {
            return null;
        }
        $n = (int) $m[1];
        $unit = strtolower($m[2]);
        $at = match (true) {
            str_starts_with($unit, 'y') => now()->subYears($n),
            str_starts_with($unit, 'mo') => now()->subMonths($n),
            str_starts_with($unit, 'w') => now()->subWeeks($n),
            str_starts_with($unit, 'd') => now()->subDays($n),
            str_starts_with($unit, 'h') => now()->subHours($n),
            str_starts_with($unit, 'm') => now()->subMinutes($n),
            default => now()->subSeconds($n),
        };

        return $at->toIso8601String();
    }

    /** The page's own news feed (not its comments'), from <link rel="alternate">. */
    private static function feedLink(string $html, string $base): ?string
    {
        if (! preg_match_all('/<link\b[^>]*>/i', $html, $tags)) {
            return null;
        }
        foreach ($tags[0] as $tag) {
            if (! preg_match('/\brel\s*=\s*["\']?[^"\'>]*alternate/i', $tag) || ! preg_match('/\btype\s*=\s*["\']?application\/(rss|atom)\+xml/i', $tag)
                || ! preg_match('/\bhref\s*=\s*(?:"([^"]+)"|\'([^\']+)\')/i', $tag, $href)) {
                continue;
            }
            $url = SiteReader::absolute(html_entity_decode($href[1] !== '' ? $href[1] : ($href[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $base);
            if ($url !== null && ! preg_match('/comments?/i', $tag)) {
                return $url;
            }
        }

        return null;
    }

    /** The home page of the agent's website (the page it was set up with, or its root). */
    private static function isHome(string $url, ?string $website): bool
    {
        $site = $website !== null ? SiteReader::normalizeUrl($website) : null;
        if ($site === null) {
            return false;
        }
        $key = fn (string $u): string => preg_replace('/^www\./', '', strtolower((string) parse_url($u, PHP_URL_HOST)))
            .'/'.trim(strtolower(rawurldecode((string) parse_url($u, PHP_URL_PATH))), '/');
        $root = preg_replace('/^www\./', '', strtolower((string) parse_url($site, PHP_URL_HOST))).'/';

        return in_array($key($url), [$key($site), $root], true);
    }

    private static function meta(string $html, string $property): string
    {
        return preg_match('/<meta\s+property="'.preg_quote($property, '/').'"\s+content="([^"]*)"/i', $html, $m)
            ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    }

    private static function between(string $text, string $start, string $end): string
    {
        $from = strpos($text, $start);
        if ($from === false) {
            return '';
        }
        $from += strlen($start);
        $to = strpos($text, $end, $from);

        return $to === false ? '' : substr($text, $from, $to - $from);
    }
}
