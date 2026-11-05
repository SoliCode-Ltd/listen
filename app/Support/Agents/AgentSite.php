<?php

namespace App\Support\Agents;

use App\Jobs\ReadAgentWebsite;
use App\Models\Agent;
use App\Models\AgentSource;
use App\Services\Agents\PictureReader;
use App\Services\Agents\SiteReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every page of an agent's website, so it can answer whatever a caller asks about the business,
 * not only what the setup summary kept: the pages are read in the background (ReadAgentWebsite,
 * at most SiteReader::SITE_PAGES), each kept as an AgentSource of kind page, and looked up during
 * a call (AgentLookup, the look_up tool). A page whose words are in a picture (a board of
 * directors) gets the picture's words (PictureReader).
 *
 * Read when the agent is written from its website, once for an agent made before this (its page
 * opened, or agents:read-websites), when the owner asks, and again every EVERY_DAYS days for
 * live agents. A reading that finds nothing keeps the pages read before.
 */
final class AgentSite
{
    public const EVERY_DAYS = 7;

    /** Pictures read in one reading of a website, the pages with the fewest words first. */
    public const PICTURES = 8;

    /** A page with fewer words of its own than this has its pictures read. */
    private const PICTURES_BELOW = 600;

    /** The most one page keeps, with its pictures' words. */
    private const PAGE_MAX = 10_000;

    /** A reading that found nothing is tried again after this long. */
    private const FAILED_AGAIN_HOURS = 6;

    /** Has a website, and its pages were never read, or not for EVERY_DAYS days. */
    public static function isDue(Agent $agent): bool
    {
        if (! $agent->exists || ! $agent->website_url || ! AgentAccess::tablesReady()) {
            return false;
        }
        if (Cache::has(self::key('reading', $agent)) || Cache::has(self::key('failed', $agent))) {
            return false;
        }
        $last = $agent->sources()->where('kind', AgentSource::PAGE)->max('read_at');

        return $last === null || Carbon::parse($last)->lte(now()->subDays(self::EVERY_DAYS)->addHour());
    }

    /** The website read in the background when it is due (or now, when the owner asks). */
    public static function refresh(Agent $agent, bool $now = false): bool
    {
        if (! $agent->website_url || ! AgentAccess::tablesReady() || Cache::has(self::key('reading', $agent))) {
            return false;
        }
        if (! $now && ! self::isDue($agent)) {
            return false;
        }
        Cache::forget(self::key('failed', $agent));
        Cache::put(self::key('reading', $agent), true, now()->addMinutes(20));
        ReadAgentWebsite::dispatch($agent->id);

        return true;
    }

    /**
     * Reads the website and keeps its pages (the pages read before are replaced; kept when nothing
     * could be read). Returns how many pages were kept.
     */
    public static function read(Agent $agent, SiteReader $reader, PictureReader $pictures, ?float $until = null): int
    {
        $until ??= microtime(true) + 115;
        try {
            if (! $agent->website_url) {
                return 0;
            }
            $site = $reader->site($agent->website_url, $agent->language ?: null, min($until - 35, microtime(true) + 80));
            $pages = $site['pages'];
            if ($pages === []) {
                Cache::put(self::key('failed', $agent), true, now()->addHours(self::FAILED_AGAIN_HOURS));

                return 0;
            }

            $pages = self::withPictures($pages, $pictures, $agent->business_name, $until);

            $now = now();
            $rows = [];
            foreach ($pages as $page) {
                $text = Str::limit(trim($page['text']), self::PAGE_MAX, '');
                if ($text === '') {
                    continue;
                }
                $rows[] = [
                    'agent_id' => $agent->id, 'kind' => AgentSource::PAGE, 'title' => Str::limit($page['title'] !== '' ? $page['title'] : $page['url'], 195, ''),
                    'url' => Str::limit($page['url'], 500, ''), 'content' => $text, 'chars' => mb_strlen($text), 'status' => AgentSource::READY,
                    'read_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            if ($rows === []) {
                Cache::put(self::key('failed', $agent), true, now()->addHours(self::FAILED_AGAIN_HOURS));

                return 0;
            }
            DB::transaction(function () use ($agent, $rows): void {
                $agent->sources()->where('kind', AgentSource::PAGE)->delete();
                foreach (array_chunk($rows, 20) as $chunk) {
                    AgentSource::insert($chunk);
                }
            });

            return count($rows);
        } finally {
            Cache::forget(self::key('reading', $agent));
        }
    }

    /**
     * The pages whose words are in their pictures, the fewest words first, get the pictures' words
     * before their own (at most PICTURES pictures, two a page).
     *
     * @param  list<array{url: string, title: string, text: string, pictures: list<string>}>  $pages
     * @return list<array{url: string, title: string, text: string, pictures: list<string>}>
     */
    public static function withPictures(array $pages, PictureReader $pictures, ?string $business, float $until): array
    {
        $wanting = array_keys(array_filter($pages, fn (array $p): bool => $p['pictures'] !== [] && mb_strlen($p['text']) < self::PICTURES_BELOW));
        usort($wanting, fn (int $a, int $b): int => mb_strlen($pages[$a]['text']) <=> mb_strlen($pages[$b]['text']));
        $left = self::PICTURES;
        foreach ($wanting as $i) {
            if ($left <= 0 || microtime(true) > $until - 20) {
                break;
            }
            $take = min(2, $left, count($pages[$i]['pictures']));
            $left -= $take;
            $words = $pictures->words($pages[$i]['pictures'], $pages[$i]['title'], $business, $take, $until);
            if ($words !== '') {
                $pages[$i]['text'] = trim($words."\n".$pages[$i]['text']);
            }
        }

        return $pages;
    }

    /**
     * What Knowledge → Your website shows: reading, ready (how many pages, when), failed, or none
     * (never read yet).
     *
     * @return array{state: string, pages: int, text: string}
     */
    public static function state(Agent $agent): array
    {
        $site = $agent->website_url ? (string) (parse_url((string) $agent->website_url, PHP_URL_HOST) ?: $agent->website_url) : '';
        $site = preg_replace('/^www\./i', '', $site) ?? $site;
        if (! $agent->exists || $site === '' || ! AgentAccess::tablesReady()) {
            return ['state' => 'none', 'pages' => 0, 'text' => ''];
        }
        $pages = $agent->sources()->where('kind', AgentSource::PAGE)->count();
        if (Cache::has(self::key('reading', $agent))) {
            return ['state' => 'reading', 'pages' => $pages, 'text' => __('agents.site.reading')];
        }
        if ($pages > 0) {
            $at = Carbon::parse($agent->sources()->where('kind', AgentSource::PAGE)->max('read_at'));

            return ['state' => 'ready', 'pages' => $pages, 'text' => trans_choice('agents.site.ready', $pages, ['count' => $pages, 'site' => $site])
                .' '.__('agents.site.read_on', ['date' => $at->locale(app()->getLocale())->translatedFormat('j F Y')])];
        }
        if (Cache::has(self::key('failed', $agent))) {
            return ['state' => 'failed', 'pages' => 0, 'text' => __('agents.site.failed', ['site' => $site])];
        }

        return ['state' => 'none', 'pages' => 0, 'text' => __('agents.site.none', ['site' => $site])];
    }

    private static function key(string $what, Agent $agent): string
    {
        return 'agent-site-'.$what.':'.$agent->id;
    }
}
