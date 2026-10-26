<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->status && $user->status !== 'active') {
            return response()->json(['message' => 'Account is inactive. Access denied.'], 403);
        }

        $isAuthorized = $user->hasRole('platform_owner')
            || $user->hasRole('Super Admin')
            || $user->hasRole('super_admin')
            || $user->roles()->whereIn('slug', ['platform_owner', 'super_admin'])->exists();

        if (! $isAuthorized) {
            return response()->json(['message' => 'Forbidden. Super admin access is required.'], 403);
        }

        return $next($request);
    }
}

