<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the authenticated owner's library once per request and exposes it
 * as the "library" request attribute, so controllers don't repeat the lookup.
 */
class ResolveLibrary
{
    public function handle(Request $request, Closure $next): Response
    {
        $library = $request->user()?->libraries()->first();

        if (! $library) {
            return response()->json([
                'status' => false,
                'message' => 'Library not found',
            ], 404);
        }

        $request->attributes->set('library', $library);

        return $next($request);
    }
}
