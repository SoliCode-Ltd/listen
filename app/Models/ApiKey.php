<?php

namespace App\Models;

use App\Support\Api\ApiScopes;
use App\Support\CreditPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A key for the public developer API (/v1). The plain key is shown once, when
 * it is made; only its SHA-256 hash and last four characters are kept.
 */
class ApiKey extends Model
{
    /** Every key starts with this, so it is easy to spot (and to scan for when leaked). */
    public const PREFIX = 'lsk_live_';

    /** Active keys one member may hold at once. */
    public const MAX_ACTIVE_PER_USER = 10;

    /** What a member may change; the owner, the hash and the dates are set by the code only. */
    protected $fillable = [
        'name',
        'scopes',
        'monthly_credit_limit',
    ];

    protected $hidden = [
        'key_hash',
    ];

    protected $casts = [
        'scopes' => 'array',
        'monthly_credit_limit' => 'integer',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(ApiKeyUsage::class);
    }

    /**
     * @param  Builder<ApiKey>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public static function hash(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    /**
     * Makes a key for the member and returns it with its one-time plain value.
     *
     * @param  array<int, string>|null  $scopes  null for every permission
     * @return array{key: ApiKey, plain: string}
     */
    public static function issue(User $user, string $name, ?array $scopes = null, ?int $monthlyCreditLimit = null): array
    {
        $plain = self::PREFIX.Str::random(40);

        $key = new self([
            'name' => trim($name),
            'scopes' => $scopes === null ? null : ApiScopes::normalize($scopes),
            'monthly_credit_limit' => $monthlyCreditLimit !== null && $monthlyCreditLimit > 0 ? $monthlyCreditLimit : null,
        ]);
        $key->forceFill([
            'user_id' => $user->id,
            'key_hash' => self::hash($plain),
            'last_four' => substr($plain, -4),
        ])->save();

        return ['key' => $key, 'plain' => $plain];
    }

    /**
     * The key of an AI app the member connected with "Sign in with Listen"
     * (App\Mcp\ConnectedApps). It has no plain value: the app signs in with
     * its OAuth token, so it can never be sent as a key.
     *
     * @param  array<int, string>  $scopes
     */
    public static function forConnectedApp(User $user, string $name, string $oauthClientId, array $scopes): self
    {
        $key = new self([
            'name' => mb_substr(trim($name), 0, 80) ?: 'AI app',
            'scopes' => ApiScopes::normalize($scopes),
        ]);
        $key->forceFill([
            'user_id' => $user->id,
            'oauth_client_id' => $oauthClientId,
            'key_hash' => self::hash('oauth:'.$oauthClientId.':'.Str::random(40)),
            'last_four' => '',
        ])->save();

        return $key;
    }

    public function isConnectedApp(): bool
    {
        return $this->oauth_client_id !== null;
    }

    /**
     * Keys made in the dashboard, not connected apps.
     *
     * @param  Builder<ApiKey>  $query
     */
    public function scopeIssued(Builder $query): void
    {
        $query->whereNull('oauth_client_id');
    }

    /**
     * @param  Builder<ApiKey>  $query
     */
    public function scopeConnectedApps(Builder $query): void
    {
        $query->whereNotNull('oauth_client_id');
    }

    /**
     * The key for a plain value, when it exists and is not revoked.
     */
    public static function findActiveByPlain(string $plainKey): ?self
    {
        $plainKey = trim($plainKey);
        if (! str_starts_with($plainKey, self::PREFIX) || strlen($plainKey) > 200) {
            return null;
        }

        return self::query()->active()->where('key_hash', self::hash($plainKey))->first();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function revoke(): void
    {
        if (! $this->isRevoked()) {
            $this->forceFill(['revoked_at' => now()])->save();
        }
    }

    public function allows(string $scope): bool
    {
        return ApiScopes::allows($this->scopes, $scope);
    }

    /** What the member sees instead of the key: lsk_live_…abcd (a connected app has no key to show). */
    public function maskedKey(): string
    {
        return $this->isConnectedApp() ? 'Sign in with Listen' : self::PREFIX.'…'.$this->last_four;
    }

    /** Credits this key spent in a credit period (App\Support\CreditPeriod key; default: its member's current one). */
    public function creditsUsed(?string $periodYm = null): int
    {
        return (int) ApiKeyUsage::query()
            ->where('api_key_id', $this->id)
            ->where('period_ym', $periodYm ?? CreditPeriod::current((int) $this->user_id)['key'])
            ->value('credits');
    }

    /** Requests this key made in a credit period (default: its member's current one). */
    public function requestsMade(?string $periodYm = null): int
    {
        return (int) ApiKeyUsage::query()
            ->where('api_key_id', $this->id)
            ->where('period_ym', $periodYm ?? CreditPeriod::current((int) $this->user_id)['key'])
            ->value('requests');
    }

    /**
     * Notes when the key was last used, at most once a minute (whatever the
     * address), so busy keys and parallel requests do not write every time.
     */
    public function touchLastUsed(?string $ip): void
    {
        if ($this->last_used_at !== null && $this->last_used_at->gt(now()->subMinute())) {
            return;
        }
        if (! Cache::add('api-key-touched:'.$this->id, 1, 60)) {
            return;
        }

        self::query()->whereKey($this->id)->update([
            'last_used_at' => now(),
            'last_used_ip' => $ip !== null ? mb_substr($ip, 0, 45) : null,
        ]);
        $this->last_used_at = now();
        $this->last_used_ip = $ip;
    }
}
