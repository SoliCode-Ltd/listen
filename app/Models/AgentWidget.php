<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Where an agent can be reached: the call button a website loads with one line
 * of code (public_key), and its link page at /talk/{slug}, which also works
 * as a QR code. The look lives here, served with the button, so changing it
 * never means pasting the code again.
 *
 * allowed_origins lists the websites that may load the button; empty means
 * any website (the link page always works).
 */
class AgentWidget extends Model
{
    protected $fillable = [
        'agent_id',
        'public_key',
        'slug',
        'allowed_origins',
        'appearance',
        'link_enabled',
        'enabled',
        'installed_via',
        'last_seen_origin',
        'last_seen_at',
    ];

    protected $casts = [
        'allowed_origins' => 'array',
        'appearance' => 'array',
        'link_enabled' => 'boolean',
        'enabled' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public const DEFAULT_APPEARANCE = [
        'position' => 'end',
        'style' => 'orb',
        'theme' => 'auto',
        'label' => null,
    ];

    public static function createFor(Agent $agent): self
    {
        return self::create([
            'agent_id' => $agent->id,
            'public_key' => 'lw_'.Str::random(28),
            'slug' => self::freshSlug($agent),
            'appearance' => self::DEFAULT_APPEARANCE,
            'allowed_origins' => [],
            'link_enabled' => true,
            'enabled' => true,
        ]);
    }

    /** A readable link: the business name, then a few random letters so it cannot be guessed. */
    public static function freshSlug(Agent $agent): string
    {
        $base = Str::slug(Str::limit($agent->business_name ?: $agent->name, 40, ''));
        $base = $base !== '' ? $base : 'agent';
        do {
            $slug = $base.'-'.Str::lower(Str::random(5));
        } while (self::where('slug', $slug)->exists());

        return $slug;
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function look(string $key, mixed $default = null): mixed
    {
        return data_get(array_merge(self::DEFAULT_APPEARANCE, $this->appearance ?? []), $key, $default);
    }

    /** Whether a page from this origin may load the button (scheme://host[:port], lower case). */
    public function allowsOrigin(?string $origin): bool
    {
        $allowed = array_values(array_filter(array_map([self::class, 'normalizeOrigin'], $this->allowed_origins ?? [])));
        if ($allowed === []) {
            return true;
        }
        $origin = self::normalizeOrigin($origin);

        return $origin !== null && in_array($origin, $allowed, true);
    }

    public static function normalizeOrigin(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (! str_contains($value, '://')) {
            $value = 'https://'.$value;
        }
        $parts = parse_url($value);
        if (! $parts || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }
}
