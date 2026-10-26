<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminAccess
{
    /**
     * Handle an incoming request for admin routes.
     * Allows platform owners, super admins, and users with any platform role.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->status && $user->status !== 'active') {
            return response()->json(['message' => 'Account is inactive. Access denied.'], 403);
        }

        $hasPlatformRole = $user->roles()
            ->where('guard_name', 'web')
            ->exists();

        if (! $hasPlatformRole) {
            return response()->json(['message' => 'Forbidden. Platform administrator access required.'], 403);
        }

        return $next($request);
    }
}
