<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use Billable, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'google_avatar',
        'api_key_hash',
        'api_key_last4',
        'api_key_created_at',
        'subscription_status_checked_at',
        'is_admin',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'api_key_hash',
    ];

    public function planSubscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function usageCounters(): HasMany
    {
        return $this->hasMany(UsageCounter::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'api_key_created_at' => 'datetime',
            'subscription_status_checked_at' => 'datetime',
            'is_admin' => 'boolean',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_admin === true;
    }

    public static function hashApiKey(string $plainTextKey): string
    {
        return hash('sha256', $plainTextKey);
    }

    public function issueApiKey(): string
    {
        $plain = 'lk_live_'.Str::random(48);
        $this->forceFill([
            'api_key_hash' => self::hashApiKey($plain),
            'api_key_last4' => substr($plain, -4),
            'api_key_created_at' => now(),
        ])->save();

        return $plain;
    }

    public function revokeApiKey(): void
    {
        $this->forceFill([
            'api_key_hash' => null,
            'api_key_last4' => null,
            'api_key_created_at' => null,
        ])->save();
    }
}

