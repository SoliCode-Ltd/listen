<?php

namespace App\Models;

use App\Support\AudioUrl;
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
        'show_in_hero',
        'show_in_showcase',
        'category',
        'duration_seconds',
        'metadata',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'is_active' => 'boolean',
        'show_in_hero' => 'boolean',
        'show_in_showcase' => 'boolean',
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
        // Homepage showcase, cached per page language and per gallery limit (1 to 60).
        foreach (['ar', 'en'] as $language) {
            foreach (range(1, 60) as $limit) {
                Cache::forget("home_showcase:{$language}:{$limit}");
            }
        }
        // Sign-in and register sample rows (AuthSamples), cached per row limit.
        foreach (range(1, 12) as $limit) {
            Cache::forget("auth_sample_rows:ar:{$limit}");
            Cache::forget("auth_sample_rows:en:{$limit}");
        }
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

    public function scopeShowInHero($query)
    {
        return $query->where('show_in_hero', true);
    }

    public function scopeShowInShowcase($query)
    {
        return $query->where('show_in_showcase', true);
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
        return AudioUrl::fromPath($this->audio_path);
    }
}
