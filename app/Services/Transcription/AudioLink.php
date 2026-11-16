<?php

namespace App\Services\Transcription;

use App\Services\Agents\SiteReader;
use Illuminate\Support\Facades\Http;

/**
 * Audio from a link a member gives (the page, the API, MCP), fetched the
 * safe way the agents read websites (SiteReader): only a public web address,
 * every hop checked and pinned to the address that was checked (no reaching
 * into this server's own network), at most four redirects; written straight
 * to a file and stopped as soon as it passes the largest upload, so a large
 * file never sits in memory; and only audio or video (or a file the server
 * calls "octet-stream", which ffmpeg then checks).
 */
final class AudioLink
{
    /** @var (callable(string): ?string)|null for tests: the address a host name has */
    public static $resolver = null;

    /**
     * The file at $url saved to $to. Returns its file name (from the link), or throws TranscriptionRefused.
     *
     * @throws TranscriptionRefused
     */
    public function fetch(string $url, string $to, int $maxBytes): string
    {
        $url = SiteReader::normalizeUrl($url) ?? '';
        if ($url === '') {
            throw self::refused();
        }
        for ($hop = 0; $hop < 5; $hop++) {
            $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
            $address = self::$resolver !== null ? (self::$resolver)($host) : SiteReader::publicAddress($host);
            if ($address === null) {
                throw self::refused();
            }
            $received = 0;
            try {
                $response = Http::withHeaders(['User-Agent' => 'ListenSpeechToText/1.0 (+https://listenai.studio)', 'Accept' => 'audio/*,video/*;q=0.9,*/*;q=0.5'])
                    ->withOptions(['sink' => $to, 'progress' => function ($total, $done) use ($maxBytes): void {
                        if ($total > $maxBytes || $done > $maxBytes) {
                            throw new \OverflowException('too big');
                        }
                    }] + array_diff_key(SiteReader::options($url, $address), ['stream' => true]))
                    ->timeout(240)->connectTimeout(8)->get($url);
            } catch (\Throwable $e) {
                @unlink($to);
                if ($e instanceof \OverflowException || $e->getPrevious() instanceof \OverflowException) {
                    throw new TranscriptionRefused('too_big', __('transcribe.refused.too_big', ['mb' => intdiv($maxBytes, 1024 * 1024)]), 413);
                }
                throw self::refused();
            }
            if ($response->redirect()) {
                @unlink($to);
                $url = SiteReader::absolute((string) $response->header('Location'), $url) ?? '';
                if ($url === '' || SiteReader::normalizeUrl($url) === null) {
                    throw self::refused();
                }

                continue;
            }
            $type = strtolower((string) $response->header('Content-Type'));
            clearstatcache(true, $to);
            $received = is_file($to) ? (int) filesize($to) : 0;
            if (! $response->successful() || $received === 0 || ! (str_starts_with($type, 'audio/') || str_starts_with($type, 'video/')
                || str_contains($type, 'octet-stream') || str_contains($type, 'ogg') || $type === '')) {
                @unlink($to);
                throw self::refused();
            }
            if ($received > $maxBytes) {
                @unlink($to);
                throw new TranscriptionRefused('too_big', __('transcribe.refused.too_big', ['mb' => intdiv($maxBytes, 1024 * 1024)]), 413);
            }

            return self::nameOf($url);
        }

        throw self::refused();
    }

    /** A readable name for a transcript from its link: the file's name, or the site's. */
    public static function nameOf(string $url): string
    {
        $name = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));

        return mb_substr($name !== '' && $name !== '/' ? $name : (string) parse_url($url, PHP_URL_HOST), 0, 200);
    }

    private static function refused(): TranscriptionRefused
    {
        return new TranscriptionRefused('bad_link', __('transcribe.refused.bad_link'));
    }
}
