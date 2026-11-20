<?php

namespace App\Services\Acquisition;

use App\Http\Middleware\CaptureAdClickId;
use App\Models\MemberSource;
use App\Models\User;
use App\Support\Acquisition\Touch;
use App\Support\InApp\InApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * How visitors find the site, until they sign up.
 *
 * Each page a visitor opens from outside the site tells the server where they came from
 * (App\Http\Controllers\LandedController; the page does it itself, as Cloudflare answers most public
 * pages without the server). The server keeps two of those arrivals in the visitor's own cookie for
 * up to 90 days: the first, and the latest that names a source. When the visitor signs up, both are
 * kept with the new account (MemberSource) and the cookie goes.
 *
 * Nothing is kept for a visitor from where the consent rules for ads apply (the UK, the EEA,
 * Switzerland), nor when their country is not known: the same rule as the ad clicks
 * (CaptureAdClickId::mayTrack).
 */
class VisitSources
{
    public const COOKIE = 'listen_src';

    public const MINUTES = 60 * 24 * 90;

    /** The cookie the arrival leaves, or null when nothing changes (forget() when one is there and may not be). */
    public function landed(Request $request): ?SymfonyCookie
    {
        if (! CaptureAdClickId::mayTrack($request)) {
            return $request->cookies->has(self::COOKIE) ? Cookie::forget(self::COOKIE) : null;
        }

        $touch = Touch::fromPage((array) $request->json()->all(), $this->ownHosts($request));
        $kept = $this->read($request);

        if ($kept === null) {
            $kept = ['f' => $touch->toArray(), 'l' => $touch->hasSource() ? $touch->toArray() : null];
        } elseif ($touch->hasSource()) {
            $kept['l'] = $touch->toArray();
        } else {
            // Typed in again, or a bookmark: the first visit and the latest source stay as they are.
            return null;
        }

        // Read by the server alone (httpOnly); secure as the site's other cookies are (config/session.php).
        return Cookie::make(self::COOKIE, (string) json_encode(array_filter($kept)), self::MINUTES, '/', null, null, true, false, 'lax');
    }

    /**
     * The visits kept for this browser: f (the first), l (the latest that named a source), or null.
     *
     * @return array{f: array<string, mixed>, l?: array<string, mixed>}|null
     */
    public function read(Request $request): ?array
    {
        $raw = $request->cookies->get(self::COOKIE);
        if (! is_string($raw) || $raw === '' || strlen($raw) > 3000) {
            return null;
        }

        $data = json_decode($raw, true);
        $first = is_array($data) ? Touch::fromStored($data['f'] ?? null) : null;
        if ($first === null) {
            return null;
        }
        $last = Touch::fromStored($data['l'] ?? null);

        return array_filter(['f' => $first->toArray(), 'l' => $last?->toArray()]);
    }

    /**
     * A new account: keep how its member found the site. Called when any account is made (User::created),
     * so it keeps nothing unless a visitor made it for themselves: a visitor's request, nobody signed in.
     */
    public function recordSignUp(User $user, ?Request $request = null): void
    {
        try {
            $request ??= request();
            if (! MemberSource::ready() || $request->route() === null || Auth::guard('web')->check()) {
                return;
            }
            if (MemberSource::query()->where('user_id', $user->id)->exists()) {
                return;
            }

            $visits = CaptureAdClickId::mayTrack($request) ? $this->read($request) : null;
            MemberSource::fromVisits((int) $user->id, $visits, $this->platform($request))->save();

            if ($visits !== null) {
                Cookie::queue(Cookie::forget(self::COOKIE));
            }
        } catch (\Throwable $e) {
            Log::warning('Could not keep how a new member found the site.', [
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /** android or ios when the account is made in the phone app (or the sign-in tab it opened), else null. */
    private function platform(Request $request): ?string
    {
        try {
            return InApp::platform($request);
        } catch (\Throwable) {
            // A request without a session: InApp asks it last, about the app's sign-in tab, once the
            // app's name and cookie have already said it is not the app.
            return null;
        }
    }

    /** @return list<string> */
    private function ownHosts(Request $request): array
    {
        return array_values(array_unique(array_filter([
            strtolower($request->getHost()),
            strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
        ])));
    }
}
