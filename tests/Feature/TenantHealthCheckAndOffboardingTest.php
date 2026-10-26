<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantHealthCheck;
use App\Models\TenantOffboardingJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantHealthCheckAndOffboardingTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Super Admin', 'web');

        $this->superAdmin = User::factory()->create([
            'email' => 'health-admin@example.com',
            'password' => Hash::make('SuperSecret123!'),
        ]);

        $this->superAdmin->assignRole('Super Admin');
    }

    public function test_super_admin_can_record_and_list_health_checks(): void
    {
        $tenant = Tenant::create([
            'name' => 'Health Test Tenant',
            'slug' => 'health-test-tenant',
            'status' => 'active',
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/auth/admin/tenants/{$tenant->id}/health-checks", [
                'check_key' => 'database',
                'category' => 'infrastructure',
                'status' => 'healthy',
                'message' => 'Database connection verified.',
                'details' => ['latency_ms' => 12],
            ])
            ->assertOk()
            ->assertJsonPath('data.check_key', 'database')
            ->assertJsonPath('data.status', 'healthy');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/auth/admin/tenants/{$tenant->id}/health-checks")
            ->assertOk()
            ->assertJsonPath('data.data.0.check_key', 'database');
    }

    public function test_health_check_cannot_be_accessed_through_another_tenant(): void
    {
        $tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-health',
            'status' => 'active',
        ]);

        $tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-health',
            'status' => 'active',
        ]);

        $check = TenantHealthCheck::create([
            'tenant_id' => $tenantA->id,
            'check_key' => 'database',
            'category' => 'infrastructure',
            'status' => 'healthy',
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson(
                "/api/auth/admin/tenants/{$tenantB->id}/health-checks/{$check->id}"
            )
            ->assertNotFound();
    }

    public function test_super_admin_can_create_and_cancel_offboarding_request(): void
    {
        $tenant = Tenant::create([
            'name' => 'Offboarding Test Tenant',
            'slug' => 'offboarding-test-tenant',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/auth/admin/tenant-offboarding', [
                'tenant_id' => $tenant->id,
                'reason' => 'Business account closure requested.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $jobId = $response->json('data.id');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/auth/admin/tenant-offboarding/{$jobId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'status' => 'active',
        ]);
    }

    public function test_duplicate_active_offboarding_request_is_rejected(): void
    {
        $tenant = Tenant::create([
            'name' => 'Duplicate Test Tenant',
            'slug' => 'duplicate-test-tenant',
            'status' => 'active',
        ]);

        TenantOffboardingJob::create([
            'tenant_id' => $tenant->id,
            'requested_by' => $this->superAdmin->id,
            'status' => 'pending',
            'reason' => 'Existing request.',
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/auth/admin/tenant-offboarding', [
                'tenant_id' => $tenant->id,
                'reason' => 'Another closure request.',
            ])
            ->assertStatus(409);
    }

    public function test_guest_cannot_access_health_or_offboarding_apis(): void
    {
        $this->getJson('/api/auth/admin/tenant-offboarding')
            ->assertUnauthorized();

        $this->postJson('/api/auth/admin/tenant-offboarding', [])
            ->assertUnauthorized();
    }
}