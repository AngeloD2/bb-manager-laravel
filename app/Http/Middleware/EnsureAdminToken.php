<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureAdminToken
 *
 * Guards admin endpoints to verify that the token belongs to an admin User
 * and contains the 'admin' ability.
 */
class EnsureAdminToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof \App\Models\User) {
            return response()->json(['message' => 'Unauthorized. Admin token required.'], 401);
        }

        if (! $user->tokenCan('admin')) {
            return response()->json(['message' => 'Token missing admin ability.'], 403);
        }

        return $next($request);
    }
}
