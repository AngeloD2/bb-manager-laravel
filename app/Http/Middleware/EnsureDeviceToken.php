<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureDeviceToken
 *
 * Guards the /api/v1/sync and /api/v1/logs routes.
 * Verifies the Sanctum token has the 'device:sync' or 'device:log' ability
 * and that the authenticated model is a Device (not an admin user, if you
 * add admin user auth later).
 */
class EnsureDeviceToken
{
    public function handle(Request $request, Closure $next, string $ability = 'device:sync'): Response
    {
        $user = $request->user();

        if (! $user instanceof \App\Models\Device) {
            return response()->json(['message' => 'Unauthorized. Device token required.'], 401);
        }

        if (! $request->user()->tokenCan($ability)) {
            return response()->json(['message' => "Token missing ability: {$ability}"], 403);
        }

        return $next($request);
    }
}
