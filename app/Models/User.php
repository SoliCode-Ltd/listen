<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\PlanAccessService;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use Billable, HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::created(static function (self $user): void {
            try {
                app(PlanAccessService::class)->ensureFreePlanForUser($user);
            } catch (\Throwable $e) {
                Log::warning('Failed to auto-assign free plan for new user.', [
                    'user_id' => $user->id,
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone_number',
        'phone_verified_at',
        'firebase_uid',
        'auth_provider',
        'google_id',
        'google_avatar',
        'registration_ip',
        'last_login_ip',
        'registration_device_id',
        'last_seen_device_id',
        'registration_request_signature',
        'last_seen_request_signature',
        'api_key_hash',
        'api_key_last4',
        'api_key_created_at',
        'subscription_status_checked_at',
        'is_admin',
        'is_blocked',
        'multi_account_whitelisted',
        'blocked_at',
        'blocked_reason',
        'blocked_reason_ar',
        'blocked_reason_en',
        'blocked_by',
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

    public function demoVoiceGenerations(): HasMany
    {
        return $this->hasMany(DemoVoiceGeneration::class);
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
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'api_key_created_at' => 'datetime',
            'subscription_status_checked_at' => 'datetime',
            'is_admin' => 'boolean',
            'is_blocked' => 'boolean',
            'blocked_at' => 'datetime',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_admin === true && ! $this->isBlocked();
    }

    public function blockedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'blocked_by');
    }

    public function isBlocked(): bool
    {
        return (bool) $this->is_blocked;
    }

    public function isPhoneUser(): bool
    {
        return $this->auth_provider === 'phone';
    }

    /**
     * Display-friendly contact info: phone number for phone users, email otherwise.
     */
    public function displayContact(): string
    {
        if ($this->isPhoneUser() && filled($this->phone_number)) {
            return $this->phone_number;
        }

        return $this->email;
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
