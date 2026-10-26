<?php

namespace Tests\Feature;

use App\Models\AdminInvitation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SuperAdminUsersAndRbacTest extends TestCase
{
    use RefreshDatabase;

    protected Role $platformOwnerRole;
    protected Role $superAdminRole;
    protected Role $adminRole;
    protected User $platformOwner;
    protected User $superAdmin;
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Ensure roles exist
        $this->platformOwnerRole = Role::firstOrCreate(
            ['name' => 'platform_owner', 'guard_name' => 'web'],
            ['slug' => 'platform_owner', 'is_default' => true]
        );

        $this->superAdminRole = Role::firstOrCreate(
            ['name' => 'Super Admin', 'guard_name' => 'web'],
            ['slug' => 'super_admin', 'is_default' => true]
        );

        $this->adminRole = Role::firstOrCreate(
            ['name' => 'Administrator', 'guard_name' => 'web'],
            ['slug' => 'administrator', 'is_default' => true]
        );

        // Ensure standard permissions exist
        $permissions = [
            'users.view', 'users.create', 'users.update', 'users.delete', 'users.activate', 'users.deactivate',
            'roles.view', 'roles.create', 'roles.update', 'roles.delete',
            'permissions.view', 'roles.permissions.view', 'roles.permissions.update',
        ];

        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'web');
        }

        $allPerms = Permission::where('guard_name', 'web')->get();
        $this->platformOwnerRole->syncPermissions($allPerms);
        $this->superAdminRole->syncPermissions($allPerms);

        // Create test platform owner
        $this->platformOwner = User::firstOrCreate(
            ['email' => 'owner@crm-platform.test'],
            [
                'first_name' => 'Platform',
                'last_name' => 'Owner',
                'phone_number' => '+1111222333',
                'password' => Hash::make('Secret123!'),
                'status' => 'active',
            ]
        );
        $this->platformOwner->syncRoles([$this->platformOwnerRole]);

        // Create test super admin
        $this->superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@crm-platform.test'],
            [
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'phone_number' => '+2222333444',
                'password' => Hash::make('Secret123!'),
                'status' => 'active',
            ]
        );
        $this->superAdmin->syncRoles([$this->superAdminRole]);

        // Create test admin with scoped permissions
        $this->adminUser = User::firstOrCreate(
            ['email' => 'scoped-admin@crm-platform.test'],
            [
                'first_name' => 'Scoped',
                'last_name' => 'Admin',
                'phone_number' => '+3333444555',
                'password' => Hash::make('Secret123!'),
                'status' => 'active',
            ]
        );
        $this->adminUser->syncRoles([$this->adminRole]);
        $this->adminUser->syncPermissions(['users.view']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * RBAC-001: Designated platform owner can access authorized platform endpoints and has all platform permissions.
     */
    public function test_rbac_001_platform_owner_has_full_platform_access(): void
    {
        Sanctum::actingAs($this->platformOwner, ['*']);

        $response = $this->getJson('/api/auth/admin/users');
        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertTrue($this->platformOwner->hasRole('platform_owner'));
        $this->assertTrue($this->platformOwner->can('users.create'));
        $this->assertTrue($this->platformOwner->can('roles.permissions.update'));
    }

    /**
     * RBAC-002: A read-only admin can read permitted resources but cannot mutate them.
     */
    public function test_rbac_002_read_only_admin_cannot_mutate_resources(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        // Can view users
        $res = $this->getJson('/api/auth/admin/users');
        $res->assertStatus(200);

        // Cannot invite / create users
        $resInvite = $this->postJson('/api/auth/admin/users/invite', [
            'email' => 'newuser@crm-test.test',
            'role_name' => 'Administrator',
        ]);
        $resInvite->assertStatus(403);
    }

    /**
     * RBAC-003: Unauthenticated and unauthorized direct API calls are rejected.
     */
    public function test_rbac_003_unauthenticated_and_unauthorized_calls_rejected(): void
    {
        $unauth = $this->getJson('/api/auth/admin/users');
        $unauth->assertStatus(401);

        $tenantUser = User::firstOrCreate(
            ['email' => 'tenant-only@example.test'],
            [
                'first_name' => 'Tenant',
                'last_name' => 'Only',
                'phone_number' => '+9999000111',
                'password' => Hash::make('Secret123!'),
                'status' => 'active',
            ]
        );
        // Has no platform roles

        Sanctum::actingAs($tenantUser, ['*']);
        $tenantRes = $this->getJson('/api/auth/admin/users');
        $tenantRes->assertStatus(403);
    }

    /**
     * RBAC-004: A user cannot grant permissions they do not possess.
     */
    public function test_rbac_004_user_cannot_grant_unpossessed_permissions(): void
    {
        // Admin user has only 'users.view'
        $this->adminUser->syncPermissions(['users.view', 'roles.permissions.update']);
        Sanctum::actingAs($this->adminUser, ['*']);

        $customRole = Role::firstOrCreate(
            ['name' => 'Custom Support', 'guard_name' => 'web'],
            ['slug' => 'custom_support', 'is_default' => false]
        );

        Permission::findOrCreate('tenants.delete', 'web');
        $allPermIds = Permission::whereIn('name', ['users.view', 'tenants.delete'])->pluck('id')->toArray();

        $res = $this->putJson("/api/auth/admin/roles/{$customRole->id}/permissions", [
            'permission_ids' => $allPermIds,
        ]);

        $res->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You cannot grant permissions that you do not personally possess.');
    }

    /**
     * RBAC-005: The last active platform owner cannot be deactivated, deleted, or stripped of owner membership.
     */
    public function test_rbac_005_last_active_platform_owner_cannot_be_removed_or_deactivated(): void
    {
        // Ensure only 1 active platform owner exists
        User::whereHas('roles', fn ($q) => $q->where('name', 'platform_owner'))
            ->where('id', '!=', $this->platformOwner->id)
            ->each(function ($u) {
                $u->removeRole('platform_owner');
            });

        Sanctum::actingAs($this->platformOwner, ['*']);

        // Attempt deactivating last owner
        $resDeactivate = $this->postJson("/api/auth/admin/users/{$this->platformOwner->id}/deactivate");
        $resDeactivate->assertStatus(422);

        // Attempt stripping platform_owner role
        $resRoleChange = $this->patchJson("/api/auth/admin/users/{$this->platformOwner->id}", [
            'role_name' => 'Administrator',
        ]);
        $resRoleChange->assertStatus(422);
    }

    /**
     * RBAC-006: Role/permission removal is enforced on the next protected request.
     */
    public function test_rbac_006_role_permission_removal_enforced_immediately(): void
    {
        $testAdmin = User::create([
            'first_name' => 'Temp',
            'last_name' => 'Admin',
            'email' => 'temp-admin@test.test',
            'phone_number' => '+8888777666',
            'password' => Hash::make('Secret123!'),
            'status' => 'active',
        ]);
        $testAdmin->assignRole($this->adminRole);
        $testAdmin->givePermissionTo('users.view');

        Sanctum::actingAs($testAdmin, ['*']);

        $res1 = $this->getJson('/api/auth/admin/users');
        $res1->assertStatus(200);

        // Strip permission
        $testAdmin->revokePermissionTo('users.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $res2 = $this->getJson('/api/auth/admin/users');
        $res2->assertStatus(403);
    }

    /**
     * Test secure invitation workflow (creation, hashing, expiration, single-use).
     */
    public function test_invitation_flow_with_secure_tokens(): void
    {
        Sanctum::actingAs($this->platformOwner, ['*']);

        $inviteRes = $this->postJson('/api/auth/admin/users/invite', [
            'email' => 'new-operator@crm-platform.test',
            'role_name' => 'Administrator',
        ]);

        $inviteRes->assertStatus(201)
            ->assertJsonPath('success', true);

        $rawToken = $inviteRes->json('data.invitation_token');
        $this->assertNotEmpty($rawToken);

        // Database only has hashed token
        $invitation = AdminInvitation::where('email', 'new-operator@crm-platform.test')->first();
        $this->assertNotNull($invitation);
        $this->assertEquals(hash('sha256', $rawToken), $invitation->token_hash);
        $this->assertNotEquals($rawToken, $invitation->token_hash);

        // Accept invitation publicly
        $acceptRes = $this->postJson('/api/auth/admin/users/accept-invitation', [
            'token' => $rawToken,
            'first_name' => 'New',
            'last_name' => 'Operator',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $acceptRes->assertStatus(200)
            ->assertJsonPath('success', true);

        $acceptedUser = User::where('email', 'new-operator@crm-platform.test')->first();
        $this->assertNotNull($acceptedUser);
        $this->assertTrue($acceptedUser->hasRole('Administrator'));

        // Replay prevention: cannot accept twice
        $replayRes = $this->postJson('/api/auth/admin/users/accept-invitation', [
            'token' => $rawToken,
            'first_name' => 'New',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);
        $replayRes->assertStatus(409);
    }

    /**
     * Test deactivation revokes tokens and blocks login.
     */
    public function test_deactivation_blocks_login_and_revokes_tokens(): void
    {
        Sanctum::actingAs($this->platformOwner, ['*']);

        $operator = User::create([
            'first_name' => 'Active',
            'last_name' => 'Operator',
            'email' => 'active-op@test.test',
            'phone_number' => '+7777888999',
            'password' => Hash::make('Secret123!'),
            'status' => 'active',
        ]);
        $operator->assignRole($this->adminRole);

        $token = $operator->createToken('admin-auth-token')->plainTextToken;
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $operator->id]);

        // Deactivate operator
        $deactivateRes = $this->postJson("/api/auth/admin/users/{$operator->id}/deactivate");
        $deactivateRes->assertStatus(200);

        // Tokens revoked
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $operator->id]);

        // Login blocked
        $loginRes = $this->postJson('/api/auth/admin/login', [
            'email' => 'active-op@test.test',
            'password' => 'Secret123!',
        ]);
        $loginRes->assertStatus(403)
            ->assertJsonPath('message', 'This account has been deactivated. Please contact an administrator.');

        // Reactivate
        $reactivateRes = $this->postJson("/api/auth/admin/users/{$operator->id}/reactivate");
        $reactivateRes->assertStatus(200);

        $operator->refresh();
        $this->assertEquals('active', $operator->status);
    }

    /**
     * Test role protection for platform_owner and Super Admin.
     */
    public function test_protected_roles_cannot_be_renamed_or_stripped_of_permissions(): void
    {
        Sanctum::actingAs($this->platformOwner, ['*']);

        // Cannot strip permissions from platform_owner
        $permRes = $this->putJson("/api/auth/admin/roles/{$this->platformOwnerRole->id}/permissions", [
            'permission_ids' => [],
        ]);
        $permRes->assertStatus(403);

        // Cannot rename default role
        $renameRes = $this->patchJson("/api/auth/admin/roles/{$this->platformOwnerRole->id}", [
            'name' => 'Hacked Owner',
        ]);
        $renameRes->assertStatus(422);

        // Cannot delete default role
        $delRes = $this->deleteJson("/api/auth/admin/roles/{$this->platformOwnerRole->id}");
        $delRes->assertStatus(403);
    }
}
