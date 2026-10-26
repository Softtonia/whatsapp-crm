<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\RoleResource;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

use App\Services\ActivityLogService;

class RoleController extends Controller
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

    public function index(Request $request)
    {
        $this->authorizePermission($request, 'roles.read', 'roles.view');
        $roles = Role::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            })
            ->withCount('users')
            ->orderBy('name')
            ->paginate(min((int) $request->input('per_page', 15), 100));

        return RoleResource::collection($roles);
    }

    public function store(Request $request)
    {
        $this->authorizePermission($request, 'roles.manage', 'roles.create');
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                'unique:roles,name',
                Rule::notIn(['platform_owner', 'Super Admin', 'super_admin']),
            ],
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                'unique:roles,slug',
                Rule::notIn(['platform_owner', 'Super Admin', 'super_admin']),
            ],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'guard_name' => ['sometimes', 'in:web'],
        ]);

        $role = Role::create([
            ...$validated,
            'guard_name' => 'web',
            'is_default' => false,
        ]);

        $this->activityLogService->log(
            eventType: 'role.created',
            category: 'roles',
            description: "Role [{$role->name}] was created.",
            actor: $request->user(),
            subjectType: Role::class,
            subjectId: $role->id,
            metadata: ['name' => $role->name, 'slug' => $role->slug],
            request: $request,
            newValues: $role->toArray()
        );

        return (new RoleResource($role))
            ->response()
            ->setStatusCode(201);
    }


    public function show(Request $request, int $roleId)
    {
        $this->authorizePermission($request, 'roles.read', 'roles.view');
        $role = Role::find($roleId);

        if (! $role) {
            return response()->json([
                'success' => false,
                'message' => "Role with ID {$roleId} not found.",
            ], 404);
        }

        return new RoleResource(
            $role->loadCount('users')->load('permissions')
        );
    }

    public function update(Request $request, int $role)
    {
        $this->authorizePermission($request, 'roles.manage', 'roles.update');
        $role = Role::find($role);

        if (! $role) {
            return response()->json([
                'success' => false,
                'message' => "Role with ID {$role} not found.",
            ], 404);
        }

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')->ignore($role->id),
            ],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('roles', 'slug')->ignore($role->id),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($role->is_default && (
            isset($validated['name']) || isset($validated['slug'])
        )) {
            throw ValidationException::withMessages([
                'name' => 'Default system role names and slugs cannot be changed.',
            ]);
        }

        $oldValues = $role->toArray();
        $role->fill($validated)->save();
        $newValues = $role->fresh()->toArray();

        $this->activityLogService->log(
            eventType: 'role.updated',
            category: 'roles',
            description: "Role [{$role->name}] was updated.",
            actor: $request->user(),
            subjectType: Role::class,
            subjectId: $role->id,
            metadata: ['role_id' => $role->id, 'updated_fields' => array_keys($validated)],
            request: $request,
            oldValues: $oldValues,
            newValues: $newValues
        );

        return new RoleResource($role->fresh()->loadCount('users'));
    }

    public function destroy(Request $request, int $role)
    {
        $this->authorizePermission($request, 'roles.manage', 'roles.delete');
        $role = Role::find($role);

        if (! $role) {
            return response()->json([
                'success' => false,
                'message' => "Role with ID {$role} not found.",
            ], 404);
        }

        if ($role->is_default) {
            return response()->json([
                'success' => false,
                'message' => 'Default system roles cannot be deleted.',
            ], 403);
        }

        if ($role->users()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This role is assigned to users and cannot be deleted.',
            ], 422);
        }

        $deletedSnapshot = $role->toArray();

        DB::transaction(function () use ($role) {
            $role->permissions()->detach();
            $role->delete();
        });

        $this->activityLogService->log(
            eventType: 'role.deleted',
            category: 'roles',
            description: "Role [{$deletedSnapshot['name']}] was deleted.",
            actor: $request->user(),
            subjectType: Role::class,
            subjectId: $role->id,
            metadata: ['deleted_role' => $deletedSnapshot],
            request: $request,
            oldValues: $deletedSnapshot
        );

        return response()->json([
            'success' => true,
            'message' => 'Role deleted successfully.',
        ]);
    }
}
