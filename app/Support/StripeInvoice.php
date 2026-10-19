<?php

namespace App\Support;

/**
 * Reads a Stripe invoice as webhooks send it, in the API versions from before
 * and after the subscription moved under parent.subscription_details.
 */
final class StripeInvoice
{
    /** The subscription the invoice was made for, or '' (a one-off invoice). */
    public static function subscriptionId(object $invoice): string
    {
        $fromTopLevel = self::id(self::value($invoice, 'subscription'));
        if ($fromTopLevel !== '') {
            return $fromTopLevel;
        }

        $parent = self::value($invoice, 'parent');
        $details = is_object($parent) ? self::value($parent, 'subscription_details') : null;

        return is_object($details) ? self::id(self::value($details, 'subscription')) : '';
    }

    /**
     * Why Stripe made the invoice: subscription_cycle (a renewal, or the charge
     * when a trial ends), subscription_update (a plan change), subscription_create
     * (a new subscription's first payment), manual, ... ('' when not sent).
     */
    public static function billingReason(object $invoice): string
    {
        return strtolower(trim((string) self::value($invoice, 'billing_reason')));
    }

    private static function value(object $object, string $key): mixed
    {
        if ($object instanceof \ArrayAccess && $object->offsetExists($key)) {
            return $object->offsetGet($key);
        }

        return $object->{$key} ?? null;
    }

    private static function id(mixed $value): string
    {
        if (is_object($value)) {
            return trim((string) ($value->id ?? ''));
        }

        return trim((string) $value);
    }
}
