<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /agent-api is reached from the websites that carry an agent's call button.
 * Any website may ask (no cookies are sent or read): each widget checks the
 * website against the owner's list itself (AgentWidget::allowsOrigin).
 */
class AgentCors
{
    public function handle(Request $request, Closure $next): Response
    {
        // A call's background sound is the same file for every website and says so itself
        // (AgentCallController::sound): no Origin echoed, so caches keep one copy for all.
        if ($request->is('agent-api/sounds/*')) {
            return $next($request);
        }
        $response = $request->isMethod('OPTIONS') ? response('', 204) : $next($request);
        $origin = $request->headers->get('Origin');
        if ($origin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept');
            $response->headers->set('Access-Control-Max-Age', '600');
        }

        return $response;
    }
}
