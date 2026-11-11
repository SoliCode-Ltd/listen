<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * The inbox an email address reaches, so that addresses which land in one
 * inbox count as one person for the free demo (App\Services\DemoLinkedAccounts).
 *
 * - Letter case never matters.
 * - Anything after a "+" in the part before the @ is a tag the inbox ignores
 *   (jane+demo2@outlook.com reaches jane@outlook.com).
 * - Gmail also ignores dots, and googlemail.com is gmail.com
 *   (j.a.n.e+x@googlemail.com reaches jane@gmail.com).
 *
 * Dots matter everywhere else (jo.smith@ and josmith@ at a company are two people).
 */
final class MailboxKey
{
    public const COLUMN = 'email_key';

    private const GMAIL_DOMAINS = ['gmail.com', 'googlemail.com'];

    public static function for(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));
        $at = strrpos($email, '@');
        if ($at === false || $at === 0 || $at === strlen($email) - 1) {
            return $email !== '' ? mb_substr($email, 0, 255) : null;
        }

        $local = substr($email, 0, $at);
        $domain = rtrim(substr($email, $at + 1), '.');

        $plus = strpos($local, '+');
        if ($plus !== false && $plus > 0) {
            $local = substr($local, 0, $plus);
        }

        if (in_array($domain, self::GMAIL_DOMAINS, true)) {
            $domain = 'gmail.com';
            $withoutDots = str_replace('.', '', $local);
            $local = $withoutDots !== '' ? $withoutDots : $local;
        }

        return mb_substr($local.'@'.$domain, 0, 255);
    }

    /** False until the migration that adds users.email_key has run: nothing reads or writes it before. */
    public static function available(): bool
    {
        return once(fn (): bool => rescue(fn () => Schema::hasColumn('users', self::COLUMN), false, false));
    }
}
