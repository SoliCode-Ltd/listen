<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * One request to reset a password from the sign-in page (App\Http\Controllers\PasswordResetController):
 * the address typed, the account it belongs to, the language of the page, whether the email went
 * out, and when the password was changed with its link. The admin reads these in Newsletter →
 * Password resets. The token itself is never kept here (Laravel keeps its hash in
 * password_reset_tokens).
 */
class PasswordResetRequest extends Model
{
    /** The email with the link went out. */
    public const SENT = 'sent';

    /** There is an account, but the email could not be sent. */
    public const FAILED = 'failed';

    /** No account has this address: nothing was sent. */
    public const NO_ACCOUNT = 'no_account';

    /** The account has no mailbox (it signed up with a phone number): nothing was sent. */
    public const NO_MAILBOX = 'no_mailbox';

    /** A link went to this account moments ago: no second email yet. */
    public const TOO_SOON = 'too_soon';

    protected $fillable = [
        'user_id', 'email', 'locale', 'status', 'ip', 'user_agent', 'sent_at', 'completed_at', 'completed_ip',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether the list can be kept yet: the reset works before its migration is run. */
    public static function ready(): bool
    {
        return once(fn (): bool => rescue(fn () => Schema::hasTable('password_reset_requests'), false, false));
    }

    /**
     * What came of the request, for the admin: the password was changed, the link is still
     * waiting, it ran out unused, or no link went out (and why).
     *
     * @return array{label: string, color: string}
     */
    public function outcome(): array
    {
        return match (true) {
            $this->completed_at !== null => ['label' => 'Password changed', 'color' => 'success'],
            $this->status === self::SENT && $this->linkExpired() => ['label' => 'Link ran out unused', 'color' => 'gray'],
            $this->status === self::SENT => ['label' => 'Email sent, waiting', 'color' => 'warning'],
            $this->status === self::FAILED => ['label' => 'Email could not be sent', 'color' => 'danger'],
            $this->status === self::NO_ACCOUNT => ['label' => 'No account with this email', 'color' => 'gray'],
            $this->status === self::NO_MAILBOX => ['label' => 'Account has no mailbox', 'color' => 'gray'],
            $this->status === self::TOO_SOON => ['label' => 'Asked again too soon', 'color' => 'gray'],
            default => ['label' => ucfirst(str_replace('_', ' ', (string) $this->status)), 'color' => 'gray'],
        };
    }

    public function linkExpired(): bool
    {
        $sent = $this->sent_at ?? $this->created_at;

        return $sent !== null && $sent->lt(now()->subMinutes(self::linkMinutes()));
    }

    /** How long a reset link works (config/auth.php), as the email and the pages say. */
    public static function linkMinutes(): int
    {
        return max(1, (int) config('auth.passwords.'.config('auth.defaults.passwords', 'users').'.expire', 60));
    }
}
