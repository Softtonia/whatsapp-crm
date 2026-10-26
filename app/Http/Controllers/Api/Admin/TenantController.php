<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Tenant\AddTenantMemberRequest;
use App\Http\Requests\Admin\Tenant\StoreTenantRequest;
use App\Http\Requests\Admin\Tenant\UpdateTenantMemberRequest;
use App\Http\Requests\Admin\Tenant\UpdateTenantRequest;
use App\Http\Resources\Admin\TenantResource;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Models\TenantActivityLog;
use App\Models\TenantStatusHistory;

class TenantController extends Controller
{
    /**
     * List tenants with search, status filter, counts, and pagination.
     */
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $user = $request->user();

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', 'nullable', Rule::in(['active', 'inactive', 'suspended', 'archived'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Tenant::query()
            ->withCount(['users as members_count']);

        // Check if user is Super Admin or standard tenant user
        $isSuperAdmin = $user && ($user->hasRole('Super Admin') || $user->hasRole('super_admin') || $user->roles()->where('slug', 'super_admin')->exists());

        if (! $isSuperAdmin && $user) {
            // Scope query to tenants that the user belongs to
            $query->whereHas('memberships', function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->where('status', 'active');
            });
        }

        $query->when($filters['search'] ?? null, function ($q, string $search): void {
            $q->where(function ($builder) use ($search): void {
                $builder->where('company_name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        })
            ->when($filters['status'] ?? null, fn($q, string $status) => $q->where('status', $status))
            ->orderByDesc('id');

        $tenants = $query->paginate($filters['per_page'] ?? 15);

        return TenantResource::collection($tenants);
    }

    /**
     * Create a tenant. Slug is generated from name when not supplied.
     */
    public function store(StoreTenantRequest $request): JsonResponse
    {
        $user = $request->user();
        if ($user && ! $user->hasRole('platform_owner') && ! $user->hasRole('Super Admin') && ! $user->hasRole('super_admin')) {
            if (! $user->can('tenants.create') && ! $user->hasPermissionTo('tenants.create', 'web')) {
                abort(403, 'Forbidden. Required permission: tenants.create.');
            }
        }
        $data = $request->validated();

        $companyName = $data['company_name'] ?? $data['name'] ?? '';
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? $companyName);
        $data['status'] = $data['status'] ?? 'active';

        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('tenant-logos', 'public');
        }

        $tenant = DB::transaction(function () use ($data, $companyName, $request) {
            $created = Tenant::create([
                'company_name' => $companyName,
                'slug' => $data['slug'],
                'status' => $data['status'],
                'suspend_reason' => $data['suspend_reason'] ?? null,
                'logo' => $data['logo'] ?? null,
                'contact_number' => $data['contact_number'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'country' => $data['country'] ?? null,
                'currency' => $data['currency'] ?? 'INR',
                'timezone' => $data['timezone'] ?? 'Asia/Kolkata',
            ]);

            $user = $request->user();

            $isSuperAdmin = $user && (
                $user->hasRole('Super Admin')
                || $user->hasRole('super_admin')
                || $user->roles()->where('slug', 'super_admin')->exists()
            );

            $ownerUserId = $data['owner_user_id'] ?? null;

            // Explicitly selected owner takes priority.
            // Otherwise, preserve automatic ownership for non-super-admin creators.
            if ($ownerUserId || ($user && ! $isSuperAdmin)) {
                $created->memberships()->create([
                    'user_id' => $ownerUserId ?? $user->id,
                    'role' => 'owner',
                    'status' => 'active',
                ]);
            }

            return $created;
        });

        $tenant->loadCount(['users as members_count', 'memberships as memberships_count']);
        $tenant->load(['memberships.user:id,first_name,last_name,email,phone_number', 'whatsappAccounts']);

        return response()->json([
            'message' => 'Business created successfully.',
            'data' => new TenantResource($tenant),
        ], 201);
    }
    /**
     * Assign ownership to an existing active tenant member.
     */
    public function transferOwner(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'owner_user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
        ]);

        $newOwner = $tenant->memberships()
            ->where('user_id', $data['owner_user_id'])
            ->where('status', 'active')
            ->first();

        if (! $newOwner) {
            return response()->json([
                'message' => 'The new owner must be an active member of this tenant.',
                'errors' => [
                    'owner_user_id' => [
                        'Add and activate the user as a tenant member first.',
                    ],
                ],
            ], 422);
        }

        DB::transaction(function () use ($tenant, $newOwner) {
            // Lock membership rows to avoid concurrent ownership transfers.
            $memberships = $tenant->memberships()
                ->lockForUpdate()
                ->get();

            // Demote the current owner(s).
            $tenant->memberships()
                ->where('role', 'owner')
                ->update(['role' => 'admin']);

            // Promote the selected active member.
            $newOwner->role = 'owner';
            $newOwner->status = 'active';
            $newOwner->save();
        });

        return response()->json([
            'message' => 'Tenant owner transferred successfully.',
            'data' => [
                'tenant_id' => $tenant->id,
                'owner_user_id' => $newOwner->user_id,
                'role' => 'owner',
                'status' => 'active',
            ],
        ]);
    }
    /**
     * Show one tenant and its WhatsApp accounts, team members, and message usage summary.
     */
    public function show(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeTenantAccess($request->user(), $tenant);

        $tenant->loadCount(['users as members_count', 'memberships as memberships_count']);
        $tenant->load([
            'memberships.user:id,first_name,last_name,email,phone_number',
            'whatsappAccounts',
        ]);

        return response()->json([
            'data' => new TenantResource($tenant),
        ]);
    }

    /**
     * Update tenant details without changing its ID or deleting related data.
     */
    public function update(UpdateTenantRequest $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeTenantManage($request->user(), $tenant);
        $user = $request->user();
        if ($user && ! $user->hasRole('platform_owner') && ! $user->hasRole('Super Admin') && ! $user->hasRole('super_admin')) {
            if (! $user->can('tenants.update') && ! $user->hasPermissionTo('tenants.update', 'web')) {
                abort(403, 'Forbidden. Required permission: tenants.update.');
            }
        }

        $data = $request->validated();

        if ($request->hasFile('logo')) {
            $oldLogo = $tenant->logo;
            $data['logo'] = $request->file('logo')->store('tenant-logos', 'public');
            if ($oldLogo) {
                Storage::disk('public')->delete($oldLogo);
            }
        }

        $tenant->fill($data)->save();

        $tenant->loadCount(['users as members_count', 'memberships as memberships_count']);
        $tenant->load(['memberships.user:id,first_name,last_name,email,phone_number', 'whatsappAccounts']);

        return response()->json([
            'message' => 'Business updated successfully.',
            'data' => new TenantResource($tenant->fresh()),
        ]);
    }

    /**
     * Activate a tenant.
     */
    public function activate(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeTenantManage($request->user(), $tenant);
        $user = $request->user();
        if ($user && ! $user->hasRole('platform_owner') && ! $user->hasRole('Super Admin') && ! $user->hasRole('super_admin')) {
            if (! $user->can('tenants.reactivate') && ! $user->can('tenants.activate') && ! $user->hasPermissionTo('tenants.reactivate', 'web') && ! $user->hasPermissionTo('tenants.activate', 'web')) {
                abort(403, 'Forbidden. Required permission: tenants.reactivate.');
            }
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $updated = $this->changeTenantStatus(
            $request,
            $tenant,
            'active',
            $validated['reason'],
            'tenant.activated',
            ['suspend_reason' => null]
        );

        return response()->json([
            'success' => true,
            'message' => 'Business activated successfully.',
            'data' => new TenantResource($updated),
        ]);
    }

    public function deactivate(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeTenantManage($request->user(), $tenant);
        $user = $request->user();
        if ($user && ! $user->hasRole('platform_owner') && ! $user->hasRole('Super Admin') && ! $user->hasRole('super_admin')) {
            if (! $user->can('tenants.deactivate') && ! $user->can('tenants.suspend') && ! $user->hasPermissionTo('tenants.deactivate', 'web') && ! $user->hasPermissionTo('tenants.suspend', 'web')) {
                abort(403, 'Forbidden. Required permission: tenants.deactivate.');
            }
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $updated = $this->changeTenantStatus(
            $request,
            $tenant,
            'inactive',
            $validated['reason'],
            'tenant.deactivated'
        );

        return response()->json([
            'success' => true,
            'message' => 'Business deactivated successfully.',
            'data' => new TenantResource($updated),
        ]);
    }

    /**
     * Suspend a tenant.
     */
    public function suspend(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeTenantManage($request->user(), $tenant);
        $user = $request->user();
        if ($user && ! $user->hasRole('platform_owner') && ! $user->hasRole('Super Admin') && ! $user->hasRole('super_admin')) {
            if (! $user->can('tenants.suspend') && ! $user->hasPermissionTo('tenants.suspend', 'web')) {
                abort(403, 'Forbidden. Required permission: tenants.suspend.');
            }
        }
        // Backward compatibility for clients using the older field name.
        if (! $request->filled('reason') && $request->filled('suspend_reason')) {
            $request->merge([
                'reason' => $request->input('suspend_reason'),
            ]);
        }
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'effective_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $effectiveAt = ! empty($validated['effective_at'])
            ? \Illuminate\Support\Carbon::parse($validated['effective_at'])
            : now();

        if ($effectiveAt->isFuture()) {
            return response()->json([
                'success' => false,
                'message' => 'Future-dated suspension is not supported yet.',
            ], 422);
        }

        $reason = $validated['reason'];

        $updated = $this->changeTenantStatus(
            $request,
            $tenant,
            'suspended',
            $reason,
            'tenant.suspended',
            ['suspend_reason' => $reason]
        );

        return response()->json([
            'success' => true,
            'message' => 'Business suspended successfully.',
            'data' => new TenantResource($updated),
        ]);
    }

    /**
     * Archive a tenant.
     */
    public function archive(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeTenantManage($request->user(), $tenant);

        $updated = $this->changeTenantStatus(
            $request,
            $tenant,
            'archived',
            null,
            'tenant.archived'
        );

        return response()->json([
            'message' => 'Business archived successfully.',
            'data' => new TenantResource($updated),
        ]);
    }

    /**
     * List team members for a tenant.
     */
    public function members(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeTenantAccess($request->user(), $tenant);

        $members = $tenant->memberships()
            ->with('user:id,first_name,last_name,email,phone_number')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $members->map(function ($membership) {
                return [
                    'id' => $membership->id,
                    'tenant_id' => $membership->tenant_id,
                    'business_id' => $membership->tenant_id,
                    'user_id' => $membership->user_id,
                    'role' => $membership->role,
                    'status' => $membership->status,
                    'user' => $membership->user ? [
                        'id' => $membership->user->id,
                        'first_name' => $membership->user->first_name,
                        'last_name' => $membership->user->last_name,
                        'full_name' => $membership->user->full_name,
                        'email' => $membership->user->email,
                        'phone_number' => $membership->user->phone_number,
                    ] : null,
                    'created_at' => $membership->created_at?->toISOString(),
                    'updated_at' => $membership->updated_at?->toISOString(),
                ];
            }),
        ]);
    }

    /**
     * Add a team member to a tenant.
     */
    public function addMember(AddTenantMemberRequest $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeTenantManage($request->user(), $tenant);

        $data = $request->validated();

        $existing = $tenant->memberships()->where('user_id', $data['user_id'])->first();
        if ($existing) {
            return response()->json([
                'message' => 'User is already a member of this business.',
            ], 422);
        }

        try {
            app(\App\Services\SubscriptionUsageService::class)->assertCanUse(
                $tenant->id,
                'team_members',
                1
            );
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $membership = $tenant->memberships()->create([
            'user_id' => $data['user_id'],
            'role' => $data['role'] ?? 'agent',
            'status' => $data['status'] ?? 'active',
        ]);

        $membership->load('user:id,first_name,last_name,email,phone_number');

        return response()->json([
            'message' => 'Member added successfully.',
            'data' => $membership,
        ], 201);
    }

    /**
     * Update a team member's role or status.
     */
    public function updateMember(UpdateTenantMemberRequest $request, Tenant $tenant, TenantUser $member): JsonResponse
    {
        $this->authorizeTenantManage($request->user(), $tenant);

        if ($member->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Member does not belong to this business.'], 404);
        }

        $data = $request->validated();
        $member->fill($data)->save();

        return response()->json([
            'message' => 'Member updated successfully.',
            'data' => $member->fresh()->load('user:id,first_name,last_name,email,phone_number'),
        ]);
    }

    /**
     * Remove a team member from a tenant.
     */
    public function removeMember(Request $request, Tenant $tenant, TenantUser $member): JsonResponse
    {
        $this->authorizeTenantManage($request->user(), $tenant);

        if ($member->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Member does not belong to this business.'], 404);
        }

        $member->delete();

        return response()->json([
            'message' => 'Member removed successfully.',
        ]);
    }

    /**
     * Delete only tenants that have no dependent records across all CRM modules.
     */
    public function destroy(Request $request, Tenant $tenant): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = $user && ($user->hasRole('Super Admin') || $user->hasRole('super_admin') || $user->roles()->where('slug', 'super_admin')->exists());

        if (! $isSuperAdmin) {
            return response()->json([
                'message' => 'Forbidden. Only Super Admin can delete a tenant account.',
            ], 403);
        }

        $hasAccounts = Schema::hasTable('tenant_whats_app_accounts')
            && DB::table('tenant_whats_app_accounts')->where('tenant_id', $tenant->id)->exists();
        $hasConversations = Schema::hasTable('conversations')
            && DB::table('conversations')->where('tenant_id', $tenant->id)->exists();
        $hasMembers = Schema::hasTable('tenant_users')
            && DB::table('tenant_users')->where('tenant_id', $tenant->id)->exists();
        $hasContacts = Schema::hasTable('contacts')
            && DB::table('contacts')->where('tenant_id', $tenant->id)->exists();
        $hasLeads = Schema::hasTable('leads')
            && DB::table('leads')->where('tenant_id', $tenant->id)->exists();
        $hasProducts = Schema::hasTable('products')
            && DB::table('products')->where('tenant_id', $tenant->id)->exists();
        $hasKnowledge = Schema::hasTable('tenant_knowledge')
            && DB::table('tenant_knowledge')->where('tenant_id', $tenant->id)->exists();

        if ($hasAccounts || $hasConversations || $hasMembers || $hasContacts || $hasLeads || $hasProducts || $hasKnowledge) {
            return response()->json([
                'message' => 'Business cannot be deleted while dependent records (WhatsApp accounts, conversations, team members, contacts, leads, products, or knowledge base) exist. Deactivate or suspend it instead.',
            ], 409);
        }

        $tenant->delete();

        return response()->json(['message' => 'Business deleted successfully.']);
    }

    /**
     * Authorize that user can view tenant.
     */
    private function authorizeTenantAccess(?User $user, Tenant $tenant): void
    {
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->hasRole('Super Admin') || $user->hasRole('super_admin') || $user->roles()->where('slug', 'super_admin')->exists()) {
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

    /**
     * Authorize that user can manage (update/edit) tenant.
     */
    private function authorizeTenantManage(?User $user, Tenant $tenant): void
    {
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->hasRole('Super Admin') || $user->hasRole('super_admin') || $user->roles()->where('slug', 'super_admin')->exists()) {
            return;
        }

        $isOwner = $tenant->memberships()
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->where('status', 'active')
            ->exists();

        if (! $isOwner) {
            abort(403, 'You are not authorized to update this tenant profile.');
        }
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source);
        if ($base === '') {
            $base = 'tenant';
        }

        $slug = $base;
        $suffix = 2;

        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }


    private function changeTenantStatus(
        Request $request,
        Tenant $tenant,
        string $newStatus,
        ?string $reason,
        string $eventType,
        array $extraAttributes = []
    ): Tenant {
        return DB::transaction(function () use (
            $request,
            $tenant,
            $newStatus,
            $reason,
            $eventType,
            $extraAttributes
        ): Tenant {
            $lockedTenant = Tenant::query()
                ->whereKey($tenant->id)
                ->lockForUpdate()
                ->firstOrFail();

            $previousStatus = $lockedTenant->status;

            $oldValues = [
                'status' => $previousStatus,
                'suspend_reason' => $lockedTenant->suspend_reason,
                'archived_at' => $lockedTenant->archived_at?->toISOString(),
            ];

            $attributes = array_merge(
                $extraAttributes,
                ['status' => $newStatus]
            );

            if ($newStatus === 'archived') {
                $attributes['archived_at'] =
                    $lockedTenant->archived_at ?? now();
            } elseif (in_array(
                $newStatus,
                ['active', 'inactive', 'suspended'],
                true
            )) {
                $attributes['archived_at'] = null;
            }

            $lockedTenant->fill($attributes);
            $lockedTenant->save();

            $newValues = [
                'status' => $lockedTenant->status,
                'suspend_reason' => $lockedTenant->suspend_reason,
                'archived_at' => $lockedTenant->archived_at?->toISOString(),
            ];

            if ($previousStatus !== $newStatus) {
                TenantStatusHistory::create([
                    'tenant_id' => $lockedTenant->id,
                    'previous_status' => $previousStatus,
                    'new_status' => $newStatus,
                    'reason' => $reason,
                    'performed_by' => $request->user()?->id,
                    'effective_at' => now(),
                    'metadata' => [
                        'source' => 'admin_api',
                        'event_type' => $eventType,
                    ],
                ]);
            }

            TenantActivityLog::create([
                'tenant_id' => $lockedTenant->id,
                'actor_user_id' => $request->user()?->id,
                'actor_type' => 'user',
                'event_type' => $eventType,
                'description' => "Tenant {$lockedTenant->id} lifecycle action: {$eventType}",
                'subject_type' => Tenant::class,
                'subject_id' => $lockedTenant->id,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            app(\App\Services\ActivityLogService::class)->log(
                eventType: $eventType,
                category: 'tenants',
                description: "Tenant [{$lockedTenant->id}] {$lockedTenant->company_name} status changed to {$newStatus}.",
                actor: $request->user(),
                subjectType: Tenant::class,
                subjectId: $lockedTenant->id,
                metadata: [
                    'tenant_id' => $lockedTenant->id,
                    'company_name' => $lockedTenant->company_name,
                    'previous_status' => $previousStatus,
                    'new_status' => $newStatus,
                    'reason' => $reason,
                ],
                request: $request,
                tenantId: $lockedTenant->id,
                status: 'success',
                oldValues: $oldValues,
                newValues: $newValues
            );

            return $lockedTenant->fresh();
        });
    }
}
