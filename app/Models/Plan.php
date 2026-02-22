<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'price_cents',
        'monthly_minutes',
        'monthly_chars',
        'monthly_requests',
        'max_chars_per_request',
        'allow_overage',
        'overage_price_per_million_cents',
        'commercial_use_allowed',
        'processing_priority',
        'voice_tier',
        'features',
        'is_active',
    ];

    protected $casts = [
        'features' => 'array',
        'allow_overage' => 'boolean',
        'commercial_use_allowed' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
