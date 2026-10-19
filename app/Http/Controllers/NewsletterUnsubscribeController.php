<?php

namespace App\Http\Controllers;

use App\Models\NewsletterSuppression;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\URL;

class NewsletterUnsubscribeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if (! $this->hasValidSignature($request)) {
            throw new InvalidSignatureException;
        }

        $email = strtolower(trim((string) $request->query('email', '')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(404);
        }

        NewsletterSuppression::query()->updateOrCreate(
            ['email' => $email],
            ['unsubscribed_at' => now()],
        );

        $locale = $request->query('lang') === 'ar' ? 'ar' : 'en';

        return response()->view('newsletter.unsubscribed', [
            'email' => $email,
            'locale' => $locale,
        ]);
    }

    /**
     * The links are signed for the https address, but behind the host's proxy
     * PHP can see the request as plain http, where that signature would not
     * match. So a signature counts for the address as it came in or for the
     * same address over https; a link with anything changed fails both.
     */
    private function hasValidSignature(Request $request): bool
    {
        if (URL::hasValidSignature($request)) {
            return true;
        }

        if ($request->secure()) {
            return false;
        }

        $query = (string) $request->server->get('QUERY_STRING');
        $overHttps = Request::create('https://'.$request->getHost().$request->getBaseUrl().$request->getPathInfo().($query !== '' ? '?'.$query : ''));

        return URL::hasValidSignature($overHttps);
    }
}
