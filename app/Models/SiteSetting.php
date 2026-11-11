<?php

namespace App\Models;

use App\Support\SeoHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SiteSetting extends Model
{
    protected $fillable = ['key', 'value', 'group'];

    // Kept in the cache for a setting that is not saved, so it is not looked up on every read.
    private const NOT_SAVED = '__site_setting_not_saved_7f3a9c__';

    /**
     * Get a setting value by key with optional default.
     *
     * Kept for 60 seconds, a setting that is not saved too: the cache keeps no
     * empty value, so without NOT_SAVED a page asked the database again for each
     * setting the admin never saved, every time it read one (21 on the homepage).
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        // Only what is saved is kept, never the caller's fallback: two callers can ask
        // with different fallbacks (the yearly discount: 20 for pricing, 0 elsewhere),
        // and the first one's must not become everyone's for a minute.
        $value = Cache::remember("site_setting.{$key}", 60, function () use ($key) {
            return static::where('key', $key)->value('value') ?? self::NOT_SAVED;
        });

        return $value === self::NOT_SAVED ? $default : $value;
    }

    /**
     * Set a setting value (create or update).
     */
    public static function set(string $key, mixed $value, string $group = 'general'): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => $group]
        );

        Cache::forget("site_setting.{$key}");
        if (in_array($group, SeoHelper::SITEMAP_SETTING_GROUPS, true)) {
            Cache::forget(SeoHelper::SITEMAP_CACHE);
        }
    }

    /**
     * Return the yearly billing discount multiplier (e.g. 0.8 for 20 % off).
     */
    public static function yearlyDiscountMultiplier(): float
    {
        $percent = (int) static::get('billing_yearly_discount_percentage', '20');
        $percent = max(0, min(99, $percent));

        return 1 - ($percent / 100);
    }

    /**
     * Return the yearly billing discount percentage (e.g. 20).
     */
    public static function yearlyDiscountPercent(): int
    {
        return max(0, min(99, (int) static::get('billing_yearly_discount_percentage', '20')));
    }

    /**
     * Get all settings in a group.
     */
    public static function getGroup(string $group): array
    {
        return Cache::remember("site_settings_group.{$group}", 60, function () use ($group) {
            return static::where('group', $group)
                ->pluck('value', 'key')
                ->toArray();
        });
    }

    /**
     * Flush cache for a group — including each individual key.
     */
    public static function flushGroupCache(string $group): void
    {
        // Clear all individual key caches for this group
        $keys = static::where('group', $group)->pluck('key');
        foreach ($keys as $key) {
            Cache::forget("site_setting.{$key}");
        }

        Cache::forget("site_settings_group.{$group}");
        if (in_array($group, SeoHelper::SITEMAP_SETTING_GROUPS, true)) {
            Cache::forget(SeoHelper::SITEMAP_CACHE);
        }
    }
}
