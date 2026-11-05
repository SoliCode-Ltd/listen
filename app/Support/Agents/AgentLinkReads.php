<?php

namespace App\Support\Agents;

use App\Jobs\ReadAgentLinks;
use App\Models\Agent;
use App\Models\AgentSource;
use App\Services\Agents\LinkReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What an agent knows from its links (Knowledge → Social media links), so it has the business's
 * latest news: every link that can be read is an AgentSource (kind link), read when it is added
 * and again every EVERY_HOURS hours (agents:read-links), and its newest posts or its page go into
 * the agent's instructions with their dates (AgentInstructions). A link that could not be read
 * this time keeps what was read before.
 */
final class AgentLinkReads
{
    public const EVERY_HOURS = 6;

    /** A link not read the last time (a site that was busy, X's limit for each server) is tried again sooner. */
    public const FAILED_AGAIN_MINUTES = 60;

    /** Reasons that do not pass in an hour (a page with no words, a private channel): read on the usual turn. */
    public const LASTING = ['empty', 'invalid', 'closed'];

    /** All the links' posts and pages in the agent's instructions, at most. */
    public const MAX_CHARS = 6_000;

    /**
     * The links that can be read, by their address.
     *
     * @return array<string, array{network: string, url: string, shown: bool}>
     */
    public static function readable(Agent $agent): array
    {
        $out = [];
        foreach (AgentLinks::all($agent) as $link) {
            if (in_array(LinkReader::way($link, $agent->website_url), [LinkReader::POSTS, LinkReader::PAGE, LinkReader::SITE], true)) {
                $out[$link['url']] = $link;
            }
        }

        return $out;
    }

    /**
     * A row for each link that can be read (a new one waits to be read), and none for a link that
     * was removed. True when a new link now waits to be read.
     */
    public static function prepare(Agent $agent): bool
    {
        if (! $agent->exists) {
            return false;
        }
        $want = self::readable($agent);
        $rows = $agent->sources()->where('kind', AgentSource::LINK)->get();
        foreach ($rows as $row) {
            if (! isset($want[(string) $row->url])) {
                $row->delete();
            }
        }
        $have = $rows->pluck('url')->map(fn ($url) => (string) $url)->all();
        $created = false;
        foreach ($want as $url => $link) {
            if (! in_array($url, $have, true)) {
                AgentSource::create(['agent_id' => $agent->id, 'kind' => AgentSource::LINK, 'title' => AgentLinks::label($url),
                    'url' => $url, 'status' => AgentSource::PENDING, 'chars' => 0]);
                $created = true;
            }
        }

        return $created;
    }

    /** After the links changed (or were found): the rows kept in step, and new links read in the background. */
    public static function refresh(Agent $agent): void
    {
        if (AgentAccess::tablesReady() && self::prepare($agent)) {
            ReadAgentLinks::dispatch($agent->id);
        }
    }

