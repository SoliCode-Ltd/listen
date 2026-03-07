<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceAdminLocale
{
    /**
     * Force the admin panel to always use English locale and LTR direction.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Force English locale for admin panel
        app()->setLocale('en');

        // Ensure the session locale doesn't override admin
        session()->forget('locale');

        return $next($request);
    }
}
