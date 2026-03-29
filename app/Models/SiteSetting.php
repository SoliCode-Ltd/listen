<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SiteSetting extends Model
{
    protected $fillable = ['key', 'value', 'group'];

    /**
     * Get a setting value by key with optional default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("site_setting.{$key}", 60, function () use ($key, $default) {
            $setting = static::where('key', $key)->first();
            return $setting?->value ?? $default;
        });
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
    }
}
