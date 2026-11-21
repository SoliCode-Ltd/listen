<?php

namespace App\Support\Acquisition;

/**
 * One arrival on the site from outside it, as the page reported it (App\Http\Controllers\LandedController)
 * and as it is kept: the address of the site the visitor came from (its name only, never the page),
 * the page they arrived on (without its query), the campaign named in the link (utm_…), and which ad
 * networks' click markers the link carried (their names only, never the values).
 *
 * Everything is checked here: anything unknown, too long or malformed is dropped.
 */
final class Touch
{
    /** The campaign fields of a link that are kept, with the longest value kept. */
    public const CAMPAIGN_FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gad_campaignid'];

    /** The click markers ad networks (and Listen's invite links) add to a link: only their presence is kept. */
    public const CLICK_MARKERS = ['gclid', 'gbraid', 'wbraid', 'gad_source', 'fbclid', 'ttclid', 'oppref', 'msclkid', 'twclid', 'li_fat_id', 'sccid', 'ref'];

    /**
     * Sites a visitor passes through without having come from them: signing in with Google or Apple,
     * paying, and the bank's check. Arriving back from one of them is not a new source.
     */
    private const PASS_THROUGH = [
        'accounts.google.com', 'accounts.youtube.com', 'myaccount.google.com', 'appleid.apple.com',
        'checkout.stripe.com', 'hooks.stripe.com', 'js.stripe.com', 'm.stripe.network', 'pay.google.com',
        'payments.google.com', 'android-app://com.google.android.gms', 'localhost', '127.0.0.1',
    ];

    /**
     * @param  array<string, string>  $campaign
     * @param  list<string>  $clicks
     */
    private function __construct(
        public readonly string $host,
        public readonly string $path,
        public readonly array $campaign,
        public readonly array $clicks,
        public readonly int $at,
    ) {}

    /**
     * What the page sent: r (the site it came from: its host name, or android-app://<package> when an
     * Android app opened the link), p (the page's path), q (the link's campaign fields), k (the click
     * markers it carried).
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $ownHosts  the site's own names: arriving from one of them is moving inside the site
     */
    public static function fromPage(array $input, array $ownHosts, ?int $at = null): self
    {
        return new self(
            self::cleanHost($input['r'] ?? '', $ownHosts),
            self::cleanPath($input['p'] ?? ''),
            self::cleanCampaign($input['q'] ?? []),
            self::cleanClicks($input['k'] ?? []),
            $at ?? time(),
        );
    }

    /** A touch as kept in the cookie or the member's record (toArray), checked again. */
    public static function fromStored(mixed $stored): ?self
    {
        if (! is_array($stored)) {
            return null;
        }

        return new self(
            self::cleanHost($stored['h'] ?? '', []),
            self::cleanPath($stored['p'] ?? ''),
            self::cleanCampaign($stored['u'] ?? []),
            self::cleanClicks($stored['k'] ?? []),
            max(0, (int) ($stored['t'] ?? 0)),
        );
    }

    /** Whether it says where the visitor came from: a site, a campaign or an ad's click. Typing the address, a bookmark or an app that hides the link says nothing. */
    public function hasSource(): bool
    {
        return $this->host !== '' || $this->campaign !== [] || $this->clicks !== [];
    }

    /** @return array{h: string, p: string, u: array<string, string>, k: list<string>, t: int} */
    public function toArray(): array
    {
        return ['h' => $this->host, 'p' => $this->path, 'u' => $this->campaign, 'k' => $this->clicks, 't' => $this->at];
    }

    private static function cleanHost(mixed $value, array $ownHosts): string
    {
        $value = strtolower(trim(is_string($value) ? $value : ''));
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, 'android-app://')) {
            $package = rtrim(substr($value, strlen('android-app://')), '/');
            $host = preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $package) === 1 && strlen($package) <= 100 ? 'android-app://'.$package : '';
        } else {
            // A full address is reduced to its host name; a bare host name is taken as it is.
            if (str_contains($value, '/')) {
                $value = (string) (parse_url(str_contains($value, '://') ? $value : 'https://'.$value, PHP_URL_HOST) ?? '');
            }
            $value = preg_replace('/^www\./', '', rtrim($value, '.')) ?? '';
            $host = preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $value) === 1 && strlen($value) <= 120 ? $value : '';
        }

        if ($host === '' || in_array($host, self::PASS_THROUGH, true)) {
            return '';
        }
        foreach ($ownHosts as $own) {
            $own = preg_replace('/^www\./', '', strtolower($own)) ?? '';
            if ($own !== '' && ($host === $own || str_ends_with($host, '.'.$own))) {
                return '';
            }
        }

        return $host;
    }

    private static function cleanPath(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        $value = explode('?', explode('#', $value)[0])[0];
        if ($value === '' || $value[0] !== '/' || strlen($value) > 200 || preg_match('#^/[A-Za-z0-9\-._~/%]*$#', $value) !== 1) {
            return '/';
        }

        return $value;
    }

    /** @return array<string, string> */
    private static function cleanCampaign(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $kept = [];
        foreach (self::CAMPAIGN_FIELDS as $field) {
            $value = $values[$field] ?? null;
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }
            // Printable text only, without the characters a spreadsheet would read as a formula.
            $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value));
            $value = ltrim($value, '=+-@');
            if ($field === 'gad_campaignid') {
                $value = preg_match('/^\d{1,20}$/', $value) === 1 ? $value : '';
            }
            if ($value === '') {
                continue;
            }
            $value = mb_substr($value, 0, 100);
            $kept[$field] = in_array($field, ['utm_source', 'utm_medium'], true) ? mb_strtolower($value) : $value;
        }

        return $kept;
    }

    /** @return list<string> */
    private static function cleanClicks(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $names = array_map(fn ($name) => is_string($name) ? strtolower(trim($name)) : '', $values);

        return array_values(array_filter(self::CLICK_MARKERS, fn (string $marker) => in_array($marker, $names, true)));
    }
}
