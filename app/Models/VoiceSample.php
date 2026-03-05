<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class VoiceSample extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'title_ar',
        'description',
        'description_ar',
        'icon',
        'sample_text',
        'sample_text_ar',
        'audio_path',
        'language',
        'dialect',
        'country',
        'emotion',
        'gender',
        'voice_name',
        'provider',
        'sort_order',
        'is_featured',
        'is_active',
        'category',
        'duration_seconds',
        'metadata',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'duration_seconds' => 'integer',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => self::flushLandingCache());
        static::deleted(fn () => self::flushLandingCache());
    }

    public static function flushLandingCache(): void
    {
        Cache::forget('landing_voice_samples:ar');
        Cache::forget('landing_voice_samples:en');
    }

    /**
     * Scope: active samples only.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: featured samples only.
     */
    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    /**
     * Scope: by category.
     */
    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope: by dialect.
     */
    public function scopeDialect($query, string $dialect)
    {
        return $query->where('dialect', $dialect);
    }

    /**
     * Scope: ordered for display.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Get the public audio URL.
     */
    public function getAudioUrlAttribute(): ?string
    {
        return \App\Support\AudioUrl::fromPath($this->audio_path);
    }
}
