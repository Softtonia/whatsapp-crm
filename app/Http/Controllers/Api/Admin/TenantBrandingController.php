<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantBrandingService;
use App\Services\TenantEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantBrandingController extends Controller
{
    public function __construct(
        protected TenantBrandingService $brandingService,
        protected TenantEntitlementService $entitlementService
    ) {}

    /**
     * GET /api/auth/admin/tenants/{tenant}/branding
     */
    public function show(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeAccess($request->user(), $tenant, 'branding.read');

        $isEntitled = $this->entitlementService->canUseWhiteLabel($tenant, $request->user());
        $branding = $this->brandingService->getBranding($tenant);
        $preview = $this->brandingService->getPreview($tenant, $branding);

        return response()->json([
            'tenant_id' => $tenant->id,
            'is_white_label_permitted' => $isEntitled,
            'feature_restrictions' => $isEntitled ? null : 'Your subscription plan does not include white-label branding. Upgrade your plan to activate custom branding across customer touchpoints.',
            'branding' => [
                'id' => $branding->id,
                'brand_name' => $branding->brand_name,
                'logo_path' => $branding->logo_path,
                'logo_url' => $branding->logo_url,
                'favicon_path' => $branding->favicon_path,
                'favicon_url' => $branding->favicon_url,
                'primary_color' => $branding->primary_color,
                'secondary_color' => $branding->secondary_color,
                'support_email' => $branding->support_email,
                'created_at' => $branding->created_at?->toISOString(),
                'updated_at' => $branding->updated_at?->toISOString(),
            ],
            'preview' => $preview,
        ]);
    }

    /**
     * PUT /api/auth/admin/tenants/{tenant}/branding
     */
    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeAccess($request->user(), $tenant, 'branding.update');

        if (! $this->entitlementService->canUseWhiteLabel($tenant, $request->user())) {
            return response()->json([
                'message' => 'White-label branding is not permitted by your current subscription plan. Please upgrade to access this feature.',
                'error' => 'feature_not_entitled',
            ], 403);
        }

        $data = $request->validate([
            'brand_name' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'primary_color' => ['sometimes', 'required', 'regex:/^#([a-fA-F0-9]{6})$/'],
            'secondary_color' => ['sometimes', 'required', 'regex:/^#([a-fA-F0-9]{6})$/'],
            'support_email' => ['sometimes', 'required', 'email', 'max:255'],
        ], [
            'primary_color.regex' => 'The primary color must be a valid 6-digit hex color code (e.g. #336699).',
            'secondary_color.regex' => 'The secondary color must be a valid 6-digit hex color code (e.g. #25D366).',
            'support_email.email' => 'The support email must be a valid email address.',
        ]);

        $branding = $this->brandingService->updateBranding($tenant, $data, $request->user(), $request);

        return response()->json([
            'message' => 'Tenant branding updated successfully.',
            'branding' => [
                'id' => $branding->id,
                'brand_name' => $branding->brand_name,
                'logo_path' => $branding->logo_path,
                'logo_url' => $branding->logo_url,
                'favicon_path' => $branding->favicon_path,
                'favicon_url' => $branding->favicon_url,
                'primary_color' => $branding->primary_color,
                'secondary_color' => $branding->secondary_color,
                'support_email' => $branding->support_email,
                'updated_at' => $branding->updated_at?->toISOString(),
            ],
            'preview' => $this->brandingService->getPreview($tenant, $branding),
        ]);
    }

    /**
     * POST /api/auth/admin/tenants/{tenant}/branding/assets
     */
    public function uploadAsset(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeAccess($request->user(), $tenant, 'branding.update');

        if (! $this->entitlementService->canUseWhiteLabel($tenant, $request->user())) {
            return response()->json([
                'message' => 'White-label branding is not permitted by your current subscription plan. Please upgrade to access this feature.',
                'error' => 'feature_not_entitled',
            ], 403);
        }

        $data = $request->validate([
            'type' => ['required', 'string', 'in:logo,favicon'],
            'file' => ['required', 'file', 'max:2048', 'mimes:png,jpg,jpeg,webp'],
        ]);

        try {
            $result = $this->brandingService->storeAsset(
                $tenant,
                $request->file('file'),
                $data['type'],
                $request->user(),
                $request
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => ucfirst($data['type']) . ' uploaded successfully.',
            'data' => $result,
        ]);
    }

    /**
     * Check authentication, permission, and tenant isolation.
     */
    protected function authorizeAccess(?User $user, Tenant $tenant, string $permission): void
    {
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        $isSuperAdmin = $user->hasRole('Super Admin')
            || $user->hasRole('super_admin')
            || $user->roles()->where('slug', 'super_admin')->exists();

        // Check Spatie permission if assigned
        if (! $user->hasRole('Super Admin') && ! $user->hasRole('super_admin') && ! $user->can($permission) && ! $user->hasPermissionTo($permission, 'web')) {
            abort(403, "You do not have the required permission: {$permission}.");
        }

        // Even for admins, if the user belongs to another tenant and is not super admin without tenant, enforce membership
        $isMember = $tenant->memberships()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        // If user has memberships in other tenants but not this one, reject cross-tenant access
        $hasOtherMemberships = $user->memberships()->where('tenant_id', '!=', $tenant->id)->exists();
        if ($hasOtherMemberships && ! $isMember) {
            abort(403, 'You are not authorized to access this tenant.');
        }

        if (! $isMember && ! $user->hasRole('Super Admin') && ! $user->hasRole('super_admin')) {
            abort(403, 'You are not authorized to access this tenant.');
        }
    }
}
