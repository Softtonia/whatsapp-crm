<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Services\ActivityLogService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionController extends Controller
{
    public function __construct(
        protected ActivityLogService $activityLogService
    ) {}

    protected function authorizePermission(Request $request, string ...$permissions): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->status && $user->status !== 'active') {
            abort(403, 'Account is inactive. Access denied.');
        }

        // Platform owner or super admin bypass
        if ($user->hasRole('platform_owner') || $user->hasRole('Super Admin') || $user->hasRole('super_admin') || $user->roles()->whereIn('slug', ['platform_owner', 'super_admin'])->exists()) {
            return;
        }

        foreach ($permissions as $permission) {
            try {
                if ($user->can($permission) || $user->hasPermissionTo($permission, 'web')) {
                    return;
                }
            } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
                // Ignore missing permission in test setups
            }
        }

        abort(403, "You do not have the required permission: {$permissions[0]}.");
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'roles.read', 'roles.view', 'permissions.view');
        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get(['id', 'name', 'guard_name']);

        return response()->json([
            'success' => true,
            'message' => 'Permissions retrieved successfully.',
            'data' => $permissions,
        ]);
    }

    public function rolePermissions(Request $request, int $roleId): JsonResponse
    {
        $this->authorizePermission($request, 'roles.read', 'roles.view', 'roles.permissions.view');
        $role = Role::find($roleId);

        if (! $role) {
            return response()->json([
                'success' => false,
                'message' => "Role with ID {$roleId} not found.",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Role permissions retrieved successfully.',
            'data' => [
                'role' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                ],
                'permissions' => $role->permissions()
                    ->where('guard_name', 'web')
                    ->orderBy('name')
                    ->get(['permissions.id', 'permissions.name', 'permissions.guard_name']),
            ],
        ]);
    }

    public function syncRolePermissions(
        Request $request,
        int $roleId
    ): JsonResponse {
        $this->authorizePermission($request, 'roles.manage', 'roles.permissions.update');
        $role = Role::find($roleId);

        if (! $role) {
            return response()->json([
                'success' => false,
                'message' => "Role with ID {$roleId} not found.",
            ], 404);
        }

        if (in_array($role->name, ['platform_owner', 'Super Admin'], true) || in_array($role->slug, ['platform_owner', 'super_admin'], true)) {
            return response()->json([
                'success' => false,
                'message' => "Protected system role '{$role->name}' permissions are managed automatically and cannot be changed manually.",
            ], 403);
        }

        $validated = $request->validate([
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('permissions', 'id')
                    ->where('guard_name', 'web'),
            ],
        ]);

        $user = $request->user();
        $isPlatformOwner = $user && ($user->hasRole('platform_owner') || $user->roles()->where('slug', 'platform_owner')->exists());

        // Normal administrators cannot grant permissions they do not possess
        if (! $isPlatformOwner) {
            $userPermissionIds = $user ? $user->getAllPermissions()->pluck('id')->toArray() : [];
            $unauthorizedIds = array_diff($validated['permission_ids'], $userPermissionIds);

            if (! empty($unauthorizedIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot grant permissions that you do not personally possess.',
                ], 403);
            }
        }

        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('id', $validated['permission_ids'])
            ->get();

        $role->syncPermissions($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->activityLogService->log(
            eventType: 'role.permissions_synced',
            category: 'roles',
            description: "Permissions synced for role [{$role->name}].",
            actor: $request->user(),
            subjectType: Role::class,
            subjectId: $role->id,
            metadata: [
                'role_id' => $role->id,
                'permission_ids' => $validated['permission_ids'],
                'permission_count' => count($validated['permission_ids']),
            ],
            request: $request,
            newValues: ['permissions' => $permissions->pluck('name')->toArray()]
        );

        return response()->json([
            'success' => true,
            'message' => 'Role permissions updated successfully.',
            'data' => [
                'role' => [
                    'id' => $role->id,
                    'name' => $role->name,
                ],
                'permissions' => $role->permissions()
                    ->where('guard_name', 'web')
                    ->orderBy('name')
                    ->get(['permissions.id', 'permissions.name', 'permissions.guard_name']),
            ],
        ]);
    }
}
