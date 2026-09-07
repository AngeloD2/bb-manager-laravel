<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureBillboardToken
 *
 * Guards the /api/v1/sync and /api/v1/logs routes.
 * Verifies the Sanctum token has the 'billboard:sync' or 'billboard:log' ability
 * and that the authenticated model is a Billboard (not an admin user, if you
 * add admin user auth later).
 */
class EnsureBillboardToken
{
    public function handle(Request $request, Closure $next, string $ability = 'billboard:sync'): Response
    {
        $user = $request->user();

        if (! $user instanceof \App\Models\Billboard) {
            return response()->json(['message' => 'Unauthorized. Billboard token required.'], 401);
        }

        if (! $request->user()->tokenCan($ability)) {
            return response()->json(['message' => "Token missing ability: {$ability}"], 403);
        }

        return $next($request);
    }
}
