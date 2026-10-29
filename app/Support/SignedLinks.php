<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Checks the site's signed links (URL::temporarySignedRoute and the like).
 */
final class SignedLinks
{
    /**
     * The links are signed for the https address, but behind the host's proxy
     * PHP can see the request as plain http, where that signature would not
     * match. So a signature counts for the address as it came in or for the
     * same address over https; a link with anything changed fails both.
     */
    public static function valid(Request $request): bool
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
