<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\AdminInvitation;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Mail\AdminInvitationMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class AdminUserController extends Controller
{
    public function __construct(
        protected ActivityLogService $activityLogService
    ) {}

    /**
     * List all platform administrators.
     * Scoped strictly to users with platform roles (never returns tenant-only users).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'admin_users.read', 'users.view');

        $query = User::query()
            ->whereHas('roles', function ($q) {
                $q->where('guard_name', 'web');
            })
            ->with('roles');

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $roleInput = $request->string('role')->toString();
            $query->whereHas('roles', function ($q) use ($roleInput) {
                $q->where('name', $roleInput)
                    ->orWhere('slug', $roleInput);
            });
        }

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();
            $query->where('status', $status);
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $users = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Admin users retrieved successfully.',
            'data' => AdminUserResource::collection($users->items()),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Invite a new platform administrator.
     */
    public function invite(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'admin_users.create', 'users.create');

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role_name' => ['required', 'string', 'max:100'],
        ]);

        $actor = $request->user();
        $isPlatformOwner = $this->isPlatformOwner($actor);

        $role = Role::query()
            ->where('guard_name', 'web')
            ->where(function ($q) use ($validated) {
                $q->where('name', $validated['role_name'])
                    ->orWhere('slug', $validated['role_name']);
            })
            ->first();

        if (! $role) {
            return response()->json([
                'success' => false,
                'message' => "Role '{$validated['role_name']}' does not exist.",
            ], 422);
        }

        // Only a platform owner can invite or grant the platform_owner role
        if (in_array($role->name, ['platform_owner'], true) || in_array($role->slug, ['platform_owner'], true)) {
            if (! $isPlatformOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only an existing platform owner can invite a platform owner.',
                ], 403);
            }
        }

        // A non-platform-owner cannot assign a role that grants permissions they do not possess
        if (! $isPlatformOwner) {
            $actorPermissionIds = $actor ? $actor->getAllPermissions()->pluck('id')->toArray() : [];
            $rolePermissionIds = $role->permissions()->pluck('id')->toArray();
            $unauthorizedIds = array_diff($rolePermissionIds, $actorPermissionIds);

            if (! empty($unauthorizedIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot invite a user with a role that possesses permissions you do not personally have.',
                ], 403);
            }
        }

        // Check if user already exists with an admin role
        $existingAdmin = User::query()
            ->where('email', $validated['email'])
            ->whereHas('roles', fn ($q) => $q->where('guard_name', 'web'))
            ->first();

        if ($existingAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'A platform administrator with this email already exists.',
            ], 422);
        }

        $rawToken = Str::random(40);
        $tokenHash = hash('sha256', $rawToken);

        $invitation = DB::transaction(function () use ($validated, $role, $tokenHash, $actor) {
            // Invalidate any prior active invitation for this email
            AdminInvitation::where('email', $validated['email'])
                ->whereNull('accepted_at')
                ->delete();

            return AdminInvitation::create([
                'email' => $validated['email'],
                'role_name' => $role->name,
                'token_hash' => $tokenHash,
                'invited_by' => $actor?->id,
                'expires_at' => now()->addDays(3),
            ]);
        });

        // Dispatch invitation email
        $frontendUrl = rtrim(config('app.frontend_url', config('app.url', 'http://localhost')), '/');
        $acceptUrl = "{$frontendUrl}/accept-invitation?token={$rawToken}";
        $expiryFormatted = $invitation->expires_at->toFormattedDateString() . ' (' . $invitation->expires_at->diffForHumans() . ')';

        try {
            Mail::to($invitation->email)->send(
                new AdminInvitationMail(
                    invitation: $invitation,
                    acceptUrl: $acceptUrl,
                    roleName: $role->name,
                    expiryDate: $expiryFormatted
                )
            );
        } catch (\Throwable $mailException) {
            // Roll back the created invitation to avoid leaving an unusable invitation
            $invitation->delete();

            return response()->json([
                'success' => false,
                'message' => 'Failed to deliver administrator invitation email. Please verify mail server settings and try again.',
                'error' => $mailException->getMessage(),
            ], 500);
        }

        $this->activityLogService->log(
            eventType: 'admin_user_invited',
            category: 'admin_management',
            description: "Invited new admin user [{$invitation->email}] with role [{$role->name}].",
            actor: $actor,
            subjectType: AdminInvitation::class,
            subjectId: $invitation->id,
            metadata: [
                'email' => $invitation->email,
                'role' => $role->name,
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'email_dispatched' => true,
            ],
            request: $request
        );

        $responseData = [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role_name' => $invitation->role_name,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'email_sent' => true,
        ];

        // For local development or automated testing, provide raw token if explicitly enabled/testing
        if (app()->environment('testing', 'local') || config('app.debug')) {
            $responseData['invitation_token'] = $rawToken;
        }

        return response()->json([
            'success' => true,
            'message' => 'Admin invitation generated and sent successfully.',
            'data' => $responseData,
        ], 201);
    }

    /**
     * Accept an admin invitation and establish account.
     */
    public function acceptInvitation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone_number' => ['nullable', 'string', 'max:20'],
        ]);

        $tokenHash = hash('sha256', $validated['token']);

        return DB::transaction(function () use ($validated, $tokenHash, $request) {
            $invitation = AdminInvitation::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if (! $invitation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired invitation token.',
                ], 404);
            }

            if ($invitation->isAccepted()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This invitation has already been accepted.',
                ], 409);
            }

            if ($invitation->isExpired()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This invitation has expired.',
                ], 410);
            }

            $role = Role::query()
                ->where('guard_name', 'web')
                ->where('name', $invitation->role_name)
                ->first();

            if (! $role) {
                return response()->json([
                    'success' => false,
                    'message' => "The assigned role '{$invitation->role_name}' is no longer available.",
                ], 422);
            }

            $user = User::query()->where('email', $invitation->email)->first();

            if (! $user) {
                $user = User::create([
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'] ?? null,
                    'email' => $invitation->email,
                    'phone_number' => $validated['phone_number'] ?? ('+000' . rand(1000000, 9999999)),
                    'password' => $validated['password'],
                    'status' => 'active',
                ]);
            } else {
                $user->update([
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'] ?? $user->last_name,
                    'password' => $validated['password'],
                    'status' => 'active',
                ]);
            }

            $user->assignRole($role);
            $invitation->update(['accepted_at' => now()]);

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->activityLogService->log(
                eventType: 'admin_invitation_accepted',
                category: 'admin_management',
                description: "Admin invitation accepted by [{$user->email}] with role [{$role->name}].",
                actor: $user,
                subjectType: User::class,
                subjectId: $user->id,
                metadata: [
                    'invitation_id' => $invitation->id,
                    'role' => $role->name,
                ],
                request: $request
            );

            return response()->json([
                'success' => true,
                'message' => 'Invitation accepted successfully. You can now log in.',
                'data' => new AdminUserResource($user->load('roles')),
            ]);
        });
    }

    /**
     * Show single admin user details.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizePermission($request, 'admin_users.read', 'users.view');

        $user = User::query()
            ->whereKey($id)
            ->whereHas('roles', fn ($q) => $q->where('guard_name', 'web'))
            ->with(['roles', 'adminSessions' => fn ($q) => $q->latest()->limit(5)])
            ->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => "Admin user with ID {$id} not found.",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Admin user retrieved successfully.',
            'data' => (new AdminUserResource($user))->additional([
                'recent_sessions' => $user->adminSessions,
            ]),
        ]);
    }

    /**
     * Update an admin user (name, phone, role assignment).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizePermission($request, 'admin_users.update', 'users.update');

        $user = User::query()
            ->whereKey($id)
            ->whereHas('roles', fn ($q) => $q->where('guard_name', 'web'))
            ->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => "Admin user with ID {$id} not found.",
            ], 404);
        }

        $validated = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('users', 'phone_number')->ignore($user->id)],
            'role_name' => ['sometimes', 'required', 'string', 'max:100'],
        ]);

        $actor = $request->user();
        $isPlatformOwner = $this->isPlatformOwner($actor);

        DB::beginTransaction();
        try {
            // Lock target user
            $targetUser = User::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if (isset($validated['role_name'])) {
                $targetRole = Role::query()
                    ->where('guard_name', 'web')
                    ->where(function ($q) use ($validated) {
                        $q->where('name', $validated['role_name'])
                            ->orWhere('slug', $validated['role_name']);
                    })
                    ->first();

                if (! $targetRole) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Role '{$validated['role_name']}' not found.",
                    ], 422);
                }

                // Protect last active platform owner from being stripped of owner role
                $isTargetCurrentOwner = $this->isPlatformOwner($targetUser);
                $isTargetNewRoleOwner = in_array($targetRole->name, ['platform_owner'], true) || in_array($targetRole->slug, ['platform_owner'], true);

                if ($isTargetCurrentOwner && ! $isTargetNewRoleOwner) {
                    $this->assertNotLastActiveOwner($targetUser);
                }

                if ($isTargetNewRoleOwner && ! $isPlatformOwner) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Only an existing platform owner can grant the platform_owner role.',
                    ], 403);
                }

                // Normal admins cannot assign a role with permissions they do not possess
                if (! $isPlatformOwner) {
                    $actorPermissionIds = $actor ? $actor->getAllPermissions()->pluck('id')->toArray() : [];
                    $rolePermissionIds = $targetRole->permissions()->pluck('id')->toArray();
                    $unauthorizedIds = array_diff($rolePermissionIds, $actorPermissionIds);

                    if (! empty($unauthorizedIds)) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => 'You cannot assign a role that possesses permissions you do not personally have.',
                        ], 403);
                    }
                }

                // Sync platform role without touching tenant memberships
                $targetUser->syncRoles([$targetRole]);
            }

            $userFields = array_intersect_key($validated, array_flip(['first_name', 'last_name', 'phone_number']));
            if (! empty($userFields)) {
                $targetUser->update($userFields);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->activityLogService->log(
                eventType: 'admin_user_updated',
                category: 'admin_management',
                description: "Updated admin user [{$targetUser->id}] {$targetUser->email}.",
                actor: $actor,
                subjectType: User::class,
                subjectId: $targetUser->id,
                metadata: [
                    'updated_fields' => array_keys($validated),
                ],
                request: $request
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Admin user updated successfully.',
                'data' => new AdminUserResource($targetUser->fresh()->load('roles')),
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Deactivate a platform administrator.
     * Revokes all Sanctum access tokens and blocks future access.
     */
    public function deactivate(Request $request, int $id): JsonResponse
    {
        $this->authorizePermission($request, 'admin_users.deactivate', 'users.deactivate');

        $actor = $request->user();

        return DB::transaction(function () use ($id, $actor, $request) {
            $user = User::query()->whereKey($id)->lockForUpdate()->first();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => "Admin user with ID {$id} not found.",
                ], 404);
            }

            // Protect last active platform owner
            if ($this->isPlatformOwner($user)) {
                $this->assertNotLastActiveOwner($user);
            }

            if ($user->status === 'inactive') {
                return response()->json([
                    'success' => false,
                    'message' => 'User is already deactivated.',
                ], 422);
            }

            $user->update(['status' => 'inactive']);

            // Revoke all personal access tokens and flush admin auth cache
            $user->tokens()->delete();
            Cache::forget('admin_auth_token_' . $user->id);

            // Invalidate active sessions
            if (method_exists($user, 'adminSessions')) {
                $user->adminSessions()->delete();
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->activityLogService->log(
                eventType: 'admin_user_deactivated',
                category: 'security',
                description: "Deactivated admin user [{$user->id}] {$user->email}.",
                actor: $actor,
                subjectType: User::class,
                subjectId: $user->id,
                metadata: [
                    'target_email' => $user->email,
                    'tokens_revoked' => true,
                ],
                request: $request
            );

            return response()->json([
                'success' => true,
                'message' => 'Admin user deactivated successfully and all active sessions were revoked.',
                'data' => new AdminUserResource($user->fresh()->load('roles')),
            ]);
        });
    }

    /**
     * Reactivate a deactivated platform administrator.
     */
    public function reactivate(Request $request, int $id): JsonResponse
    {
        $this->authorizePermission($request, 'admin_users.update', 'users.activate');

        $actor = $request->user();

        return DB::transaction(function () use ($id, $actor, $request) {
            $user = User::query()->whereKey($id)->lockForUpdate()->first();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => "Admin user with ID {$id} not found.",
                ], 404);
            }

            if ($user->status === 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'User is already active.',
                ], 422);
            }

            $user->update(['status' => 'active']);

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->activityLogService->log(
                eventType: 'admin_user_reactivated',
                category: 'security',
                description: "Reactivated admin user [{$user->id}] {$user->email}.",
                actor: $actor,
                subjectType: User::class,
                subjectId: $user->id,
                metadata: [
                    'target_email' => $user->email,
                ],
                request: $request
            );

            return response()->json([
                'success' => true,
                'message' => 'Admin user reactivated successfully.',
                'data' => new AdminUserResource($user->fresh()->load('roles')),
            ]);
        });
    }

    /**
     * Helper to check if a user is a designated platform owner.
     */
    protected function isPlatformOwner(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->hasRole('platform_owner')
            || $user->roles()->where('slug', 'platform_owner')->exists();
    }

    /**
     * Helper to verify permission on the acting user.
     */
    protected function authorizePermission(Request $request, string ...$permissions): void
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->status && $user->status !== 'active') {
            abort(403, 'Account is inactive. Access denied.');
        }

        if ($this->isPlatformOwner($user) || $user->hasRole('Super Admin') || $user->hasRole('super_admin')) {
            return;
        }

        foreach ($permissions as $permission) {
            try {
                if ($user->can($permission) || $user->hasPermissionTo($permission, 'web')) {
                    return;
                }
            } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
                // If permission does not exist in database, check next candidate
            }
        }

        abort(403, "You do not have the required permission: {$permissions[0]}.");
    }

    /**
     * Concurrency-safe safeguard: Verify that removing/deactivating this user will not leave 0 active platform owners.
     */
    protected function assertNotLastActiveOwner(User $user): void
    {
        $activeOwnerCount = User::query()
            ->where('status', 'active')
            ->whereHas('roles', function ($q) {
                $q->where('name', 'platform_owner')
                    ->orWhere('slug', 'platform_owner');
            })
            ->lockForUpdate()
            ->count();

        if ($activeOwnerCount <= 1) {
            throw ValidationException::withMessages([
                'user' => ['Cannot modify or deactivate the last active platform owner.'],
            ]);
        }
    }
}
