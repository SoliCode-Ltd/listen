<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses an address at a throwaway inbox service (config/disposable_email.php),
 * the usual way to open account after account for the free demo. A subdomain
 * of a listed domain counts too.
 */
class NotDisposableEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::isDisposable($value)) {
            $fail(__('ui.backend.auth_disposable_email'));
        }
    }

    public static function isDisposable(string $email): bool
    {
        if (! (bool) config('disposable_email.enabled', true)) {
            return false;
        }

        $domain = trim(strtolower(substr((string) strrchr(trim($email), '@'), 1)), " \t\n\r\0\x0B.");
        if ($domain === '') {
            return false;
        }

        $listed = array_flip(array_map(fn ($d) => strtolower(trim((string) $d)), (array) config('disposable_email.domains', [])));
        // The domain itself, then each domain it sits under: a.b.mailinator.com, b.mailinator.com, mailinator.com.
        $labels = explode('.', $domain);
        for ($i = 0; $i < count($labels) - 1; $i++) {
            if (isset($listed[implode('.', array_slice($labels, $i))])) {
                return true;
            }
        }

        return false;
    }
}
