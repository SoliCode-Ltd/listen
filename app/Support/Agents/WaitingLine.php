<?php

namespace App\Support\Agents;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * The callers waiting for a line on one member's agents: every call the member's plan takes at
 * once is on, so they wait while the call button tries again every few seconds. The first to wait
 * gets the next free line: a new caller never goes ahead of someone already waiting. A caller who
 * stops trying (they closed the page) drops out after a few seconds. Kept in the cache only:
 * nothing here outlives a wait.
 */
class WaitingLine
{
    /** A waiting caller not heard from for this long has gone. */
    public const STALE_SECONDS = 16;

    /** How often the call button tries again while it waits. */
    public const RETRY_SECONDS = 5;

    public function __construct(private int $userId) {}

    /** The caller's place in a line, from the caller id the call button sends. */
    public static function waiter(?string $visitor): ?string
    {
        $visitor = (string) $visitor;

        return $visitor === '' ? null : hash('sha256', 'line|'.$visitor);
    }

    /** Callers waiting before this one: all who wait, for a caller who is not in the line. */
    public function ahead(?string $waiter): int
    {
        $line = $this->fresh();
        if ($waiter === null || ! isset($line[$waiter])) {
            return count($line);
        }

        return (int) array_search($waiter, array_keys($line), true);
    }

    /**
     * The caller joins the line, or says they are still in it: their place (1 is next) and
     * whether they had just joined.
     *
     * @return array{position: int, joined: bool}
     */
    public function join(string $waiter, int $agentId): array
    {
        return $this->locked(function () use ($waiter, $agentId): array {
            $line = $this->fresh();
            $joined = ! isset($line[$waiter]);
            $line[$waiter] = ['since' => $line[$waiter]['since'] ?? (float) now()->format('U.u'), 'seen' => now()->getTimestamp(), 'agent' => $agentId];
            Cache::put($this->key(), $line, now()->addMinutes(10));

            return ['position' => (int) array_search($waiter, array_keys($this->ordered($line)), true) + 1, 'joined' => $joined];
        });
    }

    /** Out of the line (through to the agent, or left a number): whether they were in it. */
    public function leave(string $waiter): bool
    {
        return $this->locked(function () use ($waiter): bool {
            $line = (array) Cache::get($this->key(), []);
            if (! isset($line[$waiter])) {
                return false;
            }
            unset($line[$waiter]);
            $line === [] ? Cache::forget($this->key()) : Cache::put($this->key(), $line, now()->addMinutes(10));

            return true;
        });
    }

    private function key(): string
    {
        return 'agent-line:'.$this->userId;
    }

    /** @return array<string, array{since: float, seen: int, agent: int}> those still waiting, the first first */
    private function fresh(): array
    {
        $now = now()->getTimestamp();

        return $this->ordered(array_filter((array) Cache::get($this->key(), []),
            fn ($w): bool => is_array($w) && (int) ($w['seen'] ?? 0) >= $now - self::STALE_SECONDS));
    }

    /**
     * @param  array<string, array{since: float, seen: int, agent: int}>  $line
     * @return array<string, array{since: float, seen: int, agent: int}>
     */
    private function ordered(array $line): array
    {
        uksort($line, fn (string $a, string $b): int => [(float) $line[$a]['since'], $a] <=> [(float) $line[$b]['since'], $b]);

        return $line;
    }

    /** One change at a time; a store that cannot lock (or a lock not got in time) goes on without. */
    private function locked(callable $change): mixed
    {
        try {
            $lock = Cache::lock('agent-line-lock:'.$this->userId, 5);
        } catch (\BadMethodCallException) {
            return $change();
        }
        try {
            return $lock->block(2, $change);
        } catch (LockTimeoutException) {
            return $change();
        }
    }
}
