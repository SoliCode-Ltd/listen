<?php

namespace App\Services\Api;

use Illuminate\Contracts\Cache\Lock;

/**
 * The slots one API request holds while it runs (ApiConcurrency): released
 * together when it ends.
 */
final class ApiSlot
{
    /**
     * @param  array<int, Lock>  $locks
     */
    public function __construct(private readonly array $locks) {}

    public function release(): void
    {
        foreach ($this->locks as $lock) {
            try {
                $lock->release();
            } catch (\Throwable) {
                // An expired lock is already free.
            }
        }
    }
}
