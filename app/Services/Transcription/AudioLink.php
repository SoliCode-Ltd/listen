<?php

namespace App\Services\Transcription;

use App\Services\Agents\SiteReader;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

/**
 * Audio from a link a member gives (the page, the API, MCP), fetched the
 * safe way the agents read websites (SiteReader): only a public web address,
 * every hop checked and pinned to the address that was checked (no reaching
 * into this server's own network), at most four redirects; written straight
 * to a file and stopped as soon as it passes the largest upload, so a large
 * file never sits in memory; and only audio or video (or a file the server
 * calls "octet-stream", which ffmpeg then checks), told by the answer's
 * headers before its body is read.
 *
 * It runs while the member's request waits, so it is kept short: one deadline
 * for the whole download (redirects included), a speed below which it stops,
 * one link at a time for each member, and a few at once for the whole site.
 */
final class AudioLink
{
    /** The whole download, every redirect included: a file that takes longer is refused. */
    public const SECONDS = 120;

    /** The slowest a download may run, in bytes a second, for SLOW_SECONDS in a row. */
    public const SLOWEST = 32 * 1024;

    public const SLOW_SECONDS = 15;

    /** Links downloaded at the same time on this server: each keeps a web worker while it runs. */
    public const AT_ONCE = 3;

    /** @var (callable(string): ?string)|null for tests: the address a host name has */
    public static $resolver = null;

    /**
     * The file at $url saved to $to. Returns its file name (from the link), or throws TranscriptionRefused.
     * With $memberId, the member may have only one link downloading at a time.
     *
     * @throws TranscriptionRefused
     */
    public function fetch(string $url, string $to, int $maxBytes, ?int $memberId = null): string
    {
        $mine = $memberId !== null ? Cache::lock('transcribe-link:'.$memberId, self::SECONDS + 60) : null;
        if ($mine !== null && ! $mine->get()) {
            throw new TranscriptionRefused('busy', __('transcribe.refused.link_busy'), 429);
        }
        try {
            $slot = self::slot();
            if ($slot === null) {
                throw new TranscriptionRefused('site_busy', __('transcribe.refused.links_busy'), 503);
            }
            try {
                return $this->download($url, $to, $maxBytes);
            } finally {
                $slot->release();
            }
        } finally {
            $mine?->release();
        }
    }

    /**
     * @throws TranscriptionRefused
     */
    private function download(string $url, string $to, int $maxBytes): string
    {
        $url = SiteReader::normalizeUrl($url) ?? '';
        if ($url === '') {
            throw self::refused();
        }
        $deadline = microtime(true) + self::SECONDS;
        for ($hop = 0; $hop < 5; $hop++) {
            $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
            $address = self::$resolver !== null ? (self::$resolver)($host) : SiteReader::publicAddress($host);
            if ($address === null) {
                throw self::refused();
            }
            $left = (int) floor($deadline - microtime(true));
            if ($left < 1) {
                throw self::slow();
            }
            $options = array_diff_key(SiteReader::options($url, $address), ['stream' => true]);
            if (defined('CURLOPT_LOW_SPEED_LIMIT')) {
                $options['curl'] = (array) ($options['curl'] ?? []) + [CURLOPT_LOW_SPEED_LIMIT => self::SLOWEST, CURLOPT_LOW_SPEED_TIME => self::SLOW_SECONDS];
            }
            try {
                $response = Http::withHeaders(['User-Agent' => 'ListenSpeechToText/1.0 (+https://listenai.studio)', 'Accept' => 'audio/*,video/*;q=0.9,*/*;q=0.5'])
                    ->withOptions(['sink' => $to, 'progress' => function ($total, $done) use ($maxBytes): void {
                        if ($total > $maxBytes || $done > $maxBytes) {
                            throw new \OverflowException('too big');
                        }
                    }, 'on_headers' => function (ResponseInterface $answer) use ($maxBytes): void {
                        // A web page, an error or a file too large is told by its headers: its body is never read.
                        $status = $answer->getStatusCode();
                        if ($status >= 300 && $status < 400) {
                            return;
                        }
                        if ($status < 200 || $status >= 300 || ! self::isMedia($answer->getHeaderLine('Content-Type'))) {
                            throw new \UnexpectedValueException('not audio or video');
                        }
                        if ((int) $answer->getHeaderLine('Content-Length') > $maxBytes) {
                            throw new \OverflowException('too big');
                        }
                    }] + $options)
                    ->timeout(min($left, self::SECONDS))->connectTimeout(min(8, $left))->get($url);
            } catch (\Throwable $e) {
                @unlink($to);
                if (self::caused($e, \OverflowException::class)) {
                    throw self::tooBig($maxBytes);
                }
                if (! self::caused($e, \UnexpectedValueException::class) && self::tooSlow($e)) {
                    throw self::slow();
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
            clearstatcache(true, $to);
            $received = is_file($to) ? (int) filesize($to) : 0;
            if (! $response->successful() || $received === 0 || ! self::isMedia((string) $response->header('Content-Type'))) {
                @unlink($to);
                throw self::refused();
            }
            if ($received > $maxBytes) {
                @unlink($to);
                throw self::tooBig($maxBytes);
            }

            return self::nameOf($url);
        }

        throw self::refused();
    }

    /** Audio or video, or a file its server does not name (ffmpeg checks what it holds). */
    private static function isMedia(string $type): bool
    {
        $type = strtolower(trim($type));

        return $type === '' || str_starts_with($type, 'audio/') || str_starts_with($type, 'video/')
            || str_contains($type, 'octet-stream') || str_contains($type, 'ogg');
    }

    /** A free place among the AT_ONCE downloads this server runs at a time, or null when all are taken. */
    private static function slot(): ?Lock
    {
        for ($i = 1; $i <= self::AT_ONCE; $i++) {
            $lock = Cache::lock('transcribe-link-slot:'.$i, self::SECONDS + 60);
            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }

    /** Whether $class is the error, or what caused it (an error thrown inside a download's callback comes back wrapped). */
    private static function caused(\Throwable $e, string $class): bool
    {
        for ($at = $e; $at !== null; $at = $at->getPrevious()) {
            if ($at instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /** The download ran out of time or ran too slowly (not a server that could not be reached at all). */
    private static function tooSlow(\Throwable $e): bool
    {
        for ($at = $e; $at !== null; $at = $at->getPrevious()) {
            $message = $at->getMessage();
            if (str_contains($message, 'Operation too slow') || str_contains($message, 'Operation timed out')) {
                return true;
            }
        }

        return false;
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

    private static function slow(): TranscriptionRefused
    {
        return new TranscriptionRefused('bad_link', __('transcribe.refused.slow_link'));
    }

    private static function tooBig(int $maxBytes): TranscriptionRefused
    {
        return new TranscriptionRefused('too_big', __('transcribe.refused.too_big', ['mb' => intdiv($maxBytes, 1024 * 1024)]), 413);
    }
}
