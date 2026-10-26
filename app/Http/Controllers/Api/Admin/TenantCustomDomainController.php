<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantCustomDomain;
use App\Models\User;
use App\Services\CustomDomainService;
use App\Services\TenantEntitlementService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class TenantCustomDomainController extends Controller
{
    public function __construct(
        protected CustomDomainService $domainService,
        protected TenantEntitlementService $entitlementService
    ) {}

    /**
     * GET /api/auth/admin/tenants/{tenant}/domains
     */
    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeAccess($request->user(), $tenant, 'domains.read');

        $domains = $tenant->customDomains()
            ->orderByDesc('id')
            ->get()
            ->map(fn (TenantCustomDomain $domain) => $this->domainResponse($domain, true));

        return response()->json([
            'tenant_id' => $tenant->id,
            'is_custom_domain_permitted' => $this->entitlementService->canUseCustomDomain(
                $tenant,
                $request->user()
            ),
            'data' => $domains,
        ]);
    }

    /**
     * POST /api/auth/admin/tenants/{tenant}/domains
     */
    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeAccess($request->user(), $tenant, 'domains.manage');

        if (! $this->entitlementService->canUseCustomDomain($tenant, $request->user())) {
            return response()->json([
                'message' => 'Custom domains are not permitted by your current subscription plan. Please upgrade to access this feature.',
                'error' => 'feature_not_entitled',
            ], 403);
        }

        $data = $request->validate([
            'hostname' => ['required', 'string', 'max:253'],
        ]);

        try {
            $domain = $this->domainService->registerDomain(
                $tenant,
                $data['hostname'],
                $request->user(),
                $request
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (QueryException $e) {
            report($e);

            return response()->json([
                'message' => 'The domain could not be registered. Please try again.',
            ], 500);
        }

        return response()->json([
            'message' => 'Custom domain registered successfully. Please add the required DNS record to verify ownership.',
            'data' => $this->domainResponse($domain, true),
        ], 201);
    }

    /**
     * GET /api/auth/admin/tenants/{tenant}/domains/{domainId}
     */
    public function show(Request $request, Tenant $tenant, string $domainId): JsonResponse
    {
        $this->authorizeAccess($request->user(), $tenant, 'domains.read');

        $domain = $tenant->customDomains()->whereKey($domainId)->first();

        if (! $domain) {
            return response()->json([
                'message' => 'Custom domain not found for this tenant.',
            ], 404);
        }

        return response()->json([
            'data' => $this->domainResponse($domain, true),
        ]);
    }

    /**
     * POST /api/auth/admin/tenants/{tenant}/domains/{domainId}/verify
     */
    public function verify(
        Request $request,
        Tenant $tenant,
        string $domainId
    ): JsonResponse {
        $this->authorizeAccess($request->user(), $tenant, 'domains.manage');

        $domain = $tenant->customDomains()->whereKey($domainId)->first();

        if (! $domain) {
            return response()->json([
                'message' => 'Custom domain not found for this tenant.',
            ], 404);
        }

        if (! $this->entitlementService->canUseCustomDomain($tenant, $request->user())) {
            return response()->json([
                'message' => 'Custom domains are not permitted by the current subscription plan.',
                'error' => 'feature_not_entitled',
            ], 403);
        }

        $result = $this->domainService->verifyDomain(
            $domain,
            null,
            $request->user(),
            $request
        );

        if (! $result['success']) {
            $response = [
                'message' => $result['message'],
                'verification_status' => $result['status'] ?? 'failed',
            ];

            if (isset($result['dns_instructions'])) {
                $response['dns_instructions'] = $result['dns_instructions'];
            }

            return response()->json($response, 422);
        }

        return response()->json([
            'message' => $result['message'],
            'data' => $this->domainResponse($result['domain'] ?? $domain->fresh(), false),
        ]);
    }

    /**
     * POST /api/auth/admin/tenants/{tenant}/domains/{domainId}/provision-tls
     */
    public function provisionTls(
        Request $request,
        Tenant $tenant,
        string $domainId
    ): JsonResponse {
        $this->authorizeAccess($request->user(), $tenant, 'domains.manage');

        $domain = $tenant->customDomains()->whereKey($domainId)->first();

        if (! $domain) {
            return response()->json([
                'message' => 'Custom domain not found for this tenant.',
            ], 404);
        }

        if (! $domain->isVerified()) {
            return response()->json([
                'message' => 'DNS ownership must be verified before requesting a TLS certificate.',
                'error' => 'domain_not_verified',
            ], 422);
        }

        try {
            $result = $this->domainService->provisionTls($domain, $request->user(), $request);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => $result['message'],
            'data' => $this->domainResponse($domain->fresh(), false),
        ]);
    }

    /**
     * POST /api/auth/admin/tenants/{tenant}/domains/{domainId}/activate
     */
    public function activate(
        Request $request,
        Tenant $tenant,
        string $domainId
    ): JsonResponse {
        $this->authorizeAccess($request->user(), $tenant, 'domains.manage');

        $domain = $tenant->customDomains()->whereKey($domainId)->first();

        if (! $domain) {
            return response()->json([
                'message' => 'Custom domain not found for this tenant.',
            ], 404);
        }

        if (! $this->entitlementService->canUseCustomDomain($tenant, $request->user())) {
            return response()->json([
                'message' => 'Custom domains are not permitted by the current subscription plan.',
                'error' => 'feature_not_entitled',
            ], 403);
        }

        try {
            $activatedDomain = $this->domainService->activateDomain($domain, $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'activation_prerequisites_failed',
            ], 422);
        }

        return response()->json([
            'message' => 'Custom domain activated successfully.',
            'data' => $this->domainResponse($activatedDomain, false),
        ]);
    }

    /**
     * POST /api/auth/admin/tenants/{tenant}/domains/{domainId}/deactivate
     */
    public function deactivate(
        Request $request,
        Tenant $tenant,
        string $domainId
    ): JsonResponse {
        $this->authorizeAccess($request->user(), $tenant, 'domains.manage');

        $domain = $tenant->customDomains()->whereKey($domainId)->first();

        if (! $domain) {
            return response()->json([
                'message' => 'Custom domain not found for this tenant.',
            ], 404);
        }

        $deactivatedDomain = $this->domainService->deactivateDomain($domain, $request->user(), $request);

        return response()->json([
            'message' => 'Custom domain deactivated successfully.',
            'data' => $this->domainResponse($deactivatedDomain, false),
        ]);
    }

    /**
     * DELETE /api/auth/admin/tenants/{tenant}/domains/{domainId}
     */
    public function destroy(
        Request $request,
        Tenant $tenant,
        string $domainId
    ): JsonResponse {
        $this->authorizeAccess($request->user(), $tenant, 'domains.manage');

        $domain = $tenant->customDomains()->whereKey($domainId)->first();

        if (! $domain) {
            return response()->json([
                'message' => 'Custom domain not found for this tenant.',
            ], 404);
        }

        $this->domainService->deleteDomain(
            $domain,
            $request->user(),
            $request
        );

        return response()->json([
            'message' => 'Custom domain removed successfully.',
        ]);
    }

    /**
     * Shape the API response without exposing sensitive data.
     */
    private function domainResponse(
        TenantCustomDomain $domain,
        bool $includeDnsInstructions
    ): array {
        $data = [
            'id' => $domain->id,
            'hostname' => $domain->hostname,
            'verification_status' => $domain->verification_status,
            'is_active' => $domain->is_active,
            'tls_status' => $domain->tls_status,
            'tls_metadata' => $domain->tls_metadata,
            'verified_at' => $domain->verified_at?->toISOString(),
            'last_verification_attempt_at' =>
                $domain->last_verification_attempt_at?->toISOString(),
            'last_verification_error' => $domain->last_verification_error,
            'created_at' => $domain->created_at?->toISOString(),
            'updated_at' => $domain->updated_at?->toISOString(),
        ];

        if ($includeDnsInstructions) {
            $data['dns_instructions'] = [
                'record_type' => $domain->verification_record_type,
                'host' => $domain->verification_hostname,
                'value' => $domain->expected_record_value,
            ];
        }

        return $data;
    }

    /**
     * Authenticate, authorize and enforce tenant isolation.
     */
    protected function authorizeAccess(
        ?User $user,
        Tenant $tenant,
        string $permission
    ): void {
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        $isSuperAdmin = $user->hasRole('Super Admin')
            || $user->hasRole('super_admin');

        if (! $isSuperAdmin && ! $user->can($permission)) {
            abort(403, "You do not have the required permission: {$permission}.");
        }

        if ($isSuperAdmin) {
            return;
        }

        $isMember = $tenant->memberships()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            abort(403, 'You are not authorized to access this tenant.');
        }
    }
}
