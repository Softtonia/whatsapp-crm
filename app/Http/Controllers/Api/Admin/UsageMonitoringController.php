<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\QuotaEnforcementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageMonitoringController extends Controller
{
    public function __construct(
        protected QuotaEnforcementService $quotaService
    ) {}

    /**
     * Authorize permission or super admin / platform owner.
     */
    protected function authorizePermission(Request $request, string $permission): void
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->status && $user->status !== 'active') {
            abort(403, 'Account is inactive. Access denied.');
        }

        if ($user->hasRole('platform_owner') || $user->hasRole('Super Admin') || $user->hasRole('super_admin')) {
            return;
        }

        if (! $user->can($permission) && ! $user->hasPermissionTo($permission, 'web')) {
            abort(403, "You do not have the required permission: {$permission}.");
        }
    }

    /**
     * Authorize user access to tenant data (cross-tenant protection).
     */
    protected function authorizeTenantAccess(Request $request, int $tenantId): void
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->hasRole('platform_owner') || $user->hasRole('Super Admin') || $user->hasRole('super_admin')) {
            return;
        }

        // Must be an active member of the requested tenant
        $isMember = $user->memberships()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            abort(403, 'Forbidden. You do not have access to this tenant.');
        }
    }

    /**
     * GET /usage/summary
     * Platform-wide usage aggregates and metric summaries.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'usage.read');

        $validated = $request->validate([
            'plan_id' => ['sometimes', 'nullable', 'integer', 'exists:subscription_plans,id'],
            'period' => ['sometimes', 'nullable', 'string', 'in:current,month,year'],
        ]);

        $summary = $this->quotaService->getPlatformSummary($validated);

        return response()->json([
            'success' => true,
            'message' => 'Platform usage summary retrieved successfully.',
            'data' => $summary,
        ]);
    }

    /**
     * GET /usage/tenants
     * Paginated list of tenants with effective limits and usage metrics.
     */
    public function tenants(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'usage.read');

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tenant_id' => ['sometimes', 'nullable', 'integer', 'exists:tenants,id'],
            'plan_id' => ['sometimes', 'nullable', 'integer', 'exists:subscription_plans,id'],
            'metric' => ['sometimes', 'nullable', 'string', 'in:max_whatsapp_numbers,max_monthly_campaigns,max_whatsapp_messages,max_ai_replies,max_team_members,max_contacts'],
            'over_limit' => ['sometimes', 'nullable', 'boolean'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 15);
        $page = (int) ($validated['page'] ?? 1);

        $paginator = $this->quotaService->getTenantsUsageList($validated, $perPage, $page);

        return response()->json([
            'success' => true,
            'message' => 'Tenant usage list retrieved successfully.',
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * GET /tenants/{id}/usage
     * Historical usage breakdown for the specified tenant.
     */
    public function tenantUsage(Request $request, int $id): JsonResponse
    {
        $this->authorizePermission($request, 'usage.read');
        $this->authorizeTenantAccess($request, $id);

        $validated = $request->validate([
            'metric' => ['sometimes', 'nullable', 'string', 'in:max_whatsapp_numbers,max_monthly_campaigns,max_whatsapp_messages,max_ai_replies,max_team_members,max_contacts'],
            'period_start' => ['sometimes', 'nullable', 'date'],
            'period_end' => ['sometimes', 'nullable', 'date', 'after_or_equal:period_start'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 15);
        $page = (int) ($validated['page'] ?? 1);

        $paginator = $this->quotaService->getTenantUsageHistory($id, $validated, $perPage, $page);

        return response()->json([
            'success' => true,
            'message' => 'Tenant usage history retrieved successfully.',
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * GET /tenants/{id}/quotas
     * Current effective quotas, usage, allowances, and enforcement status.
     */
    public function tenantQuotas(Request $request, int $id): JsonResponse
    {
        $this->authorizePermission($request, 'usage.read');
        $this->authorizeTenantAccess($request, $id);

        $quotas = $this->quotaService->getTenantQuotas($id);

        return response()->json([
            'success' => true,
            'message' => 'Tenant quotas and effective limits retrieved successfully.',
            'data' => $quotas,
        ]);
    }
}
