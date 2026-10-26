<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\TenantCustomDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantByDomain
{
    /**
     * Handle an incoming request by resolving the custom domain to an active, verified tenant.
     *
     * If the host matches an active custom domain, resolves and sets the tenant context.
     * If the host is a custom domain that is unverified or inactive, returns 404 / access denied.
     * If the host is the central domain or localhost, passes through to standard routing.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $rawHost = $request->getHost();
        $hostname = strtolower(trim(explode(':', $rawHost)[0]));

        // Check if the request is for the central domain, localhost or direct IP
        $centralDomains = config('app.central_domains', [
            'localhost',
            '127.0.0.1',
            'api.localhost',
            'crm.local',
            'whatsapp-crm.local',
        ]);

        if (in_array($hostname, $centralDomains, true)) {
            return $next($request);
        }

        // Search for a matching custom domain
        $domain = TenantCustomDomain::with('tenant')
            ->where('hostname', $hostname)
            ->first();

        if (! $domain) {
            // Not a registered custom domain; allow central fallback or standard 404
            return $next($request);
        }

        // Domain must be verified and active to serve tenant requests
        if (! $domain->is_active || ! $domain->isVerified()) {
            return response()->json([
                'success' => false,
                'message' => 'This custom domain is not active or verified.',
                'error' => 'custom_domain_inactive',
            ], 404);
        }

        // Check platform maintenance mode
        $settingService = app(\App\Services\PlatformSettingService::class);
        $isMaintenance = (bool) $settingService->get('maintenance_mode', false);
        if ($isMaintenance) {
            return response()->json([
                'success' => false,
                'message' => 'The platform is currently undergoing scheduled maintenance. Please check back shortly.',
                'error' => 'platform_maintenance',
            ], 503);
        }

        $tenant = $domain->tenant;

        if (! $tenant || $tenant->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'The tenant associated with this custom domain is inactive or suspended.',
                'error' => 'tenant_inactive',
            ], 403);
        }

        // Attach resolved tenant to request attributes and service container
        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('custom_domain', $domain);
        app()->instance('current_tenant', $tenant);

        return $next($request);
    }
}
