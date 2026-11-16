<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

    /**
     * How the call button looks; the owner styles it with a live preview, and
     * every website shows the new look on its next page load.
     * style: what the call shows (an orb that moves with the voice, the
     * brand's waves, or a slim bar), icon: the button's icon, accent: the
     * button colour, palette: the orb's colours (or two custom ones).
     */
    public const DEFAULT_APPEARANCE = [
        'position' => 'end',
        'style' => 'orb',
        'theme' => 'auto',
        'label' => null,
        'icon' => 'phone',
        'size' => 'm',
        'shape' => 'pill',
        'accent' => '#7659FA',
        'palette' => 'listen',
        'orb_colors' => null,
        'radius' => 'round',
        'captions' => true,
        'photo' => true,
        'title' => null,
        'subtitle' => null,
        'font' => 'system',
        // The line under the orb before a call ("Ready when you are"): the owner's words, or hidden.
        'ready_text' => null,
        'ready_show' => true,
        // What the caller hears behind the agent's voice: a quiet office, or nothing (CallBackground).
        'background' => 'office',
    ];

    public const STYLES = ['orb', 'waves', 'bar'];

    public const BACKGROUNDS = ['office', 'none'];

    public const ICONS = ['phone', 'mic', 'headset', 'waves', 'orb'];

    /** The orb's colours, deep to light. */
    public const PALETTES = [
        'listen' => ['#2A1A8F', '#7659FA', '#C9BEFF', '#F4F1FF'],
        'ocean' => ['#0B3B6E', '#1E88E5', '#8FD3FF', '#EEF9FF'],
        'emerald' => ['#064E3B', '#10B981', '#A7F3D0', '#F0FFF8'],
        'sunset' => ['#7C2D12', '#F97316', '#FDBA74', '#FFF4EA'],
        'rose' => ['#831843', '#EC4899', '#F9A8D4', '#FFF0F7'],
        'gold' => ['#6B4A0E', '#D4A017', '#F7DC86', '#FFFBEA'],
        'midnight' => ['#0F172A', '#475569', '#CBD5E1', '#F8FAFC'],
    ];

    public const ACCENTS = ['#7659FA', '#1E88E5', '#10B981', '#F97316', '#EC4899', '#D4A017', '#0F172A'];

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
        return data_get($this->style(), $key, $default);
    }

    /**
     * The look, every value checked (anything unknown falls back to the
     * default), plus the orb's four colours.
     *
     * @return array<string, mixed>
     */
    public function style(): array
    {
        $d = self::DEFAULT_APPEARANCE;
        $a = array_merge($d, array_filter($this->appearance ?? [], fn ($v) => $v !== null));
        $pick = fn (string $k, array $allowed) => in_array($a[$k], $allowed, true) ? $a[$k] : $d[$k];
        $text = fn (string $k, int $max) => ($v = trim(strip_tags((string) ($a[$k] ?? '')))) !== '' ? mb_substr($v, 0, $max) : null;
        $out = [
            'position' => $pick('position', ['start', 'end']),
            'style' => $pick('style', self::STYLES),
            'theme' => $pick('theme', ['auto', 'light', 'dark']),
            'label' => $text('label', 40),
            'icon' => $pick('icon', self::ICONS),
            'size' => $pick('size', ['s', 'm', 'l']),
            'shape' => $pick('shape', ['pill', 'round']),
            'accent' => self::hex($a['accent']) ?? $d['accent'],
            'palette' => $a['palette'] === 'custom' || isset(self::PALETTES[$a['palette']]) ? $a['palette'] : $d['palette'],
            'orb_colors' => null,
            'radius' => $pick('radius', ['square', 'soft', 'round']),
            'captions' => filter_var($a['captions'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
            // The agent's photo next to its name on the call card (the orb stays alone in the middle).
            'photo' => filter_var($a['photo'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
            'title' => $text('title', 60),
            'subtitle' => $text('subtitle', 80),
            'font' => $pick('font', ['system', 'rounded', 'site']),
            'ready_text' => $text('ready_text', 60),
            'ready_show' => filter_var($a['ready_show'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
            'background' => $pick('background', self::BACKGROUNDS),
        ];
        $custom = array_values(array_filter(array_map([self::class, 'hex'], (array) ($a['orb_colors'] ?? []))));
        if ($out['palette'] === 'custom' && count($custom) >= 2) {
            $out['orb_colors'] = [$custom[0], $custom[1]];
            $out['colors'] = [$custom[0], self::mix($custom[0], $custom[1], .5), $custom[1], self::mix($custom[1], '#FFFFFF', .75)];
        } else {
            if ($out['palette'] === 'custom') {
                $out['palette'] = $d['palette'];
            }
            $out['colors'] = self::PALETTES[$out['palette']];
        }

        return $out;
    }

    /** "#7659fa" or "7659FA" as "#7659FA"; anything else is null. */
    public static function hex(mixed $value): ?string
    {
        $v = ltrim(trim((string) $value), '#');

        return preg_match('/^[0-9a-fA-F]{6}$/', $v) ? '#'.strtoupper($v) : null;
    }

    /** A colour part way from one to another (0 = the first, 1 = the second). */
    public static function mix(string $from, string $to, float $t): string
    {
        $a = sscanf(ltrim($from, '#'), '%02x%02x%02x');
        $b = sscanf(ltrim($to, '#'), '%02x%02x%02x');
        $c = array_map(fn ($i) => (int) round($a[$i] + ($b[$i] - $a[$i]) * $t), [0, 1, 2]);

        return sprintf('#%02X%02X%02X', ...$c);
    }

    /** Whether a page from this origin may load the button (scheme://host[:port], lower case). */
    /**
     * Whether a website may load this call button. With no list yet, any website may, until the
     * first one loads it (AgentCallController::seen() then keeps that website). A site's www and
     * plain names are one website, and the owner's own computer (localhost) is always allowed:
     * no visitor reaches the button there.
     */
    public function allowsOrigin(?string $origin): bool
    {
        $allowed = $this->allowedOrigins();
        if ($allowed === []) {
            return true;
        }
        $origin = self::normalizeOrigin($origin);
        if ($origin === null) {
            return false;
        }
        if (self::isLocal($origin)) {
            return true;
        }

        return in_array($origin, $allowed, true) || in_array(self::twin($origin), $allowed, true);
    }

    /** @return list<string> */
    public function allowedOrigins(): array
    {
        return array_values(array_unique(array_filter(array_map([self::class, 'normalizeOrigin'], (array) ($this->allowed_origins ?? [])))));
    }

    public static function isLocal(string $origin): bool
    {
        return (bool) preg_match('#^https?://localhost(:\d+)?$#', $origin);
    }

    /** The same website with or without "www.". */
    public static function twin(string $origin): string
    {
        return str_contains($origin, '://www.')
            ? str_replace('://www.', '://', $origin)
            : preg_replace('#^(https?://)#', '$1www.', $origin);
    }

    /**
     * The last website that tried to load the call button and was not on the list, for the owner
     * to allow in one tap (Go live → Security).
     *
     * @return array{origin: string, at: Carbon}|null
     */
    public function lastRefused(): ?array
    {
        $refused = Cache::get('agent-widget-refused:'.$this->id);
        if (! is_array($refused) || empty($refused['origin']) || $this->allowsOrigin($refused['origin'])) {
            return null;
        }

        return ['origin' => (string) $refused['origin'], 'at' => Carbon::parse($refused['at'] ?? now())];
    }

    public function noteRefused(?string $origin): void
    {
        $origin = self::normalizeOrigin($origin);
        if ($origin === null) {
            return;
        }
        $key = 'agent-widget-refused:'.$this->id;
        $last = Cache::get($key);
        if (is_array($last) && ($last['origin'] ?? null) === $origin && now()->diffInMinutes(Carbon::parse($last['at'] ?? now()), true) < 10) {
            return;
        }
        Cache::put($key, ['origin' => $origin, 'at' => now()->toIso8601String()], now()->addDays(14));
    }

    /** When the call button was last seen on a website, in words: "3 minutes ago", or "just now". */
    public function seenAgo(): ?string
    {
        return $this->last_seen_at?->diffForHumans(['options' => CarbonInterface::JUST_NOW]);
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
        // A website's name: dotted labels (or this computer), never a stray word.
        if (! in_array($scheme, ['http', 'https'], true)
            || ($host !== 'localhost' && ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $host))) {
            return null;
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }
}