    /**
     * Reads the agent's links: all of them, or ($due) those that are due (isDue). Stops at $until
     * and says so (true: some are left for another run).
     */
    public static function read(Agent $agent, LinkReader $reader, bool $due = false, ?Carbon $until = null): bool
    {
        self::prepare($agent);
        $links = self::readable($agent);
        foreach ($agent->sources()->where('kind', AgentSource::LINK)->orderBy('id')->get() as $row) {
            $link = $links[(string) $row->url] ?? null;
            if ($link === null) {
                continue;
            }
            if ($due && ! self::isDue($row)) {
                continue;
            }
            if ($until !== null && now()->gte($until)) {
                return true;
            }
            $read = $reader->read($link, $agent->website_url);
            if ($read['ok']) {
                $content = ['kind' => $read['kind'], 'title' => $read['title'], 'items' => $read['items'], 'text' => $read['text']];
                $chars = $read['kind'] === 'posts' ? array_sum(array_map(fn (array $item): int => mb_strlen($item['text']), $read['items'])) : mb_strlen($read['text']);
                $row->forceFill(['status' => AgentSource::READY, 'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'chars' => $chars, 'error' => null, 'read_at' => now(), 'updated_at' => now()])->save();
            } elseif ($row->status === AgentSource::READY) {
                // Not read this time (the site was busy): what was read before stays, and it is tried again later.
                $row->forceFill(['error' => $read['error'], 'updated_at' => now()])->save();
            } else {
                $row->forceFill(['status' => AgentSource::FAILED, 'error' => $read['error'], 'updated_at' => now()])->save();
            }
        }

        return false;
    }

    /**
     * Whether a link should be read again: waiting, not read the last time and tried an hour ago,
     * or tried EVERY_HOURS hours ago (a few minutes early, so the hourly run never just misses it).
     */
    public static function isDue(AgentSource $row): bool
    {
        if ($row->status === AgentSource::PENDING || $row->updated_at === null) {
            return true;
        }
        $failed = ($row->status === AgentSource::FAILED || ($row->error !== null && $row->error !== ''))
            && ! in_array($row->error, self::LASTING, true);

        return $row->updated_at->lte($failed ? now()->subMinutes(self::FAILED_AGAIN_MINUTES - 10) : now()->subHours(self::EVERY_HOURS)->addMinutes(10));
    }

    /**
     * The links as the Knowledge card shows them: where each goes, whether it is written in the
     * chat (shown), and what the agent read from it (state: reading, posts, empty, page, site,
     * failed or closed; none for a chat number).
     *
     * @return list<array{network: string, url: string, label: string, shown: bool, read: ?array{state: string, text: string}}>
     */
    public static function rows(Agent $agent): array
    {
        $sources = $agent->exists && AgentAccess::tablesReady()
            ? $agent->sources()->where('kind', AgentSource::LINK)->get()->keyBy(fn (AgentSource $s) => (string) $s->url)
            : new Collection;
        $out = [];
        foreach (AgentLinks::all($agent) as $link) {
            $out[] = ['network' => $link['network'], 'url' => $link['url'], 'label' => AgentLinks::label($link['url']), 'shown' => $link['shown'],
                'read' => self::state($link, $sources->get($link['url']), $agent)];
        }

        return $out;
    }

    /** True while one of the links is still being read for the first time. */
    public static function reading(array $rows): bool
    {
        foreach ($rows as $row) {
            if (($row['read']['state'] ?? null) === 'reading') {
                return true;
            }
        }

        return false;
    }

    /** @return array{state: string, text: string}|null */
    private static function state(array $link, ?AgentSource $row, Agent $agent): ?array
    {
        $way = LinkReader::way($link, $agent->website_url);
        if ($way === LinkReader::NONE) {
            return null;
        }
        if ($way === LinkReader::CLOSED) {
            return ['state' => 'closed', 'text' => __('agents.links_read.closed')];
        }
        if ($row === null || in_array($row->status, [AgentSource::PENDING, AgentSource::READING], true)) {
            return ['state' => 'reading', 'text' => __('agents.links_read.reading')];
        }
        if ($row->status !== AgentSource::READY) {
            // A page with no words to read (drawn by its scripts) stays so: no "tried again" promise.
            return ['state' => 'failed', 'text' => __($row->error === 'empty' ? 'agents.links_read.empty_page' : 'agents.links_read.failed')];
        }
        $read = (array) json_decode((string) $row->content, true);
        $when = $row->read_at ? ' '.__('agents.links_read.updated', ['when' => $row->read_at->locale(app()->getLocale())->diffForHumans()]) : '';
        $items = (array) ($read['items'] ?? []);

        return match ($read['kind'] ?? '') {
            'posts' => $items !== []
                ? ['state' => 'posts', 'text' => trans_choice('agents.links_read.posts', count($items), ['count' => count($items), 'date' => self::day($items[0])]).$when]
                : ['state' => 'empty', 'text' => __('agents.links_read.empty', ['months' => intdiv(LinkReader::DAYS, 30)]).$when],
            'page' => ['state' => 'page', 'text' => __('agents.links_read.page').$when],
            // The website's home page without a news feed: it is read with the website ("Your website" says so).
            default => null,
        };
    }

    /** A post's date for the owner: "3 November", or "October 2026" when it is only "around". */
    private static function day(array $item): string
    {
        try {
            $at = Carbon::parse((string) ($item['at'] ?? ''))->locale(app()->getLocale());
        } catch (\Throwable) {
            return '';
        }
        if (! empty($item['about'])) {
            return $at->translatedFormat('F Y');
        }

        return $at->translatedFormat($at->isCurrentYear() ? 'j F' : 'j F Y');
    }

    /**
     * What the agent keeps in mind from its links, newest read first, at most MAX_CHARS: for each,
     * how it knows the link ("X (Twitter): @sos"), whether it is the business's own, when it was
     * read, and its posts (with their dates) or its page.
     *
     * @return list<array{name: string, own: bool, read_at: ?Carbon, kind: string, items: list<array{at: string, about: bool, text: string}>, text: string}>
     */
    public static function inMind(Agent $agent): array
    {
        if (! $agent->exists || ! AgentAccess::tablesReady()) {
            return [];
        }
        $links = self::readable($agent);
        if ($links === []) {
            return [];
        }
        $rows = $agent->sources()->where('kind', AgentSource::LINK)->where('status', AgentSource::READY)->orderBy('id')->get();
        $left = self::MAX_CHARS;
        $out = [];
        foreach ($rows as $row) {
            $link = $links[(string) $row->url] ?? null;
            $read = (array) json_decode((string) $row->content, true);
            $kind = (string) ($read['kind'] ?? '');
            if ($link === null || ! in_array($kind, ['posts', 'page'], true) || $left < 200) {
                continue;
            }
            $items = [];
            $text = '';
            if ($kind === 'posts') {
                foreach ((array) ($read['items'] ?? []) as $item) {
                    $line = (string) ($item['text'] ?? '');
                    if ($line === '' || mb_strlen($line) > $left) {
                        break;
                    }
                    $left -= mb_strlen($line) + 24;
                    $items[] = ['at' => (string) ($item['at'] ?? ''), 'about' => (bool) ($item['about'] ?? false), 'text' => $line];
                }
                if ($items === []) {
                    continue;
                }
            } else {
                $text = Str::limit((string) ($read['text'] ?? ''), min(LinkReader::MAX_CHARS, $left), '');
                if ($text === '') {
                    continue;
                }
                $left -= mb_strlen($text);
            }
            $own = $link['shown'];
            $out[] = ['name' => $own ? AgentLinks::describe($link) : 'a page the owner added ('.AgentLinks::label($link['url']).')',
                'own' => $own, 'read_at' => $row->read_at, 'kind' => $kind, 'items' => $items, 'text' => $text];
        }

        return $out;
    }
}
