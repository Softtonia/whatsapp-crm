<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RbacMatrixUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected User $platformOwner;
    protected User $platformAdmin;
    protected User $billingAdmin;
    protected User $supportOperator;
    protected User $auditor;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Run seeders
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        // Create test users for each matrix role
        $this->platformOwner = User::factory()->create(['status' => 'active']);
        $this->platformOwner->assignRole('platform_owner');

        $this->platformAdmin = User::factory()->create(['status' => 'active']);
        $this->platformAdmin->assignRole('Platform Admin');

        $this->billingAdmin = User::factory()->create(['status' => 'active']);
        $this->billingAdmin->assignRole('Billing Admin');

        $this->supportOperator = User::factory()->create(['status' => 'active']);
        $this->supportOperator->assignRole('Support Operator');

        $this->auditor = User::factory()->create(['status' => 'active']);
        $this->auditor->assignRole('Auditor');
    }

    /**
     * Test 1: Each built-in role receives the intended permissions per matrix.
     */
    public function test_built_in_roles_have_precise_permissions(): void
    {
        // 1. Platform Owner has all permissions
        $allPermissions = Permission::where('guard_name', 'web')->pluck('name')->toArray();
        $ownerRole = Role::where('name', 'platform_owner')->first();
        $this->assertCount(count($allPermissions), $ownerRole->permissions);

        // 2. Platform Admin
        $platformAdminRole = Role::where('name', 'Platform Admin')->first();
        $this->assertTrue($platformAdminRole->hasPermissionTo('dashboard.read'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('tenants.read'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('tenants.create'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('tenants.update'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('tenants.suspend'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('tenants.reactivate'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('tenants.provision'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('plans.read'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('plans.create'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('plans.update'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('plans.archive'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('subscriptions.read'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('subscriptions.assign'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('subscriptions.update'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('subscriptions.cancel'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('billing.read'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('branding.read'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('branding.update'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('domains.read'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('domains.manage'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('whatsapp.read'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('whatsapp.sync'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('whatsapp.webhooks.read'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('whatsapp.webhooks.retry'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('admin_users.read'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('admin_users.create'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('admin_users.update'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('admin_users.deactivate'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('roles.read'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('audit.read'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('security.read'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('settings.read'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('settings.manage'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('integrations.read'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('integrations.manage'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('usage.read'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('monitoring.read'));
        $this->assertTrue($platformAdminRole->hasPermissionTo('monitoring.retry'));

        // Excluded from Platform Admin:
        $this->assertFalse($platformAdminRole->hasPermissionTo('roles.manage'));
        $this->assertFalse($platformAdminRole->hasPermissionTo('security.manage'));

        // 3. Billing Admin
        $billingAdminRole = Role::where('name', 'Billing Admin')->first();
        $this->assertTrue($billingAdminRole->hasPermissionTo('dashboard.read'));
        $this->assertTrue($billingAdminRole->hasPermissionTo('tenants.read'));
        $this->assertTrue($billingAdminRole->hasPermissionTo('plans.read'));
        $this->assertTrue($billingAdminRole->hasPermissionTo('plans.create'));
        $this->assertTrue($billingAdminRole->hasPermissionTo('plans.update'));
        $this->assertTrue($billingAdminRole->hasPermissionTo('plans.archive'));
        $this->assertTrue($billingAdminRole->hasPermissionTo('subscriptions.read'));
        $this->assertTrue($billingAdminRole->hasPermissionTo('subscriptions.assign'));
        $this->assertTrue($billingAdminRole->hasPermissionTo('subscriptions.update'));
        $this->assertTrue($billingAdminRole->hasPermissionTo('subscriptions.cancel'));
        $this->assertTrue($billingAdminRole->hasPermissionTo('billing.read'));
        $this->assertTrue($billingAdminRole->hasPermissionTo('usage.read'));

        // Excluded from Billing Admin:
        $this->assertFalse($billingAdminRole->hasPermissionTo('tenants.create'));
        $this->assertFalse($billingAdminRole->hasPermissionTo('tenants.suspend'));
        $this->assertFalse($billingAdminRole->hasPermissionTo('whatsapp.read'));
        $this->assertFalse($billingAdminRole->hasPermissionTo('monitoring.read'));
        $this->assertFalse($billingAdminRole->hasPermissionTo('monitoring.retry'));

        // 4. Support Operator
        $supportOperatorRole = Role::where('name', 'Support Operator')->first();
        $this->assertTrue($supportOperatorRole->hasPermissionTo('dashboard.read'));
        $this->assertTrue($supportOperatorRole->hasPermissionTo('tenants.read'));
        $this->assertTrue($supportOperatorRole->hasPermissionTo('plans.read'));
        $this->assertTrue($supportOperatorRole->hasPermissionTo('subscriptions.read'));
        $this->assertTrue($supportOperatorRole->hasPermissionTo('branding.read'));
        $this->assertTrue($supportOperatorRole->hasPermissionTo('domains.read'));
        $this->assertTrue($supportOperatorRole->hasPermissionTo('whatsapp.read'));
        $this->assertTrue($supportOperatorRole->hasPermissionTo('whatsapp.webhooks.read'));
        $this->assertTrue($supportOperatorRole->hasPermissionTo('integrations.read'));
        $this->assertTrue($supportOperatorRole->hasPermissionTo('usage.read'));
        $this->assertTrue($supportOperatorRole->hasPermissionTo('monitoring.read'));

        // Excluded mutations from Support Operator:
        $this->assertFalse($supportOperatorRole->hasPermissionTo('tenants.create'));
        $this->assertFalse($supportOperatorRole->hasPermissionTo('tenants.suspend'));
        $this->assertFalse($supportOperatorRole->hasPermissionTo('plans.create'));
        $this->assertFalse($supportOperatorRole->hasPermissionTo('subscriptions.assign'));
        $this->assertFalse($supportOperatorRole->hasPermissionTo('whatsapp.sync'));
        $this->assertFalse($supportOperatorRole->hasPermissionTo('whatsapp.webhooks.retry'));
        $this->assertFalse($supportOperatorRole->hasPermissionTo('monitoring.retry'));

        // 5. Auditor
        $auditorRole = Role::where('name', 'Auditor')->first();
        $this->assertTrue($auditorRole->hasPermissionTo('dashboard.read'));
        $this->assertTrue($auditorRole->hasPermissionTo('tenants.read'));
        $this->assertTrue($auditorRole->hasPermissionTo('plans.read'));
        $this->assertTrue($auditorRole->hasPermissionTo('subscriptions.read'));
        $this->assertTrue($auditorRole->hasPermissionTo('billing.read'));
        $this->assertTrue($auditorRole->hasPermissionTo('whatsapp.read'));
        $this->assertTrue($auditorRole->hasPermissionTo('whatsapp.webhooks.read'));
        $this->assertTrue($auditorRole->hasPermissionTo('roles.read'));
        $this->assertTrue($auditorRole->hasPermissionTo('audit.read'));
        $this->assertTrue($auditorRole->hasPermissionTo('usage.read'));
        $this->assertTrue($auditorRole->hasPermissionTo('monitoring.read'));

        // Excluded mutations from Auditor:
        $this->assertFalse($auditorRole->hasPermissionTo('tenants.create'));
        $this->assertFalse($auditorRole->hasPermissionTo('plans.create'));
        $this->assertFalse($auditorRole->hasPermissionTo('subscriptions.assign'));
        $this->assertFalse($auditorRole->hasPermissionTo('roles.manage'));
        $this->assertFalse($auditorRole->hasPermissionTo('security.manage'));
        $this->assertFalse($auditorRole->hasPermissionTo('monitoring.retry'));
    }

    /**
     * Test 2: Platform Admin cannot manage roles or security settings.
     */
    public function test_platform_admin_cannot_manage_roles_or_security(): void
    {
        // 1. Attempting to create a role with Platform Admin must be rejected (403)
        $roleResponse = $this->actingAs($this->platformAdmin, 'sanctum')
            ->postJson('/api/auth/admin/roles', [
                'name' => 'Custom Rogue Role',
                'slug' => 'custom_rogue_role',
            ]);
        $roleResponse->assertStatus(403);

        // 2. Attempting to update security settings with Platform Admin must be rejected (403)
        $securityResponse = $this->actingAs($this->platformAdmin, 'sanctum')
            ->patchJson('/api/auth/admin/settings/security', [
                'session_lifetime_minutes' => 60,
            ]);
        $securityResponse->assertStatus(403);

        // 3. Platform Admin can read roles (200)
        $listRoles = $this->actingAs($this->platformAdmin, 'sanctum')
            ->getJson('/api/auth/admin/roles');
        $listRoles->assertStatus(200);

        // 4. Platform Admin can read security settings (200)
        $getSecurity = $this->actingAs($this->platformAdmin, 'sanctum')
            ->getJson('/api/auth/admin/settings/security');
        $getSecurity->assertStatus(200);
    }

    /**
     * Test 3: Billing Admin cannot mutate tenants or retry monitoring jobs.
     */
    public function test_billing_admin_cannot_mutate_tenants_or_retry_monitoring(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Acme Corporation',
            'slug' => 'acme-corp-' . uniqid(),
            'status' => 'active',
        ]);

        // Cannot suspend tenant (403)
        $suspendResponse = $this->actingAs($this->billingAdmin, 'sanctum')
            ->postJson("/api/auth/admin/tenants/{$tenant->id}/suspend", [
                'reason' => 'Non-payment of subscription',
            ]);
        $suspendResponse->assertStatus(403);

        // Cannot retry monitoring jobs (403)
        $retryResponse = $this->actingAs($this->billingAdmin, 'sanctum')
            ->postJson('/api/auth/admin/monitoring/jobs/1/retry');
        $retryResponse->assertStatus(403);

        // But CAN view plans and subscription billing
        $plansResponse = $this->actingAs($this->billingAdmin, 'sanctum')
            ->getJson('/api/auth/admin/subscriptions/plans');
        $plansResponse->assertStatus(200);

        $invoicesResponse = $this->actingAs($this->billingAdmin, 'sanctum')
            ->getJson('/api/auth/admin/invoices');
        $invoicesResponse->assertStatus(200);
    }

    /**
     * Test 4: Support Operator and Auditor cannot perform restricted mutations.
     */
    public function test_support_operator_and_auditor_restricted_from_mutations(): void
    {
        // Support Operator cannot create plans (403)
        $createPlan = $this->actingAs($this->supportOperator, 'sanctum')
            ->postJson('/api/auth/admin/subscriptions/plans', [
                'name' => 'Unauthorized Plan',
                'price' => 999,
                'billing_cycle' => 'monthly',
            ]);
        $createPlan->assertStatus(403);

        // Support Operator cannot retry webhook events (403)
        $retryWebhook = $this->actingAs($this->supportOperator, 'sanctum')
            ->postJson('/api/auth/admin/whatsapp/webhooks/1/retry');
        $retryWebhook->assertStatus(403);

        // Auditor cannot invite admin users (403)
        $inviteAdmin = $this->actingAs($this->auditor, 'sanctum')
            ->postJson('/api/auth/admin/users/invite', [
                'email' => 'hacked@example.com',
                'role_name' => 'Auditor',
            ]);
        $inviteAdmin->assertStatus(403);

        // Auditor cannot retry monitoring background jobs (403)
        $retryJob = $this->actingAs($this->auditor, 'sanctum')
            ->postJson('/api/auth/admin/monitoring/jobs/1/retry');
        $retryJob->assertStatus(403);
    }

    /**
     * Test 5: Platform Owner retains full unrestricted access.
     */
    public function test_platform_owner_retains_full_access(): void
    {
        // Platform owner can access security settings
        $security = $this->actingAs($this->platformOwner, 'sanctum')
            ->getJson('/api/auth/admin/settings/security');
        $security->assertStatus(200);

        // Platform owner can read monitoring health
        $health = $this->actingAs($this->platformOwner, 'sanctum')
            ->getJson('/api/auth/admin/monitoring/health');
        $health->assertStatus(200);

        // Platform owner can read roles
        $roles = $this->actingAs($this->platformOwner, 'sanctum')
            ->getJson('/api/auth/admin/roles');
        $roles->assertStatus(200);
    }

    /**
     * Test 6: Seeder idempotency - re-running seeders does not duplicate roles or permissions.
     */
    public function test_seeder_is_strictly_idempotent(): void
    {
        $roleCountBefore = Role::count();
        $permCountBefore = Permission::count();

        // Run seeders again
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->assertEquals($roleCountBefore, Role::count());
        $this->assertEquals($permCountBefore, Permission::count());
    }

    /**
     * Test 7: Custom roles and unrelated permissions are preserved when seeder is run.
     */
    public function test_custom_roles_and_permissions_are_preserved(): void
    {
        $customPermission = Permission::create(['name' => 'custom.special_op', 'guard_name' => 'web']);
        $customRole = Role::create([
            'name' => 'Custom Partner',
            'slug' => 'custom_partner',
            'guard_name' => 'web',
            'is_default' => false,
        ]);
        $customRole->givePermissionTo($customPermission);

        // Re-run seeders
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->assertDatabaseHas('roles', ['name' => 'Custom Partner']);
        $this->assertDatabaseHas('permissions', ['name' => 'custom.special_op']);
        $this->assertTrue($customRole->fresh()->hasPermissionTo('custom.special_op'));
    }
}
