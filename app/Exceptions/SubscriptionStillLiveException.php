<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Stripe still charges the member for a subscription, so starting another one
 * would charge them twice.
 */
class SubscriptionStillLiveException extends RuntimeException
{
    /**
     * @param  list<string>  $subscriptionIds
     */
    public function __construct(public readonly array $subscriptionIds)
    {
        parent::__construct('Stripe still charges for: '.implode(', ', $subscriptionIds));
    }
}
