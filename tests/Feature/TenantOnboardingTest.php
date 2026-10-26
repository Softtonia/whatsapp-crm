<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Super Admin', 'web');
        Role::findOrCreate('super_admin', 'web');

        $this->superAdmin = User::factory()->create([
            'email' => 'onboarding-admin@example.com',
            'password' => Hash::make('SuperSecret123!'),
        ]);

        $this->superAdmin->assignRole('Super Admin');
    }

    public function test_super_admin_can_view_tenant_onboarding(): void
    {
        $tenant = Tenant::create([
            'name' => 'Onboarding Test Tenant',
            'slug' => 'onboarding-test-tenant',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson(
                "/api/auth/admin/tenants/{$tenant->id}/onboarding"
            );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tenant_id', $tenant->id)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'tenant_id',
                    'onboarding_status',
                    'steps',
                ],
            ]);
    }

    public function test_guest_cannot_view_tenant_onboarding(): void
    {
        $tenant = Tenant::create([
            'name' => 'Private Tenant',
            'slug' => 'private-tenant-onboarding',
            'status' => 'active',
        ]);

        $this->getJson(
            "/api/auth/admin/tenants/{$tenant->id}/onboarding"
        )->assertUnauthorized();
    }

    public function test_completion_is_rejected_when_steps_are_pending(): void
    {
        $tenant = Tenant::create([
            'name' => 'Incomplete Tenant',
            'slug' => 'incomplete-onboarding-tenant',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson(
                "/api/auth/admin/tenants/{$tenant->id}/onboarding",
                [
                    'complete_onboarding' => true,
                ]
            );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('complete_onboarding');

        $tenant->refresh();

        $this->assertNotEquals(
            'completed',
            $tenant->onboarding_status
        );

        $this->assertNull($tenant->onboarding_completed_at);
    }
}
