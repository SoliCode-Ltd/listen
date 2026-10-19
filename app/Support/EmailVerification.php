<?php

namespace App\Support;

use App\Models\User;

/**
 * A working mailbox before a free allowance is spent. An account made with an
 * email and a password confirms its address with the link it is sent
 * (App\Services\Auth\EmailVerificationMailer); accounts made with Google or a
 * phone number are confirmed when they are made. Until then the account
 * cannot spend the free demo (the demo.verified middleware) or free credits in
 * the studio and the browser extension (App\Services\Billing\MemberUsageMeter):
 * the free plan's monthly credits, or a gift. Anyone who pays or has paid
 * (App\Support\PaidMember), pack holders included, never waits for it.
 *
 * Not Laravel's MustVerifyEmail on purpose: that contract would also change
 * the admin panel, the `verified` middleware and what happens at sign-up.
 */
final class EmailVerification
{
    /**
     * How long a link works. The texts say ":days days" (in Arabic "أيام",
     * which is right for 3 to 10).
     */
    public const LINK_DAYS = 3;

    public static function isVerified(?User $user): bool
    {
        return $user !== null && ($user->email_verified_at !== null || $user->phone_verified_at !== null);
    }

    /**
     * Whether the account must confirm its email before it spends a free allowance.
     */
    public static function requiredForFreeUse(?User $user): bool
    {
        return $user !== null && ! self::isVerified($user) && ! PaidMember::is($user);
    }
}
