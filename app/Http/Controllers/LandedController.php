<?php

namespace App\Http\Controllers;

use App\Services\Acquisition\VisitSources;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page opened from outside the site says where the visitor came from (resources/views/components/landed.blade.php):
 * the server keeps it in the visitor's cookie until they sign up (App\Services\Acquisition\VisitSources).
 * Answers nothing but that cookie.
 */
class LandedController
{
    public function __invoke(Request $request, VisitSources $sources): Response
    {
        $response = response()->noContent()->header('Cache-Control', 'no-store, private');
        $cookie = $sources->landed($request);

        return $cookie !== null ? $response->withCookie($cookie) : $response;
    }
}
