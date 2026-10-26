<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorrelationIdMiddleware
{
    /**
     * Handle an incoming request.
     * Ensures every request has a validated or server-generated correlation/request ID.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incomingId = $request->header('X-Request-ID') ?? $request->header('X-Correlation-ID');

        // Validate format: must be alphanumeric or UUID format (16-64 chars). Never blindly trust malformed input.
        if ($incomingId && preg_match('/^[a-zA-Z0-9_\-]{8,64}$/', $incomingId)) {
            $requestId = $incomingId;
        } else {
            $requestId = (string) Str::uuid();
        }

        // Attach request_id to request attributes
        $request->attributes->set('request_id', $requestId);

        $response = $next($request);

        // Echo back correlation headers on response
        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('X-Correlation-ID', $requestId);

        return $response;
    }
}
