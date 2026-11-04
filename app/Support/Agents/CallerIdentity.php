<?php

namespace App\Support\Agents;

use App\Models\AgentWidget;
use Illuminate\Support\Str;

/**
 * Who is calling, when the website the call button is on knows: its signed-in visitor's email and
 * name, signed by that website so a visitor cannot pretend to be someone else.
 *
 * The button's script tag carries data-caller-email, data-caller-name, data-caller-expires (a
 * Unix time) and data-caller-signature: HMAC-SHA256 of "email\nname\nexpires" with the button's
 * caller secret (secret()). Only the website's server knows that secret (the owner copies it from
 * the install panel, and Listen's WordPress plugin carries it in its PHP), so a signature that
 * checks out came from that website. Listen's own pages sign their signed-in member the same way.
 *
 * Kept with the call (AgentConversation::caller) and shown with it to the agent's owner. A
 * missing, altered or expired signature keeps nothing: the call goes on as an unknown caller.
 */
final class CallerIdentity
{
    /** How long a signature may be used at most (a page left open, a cached page). */
    public const MAX_SECONDS = 7 * 86400;

    /** How long the signatures Listen makes are good for (its own pages, the WordPress plugin's). */
    public const TTL = 12 * 3600;

    /** The secret a website signs its signed-in visitors with, for this button. Never shown to visitors. */
    public static function secret(string $publicKey): string
    {
        return hash_hmac('sha256', 'agent-caller|'.$publicKey, (string) config('app.key'));
    }

    public static function sign(string $publicKey, string $email, string $name, int $expires): string
    {
        return hash_hmac('sha256', $email."\n".$name."\n".$expires, self::secret($publicKey));
    }

    /**
     * The script tag's attributes for a signed-in visitor (Listen's own pages).
     *
     * @return array<string, string>
     */
    public static function attributes(string $publicKey, string $email, string $name): array
    {
        $email = trim($email);
        $name = Str::limit(trim($name), 120, '');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [];
        }
        $expires = time() + self::TTL;

        return [
            'data-caller-email' => $email,
            'data-caller-name' => $name,
            'data-caller-expires' => (string) $expires,
            'data-caller-signature' => self::sign($publicKey, $email, $name, $expires),
        ];
    }

    /**
     * The caller the call button sent, when the website's signature checks out.
     *
     * @return array{email: string, name: ?string}|null
     */
    public static function verify(AgentWidget $widget, mixed $in): ?array
    {
        if (! is_array($in)) {
            return null;
        }
        // Text only (a crafted request can send anything): what is not text is not a caller.
        $text = static fn (mixed $value): string => is_string($value) || is_int($value) ? (string) $value : '';
        $email = $text($in['email'] ?? null);
        $name = $text($in['name'] ?? null);
        $expires = $text($in['expires'] ?? null);
        $signature = strtolower($text($in['signature'] ?? null));
        if ($email === '' || strlen($email) > 254 || mb_strlen($name) > 120 || ! ctype_digit($expires)
            || ! preg_match('/\A[0-9a-f]{64}\z/', $signature) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        // Still good, and not made to last longer than a page should.
        $left = (int) $expires - time();
        if ($left <= 0 || $left > self::MAX_SECONDS) {
            return null;
        }
        if (! hash_equals(self::sign((string) $widget->public_key, $email, $name, (int) $expires), $signature)) {
            return null;
        }
        $name = trim(strip_tags($name));

        return ['email' => $email, 'name' => $name !== '' ? $name : null];
    }
}
