<?php

namespace App\Http\Controllers;

use App\Support\Clarity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Asked by a page that may record the visit (App\Support\Clarity) before it loads the recorder:
 * whether this visitor may be recorded. Their country is known only to the server, and Cloudflare
 * gives most public pages to everyone alike. No session, no cookies; never kept by any cache.
 */
class VisitCheckController
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()
            ->json(['record' => Clarity::mayRecord($request)])
            ->header('Cache-Control', 'no-store, private');
    }
}
