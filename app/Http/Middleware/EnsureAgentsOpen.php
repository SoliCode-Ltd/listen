<?php

namespace App\Http\Middleware;

use App\Support\Agents\AgentAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Agents pages (the public page and the owner's side) only for whom Agents → Settings
 * opens them: everyone, or the admin email before launch. Everyone else gets "page not found".
 * Callers' calls (/agent-api) and link pages are not behind it: agents already live keep
 * answering until "Stop all calls now".
 */
class EnsureAgentsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(AgentAccess::openTo($request->user()), 404);

        return $next($request);
    }
}
